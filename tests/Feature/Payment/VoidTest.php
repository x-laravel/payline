<?php

namespace XLaravel\Payline\Tests\Feature\Payment;

use LogicException;
use XLaravel\Payline\Enums\PaymentStatus;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Exceptions\InvalidPaymentOperationException;
use XLaravel\Payline\Facades\Payline;
use XLaravel\Payline\Models\Payment;
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
        $this->expectExceptionMessage('Only an authorized payment can be voided.');

        Payline::payment($payment)->void();
    }

    public function test_a_charged_payment_has_nothing_to_void(): void
    {
        Payline::via('fake')->reference('ORD-CHARGED')->amount(10000)->charge();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Payment has no authorized authorization transaction.');

        Payline::payment(Payment::firstOrFail())->void();
    }

    private function authorizedPayment(int $amount = 10000): Payment
    {
        Payline::via('fake')->reference('ORD-AUTH')->amount($amount)->authorize();

        return Payment::firstOrFail();
    }
}
