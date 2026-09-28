<?php

namespace XLaravel\Payline\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XLaravel\Payline\Enums\PaymentStatus;
use XLaravel\Payline\Enums\TransactionStatus;

class StatusTransitionTest extends TestCase
{
    public function test_a_settled_transaction_cannot_be_reopened(): void
    {
        foreach ([TransactionStatus::Successful, TransactionStatus::Authorized, TransactionStatus::Voided, TransactionStatus::Expired] as $final) {
            $this->assertFalse(
                $final->canTransitionTo(TransactionStatus::Failed),
                $final->value,
            );
        }

        $this->assertFalse(TransactionStatus::Failed->canTransitionTo(TransactionStatus::Successful));
    }

    public function test_a_repeated_transaction_status_is_accepted(): void
    {
        $this->assertTrue(TransactionStatus::Successful->canTransitionTo(TransactionStatus::Successful));
    }

    public function test_a_pending_transaction_settles_in_any_direction(): void
    {
        foreach ([TransactionStatus::Authorized, TransactionStatus::Successful, TransactionStatus::Failed, TransactionStatus::Expired, TransactionStatus::Voided, TransactionStatus::Unknown] as $to) {
            $this->assertTrue(TransactionStatus::Pending->canTransitionTo($to), $to->value);
        }
    }

    public function test_an_unknown_transaction_is_resolvable_by_reconciliation(): void
    {
        $this->assertTrue(TransactionStatus::Unknown->canTransitionTo(TransactionStatus::Successful));
        $this->assertTrue(TransactionStatus::Unknown->canTransitionTo(TransactionStatus::Failed));
    }

    public function test_a_paid_payment_can_only_move_towards_refunds_or_a_void(): void
    {
        $this->assertTrue(PaymentStatus::Paid->canTransitionTo(PaymentStatus::PartiallyRefunded));
        $this->assertTrue(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Refunded));
        $this->assertTrue(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Voided));

        $this->assertFalse(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Failed));
        $this->assertFalse(PaymentStatus::PartiallyRefunded->canTransitionTo(PaymentStatus::Voided));
    }

    public function test_a_partially_refunded_payment_can_complete_its_refund(): void
    {
        $this->assertTrue(PaymentStatus::PartiallyRefunded->canTransitionTo(PaymentStatus::Refunded));
        $this->assertFalse(PaymentStatus::PartiallyRefunded->canTransitionTo(PaymentStatus::Paid));
    }

    public function test_a_refunded_or_voided_payment_is_closed(): void
    {
        foreach (PaymentStatus::cases() as $to) {
            $this->assertFalse(PaymentStatus::Refunded->canTransitionTo($to), $to->value);
            $this->assertFalse(PaymentStatus::Voided->canTransitionTo($to), $to->value);
        }
    }

    public function test_an_authorized_payment_is_captured_or_voided(): void
    {
        $this->assertTrue(PaymentStatus::Authorized->canTransitionTo(PaymentStatus::Paid));
        $this->assertTrue(PaymentStatus::Authorized->canTransitionTo(PaymentStatus::PartiallyCaptured));
        $this->assertTrue(PaymentStatus::Authorized->canTransitionTo(PaymentStatus::Voided));

        $this->assertFalse(PaymentStatus::Authorized->canTransitionTo(PaymentStatus::Refunded));
        $this->assertFalse(PaymentStatus::Authorized->canTransitionTo(PaymentStatus::Failed));
    }

    public function test_a_partially_captured_payment_can_complete_its_capture_or_be_refunded(): void
    {
        $this->assertTrue(PaymentStatus::PartiallyCaptured->canTransitionTo(PaymentStatus::Paid));
        $this->assertTrue(PaymentStatus::PartiallyCaptured->canTransitionTo(PaymentStatus::PartiallyRefunded));

        $this->assertFalse(PaymentStatus::PartiallyCaptured->canTransitionTo(PaymentStatus::Authorized));
        $this->assertFalse(PaymentStatus::PartiallyCaptured->canTransitionTo(PaymentStatus::Failed));
    }

    public function test_a_partially_captured_payment_still_has_an_outstanding_amount(): void
    {
        $this->assertTrue(PaymentStatus::PartiallyCaptured->hasOutstandingAmount());
        $this->assertFalse(PaymentStatus::PartiallyCaptured->isFinal());
    }

    public function test_a_failed_payment_can_be_retried(): void
    {
        $this->assertTrue(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Paid));
        $this->assertTrue(PaymentStatus::Expired->canTransitionTo(PaymentStatus::Pending));
    }
}
