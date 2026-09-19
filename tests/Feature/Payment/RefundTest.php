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
use XLaravel\Payline\Tests\TestCase;

class RefundTest extends TestCase
{
    public function test_a_partial_refund_marks_the_payment_partially_refunded(): void
    {
        $payment = $this->paidPayment();

        Payline::payment($payment)->refund(amount: 4000);

        $payment->refresh();
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);
        $this->assertSame(4000, $payment->totalRefunded());
        $this->assertSame(6000, $payment->remainingRefundable());
    }

    public function test_refunds_reaching_the_payment_total_mark_it_refunded(): void
    {
        $payment = $this->paidPayment();

        Payline::payment($payment)->refund(amount: 4000);
        Payline::payment($payment)->refund(amount: 6000);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(10000, $payment->totalRefunded());
        $this->assertSame(0, $payment->remainingRefundable());
    }

    public function test_refund_above_the_payment_amount_is_rejected(): void
    {
        $payment = $this->paidPayment();

        $this->expectException(InvalidPaymentOperationException::class);
        $this->expectExceptionMessage('Refund amount exceeds the unreserved amount of the parent transaction.');

        Payline::payment($payment)->refund(amount: 15000);
    }

    public function test_refunds_cannot_exceed_the_payment_amount_in_total(): void
    {
        $payment = $this->paidPayment();

        Payline::payment($payment)->refund(amount: 4000);

        try {
            Payline::payment($payment)->refund(amount: 7000);
            $this->fail('The second refund should have been rejected.');
        } catch (InvalidPaymentOperationException) {
            // asserted below through the recorded state
        }

        $payment->refresh();
        $this->assertSame(4000, $payment->totalRefunded());
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);
        $this->assertSame(1, $payment->refunds()->count());
    }

    public function test_a_fully_refunded_payment_cannot_be_refunded_again(): void
    {
        $payment = $this->paidPayment();

        Payline::payment($payment)->refund(amount: 10000);

        $this->expectException(InvalidPaymentOperationException::class);
        $this->expectExceptionMessage('Only a paid payment can be refunded.');

        Payline::payment($payment)->refund(amount: 1);
    }

    public function test_an_authorized_payment_has_nothing_to_refund(): void
    {
        Payline::via('fake')->reference('ORD-AUTH')->amount(10000)->authorize();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Payment has no successful transaction to refund.');

        Payline::payment(Payment::firstOrFail())->refund(amount: 1000);
    }

    public function test_a_failed_refund_does_not_reduce_the_refundable_amount(): void
    {
        $payment = $this->paidPayment();

        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Failed,
            type: TransactionType::Refund,
            gatewayName: 'fake',
            errorMessage: 'Refund declined by the issuer.',
        ));

        Payline::payment($payment)->refund(amount: 4000);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(0, $payment->totalRefunded());
        $this->assertDatabaseHas('payline_transactions', [
            'type' => TransactionType::Refund->value,
            'status' => TransactionStatus::Failed->value,
        ]);
    }

    private function paidPayment(int $amount = 10000): Payment
    {
        Payline::via('fake')->reference('ORD-PAID')->amount($amount)->charge();

        return Payment::firstOrFail();
    }
}
