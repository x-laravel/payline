<?php

namespace XLaravel\Payline\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use XLaravel\Payline\Enums\PaymentStatus;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\StateMachine\PaymentStatusResolver;

class PaymentStatusResolverTest extends TestCase
{
    private PaymentStatusResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PaymentStatusResolver();
    }

    public function test_successful_payment_makes_the_payment_paid(): void
    {
        $this->assertSame(
            PaymentStatus::Paid,
            $this->resolver->forTransaction(TransactionType::Payment, TransactionStatus::Successful),
        );
    }

    public function test_successful_authorization_does_not_make_the_payment_paid(): void
    {
        $this->assertNull(
            $this->resolver->forTransaction(TransactionType::Authorization, TransactionStatus::Successful),
        );

        $this->assertSame(
            PaymentStatus::Authorized,
            $this->resolver->forTransaction(TransactionType::Authorization, TransactionStatus::Authorized),
        );
    }

    public function test_capture_below_the_payment_total_is_partial(): void
    {
        $this->assertSame(
            PaymentStatus::PartiallyCaptured,
            $this->resolver->forCapture(TransactionStatus::Successful, 4000, 10000),
        );
    }

    public function test_capture_reaching_the_payment_total_makes_the_payment_paid(): void
    {
        $this->assertSame(
            PaymentStatus::Paid,
            $this->resolver->forCapture(TransactionStatus::Successful, 10000, 10000),
        );
    }

    public function test_failed_capture_leaves_the_payment_status_untouched(): void
    {
        $this->assertNull($this->resolver->forCapture(TransactionStatus::Failed, 0, 10000));
    }

    public function test_unknown_capture_marks_the_payment_unknown(): void
    {
        $this->assertSame(
            PaymentStatus::Unknown,
            $this->resolver->forCapture(TransactionStatus::Unknown, 0, 10000),
        );
    }

    public function test_capture_cannot_be_resolved_without_amounts(): void
    {
        $this->expectException(LogicException::class);

        $this->resolver->forTransaction(TransactionType::Capture, TransactionStatus::Successful);
    }

    public function test_only_a_voided_void_changes_the_payment(): void
    {
        $this->assertSame(
            PaymentStatus::Voided,
            $this->resolver->forTransaction(TransactionType::Void, TransactionStatus::Voided),
        );

        $this->assertNull(
            $this->resolver->forTransaction(TransactionType::Void, TransactionStatus::Successful),
        );
    }

    public function test_unknown_propagates_for_every_operation(): void
    {
        foreach ([TransactionType::Payment, TransactionType::Authorization, TransactionType::Void] as $type) {
            $this->assertSame(
                PaymentStatus::Unknown,
                $this->resolver->forTransaction($type, TransactionStatus::Unknown),
                $type->value,
            );
        }
    }

    public function test_refund_cannot_be_resolved_without_amounts(): void
    {
        $this->expectException(LogicException::class);

        $this->resolver->forTransaction(TransactionType::Refund, TransactionStatus::Successful);
    }

    public function test_refund_below_the_payment_total_is_partial(): void
    {
        $this->assertSame(
            PaymentStatus::PartiallyRefunded,
            $this->resolver->forRefund(TransactionStatus::Successful, 4000, 10000),
        );
    }

    public function test_refund_reaching_the_payment_total_is_full(): void
    {
        $this->assertSame(
            PaymentStatus::Refunded,
            $this->resolver->forRefund(TransactionStatus::Successful, 10000, 10000),
        );
    }

    public function test_refunded_total_above_the_payment_total_is_still_full(): void
    {
        $this->assertSame(
            PaymentStatus::Refunded,
            $this->resolver->forRefund(TransactionStatus::Successful, 12000, 10000),
        );
    }

    public function test_failed_refund_leaves_the_payment_status_untouched(): void
    {
        $this->assertNull($this->resolver->forRefund(TransactionStatus::Failed, 0, 10000));
    }

    public function test_unknown_refund_marks_the_payment_unknown(): void
    {
        $this->assertSame(
            PaymentStatus::Unknown,
            $this->resolver->forRefund(TransactionStatus::Unknown, 0, 10000),
        );
    }
}
