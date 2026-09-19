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

class CaptureTest extends TestCase
{
    public function test_capture_defaults_to_the_authorized_amount_and_marks_the_payment_paid(): void
    {
        $payment = $this->authorizedPayment();

        $response = Payline::payment($payment)->capture();

        $this->assertSame(TransactionStatus::Successful, $response->status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertDatabaseHas('payline_transactions', [
            'type' => TransactionType::Capture->value,
            'status' => TransactionStatus::Successful->value,
            'amount' => 10000,
        ]);
    }

    public function test_capture_above_the_authorized_amount_is_rejected(): void
    {
        $payment = $this->authorizedPayment();

        $this->expectException(InvalidPaymentOperationException::class);
        $this->expectExceptionMessage('Capture amount exceeds the unreserved authorized amount.');

        Payline::payment($payment)->capture(amount: 15000);
    }

    public function test_a_rejected_capture_records_nothing(): void
    {
        $payment = $this->authorizedPayment();

        try {
            Payline::payment($payment)->capture(amount: 15000);
        } catch (InvalidPaymentOperationException) {
            // asserted in test_capture_above_the_authorized_amount_is_rejected
        }

        $this->assertDatabaseMissing('payline_transactions', [
            'type' => TransactionType::Capture->value,
        ]);
        $this->assertSame(PaymentStatus::Authorized, $payment->fresh()->status);
    }

    public function test_a_partial_capture_leaves_the_payment_partially_captured(): void
    {
        $payment = $this->authorizedPayment();

        Payline::payment($payment)->capture(amount: 4000);

        $this->assertSame(PaymentStatus::PartiallyCaptured, $payment->fresh()->status);
    }

    public function test_the_remainder_of_an_authorization_can_be_captured_later(): void
    {
        $payment = $this->authorizedPayment();

        Payline::payment($payment)->capture(amount: 4000);
        Payline::payment($payment)->capture(amount: 6000);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(2, $payment->transactions()->where('type', TransactionType::Capture->value)->count());
    }

    public function test_captures_cannot_exceed_the_authorized_amount_in_total(): void
    {
        $payment = $this->authorizedPayment();

        Payline::payment($payment)->capture(amount: 4000);

        try {
            Payline::payment($payment)->capture(amount: 7000);
            $this->fail('The second capture should have been rejected.');
        } catch (InvalidPaymentOperationException $exception) {
            $this->assertSame('Capture amount exceeds the unreserved authorized amount.', $exception->getMessage());
        }

        $this->assertSame(PaymentStatus::PartiallyCaptured, $payment->fresh()->status);
        $this->assertSame(1, $payment->transactions()->where('type', TransactionType::Capture->value)->count());
    }

    public function test_a_paid_payment_cannot_be_captured_again(): void
    {
        $payment = $this->authorizedPayment();

        Payline::payment($payment)->capture();

        $this->expectException(InvalidPaymentOperationException::class);
        $this->expectExceptionMessage('Only an authorized or partially captured payment can be captured.');

        Payline::payment($payment)->capture(amount: 1);
    }

    public function test_a_refund_after_a_partial_capture_is_capped_at_the_captured_amount(): void
    {
        $payment = $this->authorizedPayment();

        Payline::payment($payment)->capture(amount: 4000);

        $this->expectException(InvalidPaymentOperationException::class);
        $this->expectExceptionMessage('Refund amount exceeds the unreserved amount of the parent transaction.');

        Payline::payment($payment)->refund(amount: 5000);
    }

    public function test_a_charged_payment_has_nothing_to_capture(): void
    {
        Payline::via('fake')->reference('ORD-CHARGED')->amount(10000)->charge();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Payment has no authorized authorization transaction.');

        Payline::payment(Payment::firstOrFail())->capture();
    }

    private function authorizedPayment(int $amount = 10000): Payment
    {
        Payline::via('fake')->reference('ORD-AUTH')->amount($amount)->authorize();

        return Payment::firstOrFail();
    }
}
