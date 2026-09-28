<?php

namespace XLaravel\Payline\Tests\Feature\Payment;

use LogicException;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\Enums\PaymentStatus;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Exceptions\InvalidPaymentOperationException;
use XLaravel\Payline\Facades\Payline;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Tests\Fixtures\Gateways\FakeGateway;
use XLaravel\Payline\Tests\Fixtures\Models\Order;
use XLaravel\Payline\Tests\TestCase;

class VoidTest extends TestCase
{
    public function test_void_releases_an_authorized_payment(): void
    {
        $payment = $this->authorizedPayment();

        $response = Payline::payment($payment)->void();

        $this->assertSame(TransactionStatus::Voided, $response->status);
        $this->assertSame(PaymentStatus::Voided, $payment->fresh()->status);
        $this->assertDatabaseHas('payline_transactions', [
            'type' => TransactionType::Void->value,
            'status' => TransactionStatus::Voided->value,
            'amount' => 10000,
        ]);
    }

    public function test_void_after_capture_is_rejected(): void
    {
        $payment = $this->authorizedPayment();

        Payline::payment($payment)->capture();

        $this->expectException(InvalidPaymentOperationException::class);
        $this->expectExceptionMessage('Only an authorized payment or an unrefunded sale can be voided.');

        Payline::payment($payment)->void();
    }

    public function test_void_cancels_a_sale(): void
    {
        $payment = $this->chargedPayment();

        $response = Payline::payment($payment)->void();

        $this->assertSame(TransactionStatus::Voided, $response->status);
        $this->assertSame(PaymentStatus::Voided, $payment->fresh()->status);
        $this->assertDatabaseHas('payline_transactions', [
            'type' => TransactionType::Void->value,
            'status' => TransactionStatus::Voided->value,
            'amount' => 10000,
        ]);
    }

    public function test_a_voided_sale_cannot_be_refunded(): void
    {
        $payment = $this->chargedPayment();

        Payline::payment($payment)->void();

        $this->expectException(InvalidPaymentOperationException::class);

        Payline::payment($payment)->refund(amount: 10000);
    }

    public function test_a_partly_refunded_sale_cannot_be_voided(): void
    {
        $payment = $this->chargedPayment();

        Payline::payment($payment)->refund(amount: 2500);

        $this->expectException(InvalidPaymentOperationException::class);

        Payline::payment($payment)->void();
    }

    public function test_a_failed_refund_does_not_block_voiding_a_sale(): void
    {
        $payment = $this->chargedPayment();

        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Failed,
            type: TransactionType::Refund,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-refund-1',
        ));

        Payline::payment($payment)->refund(amount: 2500);

        Payline::payment($payment)->void();

        $this->assertSame(PaymentStatus::Voided, $payment->fresh()->status);
    }

    public function test_a_failed_charge_has_nothing_to_void(): void
    {
        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Failed,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-failed-1',
        ));

        Payline::via('fake')->reference('ORD-FAILED')->amount(10000)->charge();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Payment has no authorization or sale to void.');

        Payline::payment(Payment::firstOrFail())->void();
    }

    public function test_a_voided_sale_reports_nothing_collected(): void
    {
        $payment = $this->chargedPayment();

        $this->assertSame(10000, $payment->capturedAmount());
        $this->assertSame(10000, $payment->remainingRefundable());

        Payline::payment($payment)->void();

        $this->assertSame(0, $payment->fresh()->capturedAmount());
        $this->assertSame(0, $payment->fresh()->remainingRefundable());
    }

    public function test_voiding_a_sale_leaves_the_payable_reporting_nothing(): void
    {
        $order = Order::create(['reference' => 'ORD-VOID', 'amount' => 10000, 'currency' => 'TRY']);

        Payline::for($order)->via('fake')->charge();

        $this->assertSame(10000, $order->amountPaid());

        Payline::payment(Payment::firstOrFail())->void();

        $this->assertSame(0, $order->amountPaid());
        $this->assertSame(0, $order->amountNet());
    }

    private function chargedPayment(int $amount = 10000): Payment
    {
        Payline::via('fake')->reference('ORD-CHARGED')->amount($amount)->charge();

        return Payment::firstOrFail();
    }

    private function authorizedPayment(int $amount = 10000): Payment
    {
        Payline::via('fake')->reference('ORD-AUTH')->amount($amount)->authorize();

        return Payment::firstOrFail();
    }
}
