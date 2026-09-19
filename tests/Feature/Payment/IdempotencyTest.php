<?php

namespace XLaravel\Payline\Tests\Feature\Payment;

use XLaravel\Payline\Enums\PaymentStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Exceptions\IdempotencyConflictException;
use XLaravel\Payline\Facades\Payline;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;
use XLaravel\Payline\Tests\TestCase;

class IdempotencyTest extends TestCase
{
    public function test_repeating_a_charge_with_the_same_key_charges_once(): void
    {
        $first = $this->charge(10000, 'order:1:payment');
        $second = $this->charge(10000, 'order:1:payment');

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, Transaction::query()->where('type', TransactionType::Payment->value)->count());
        $this->assertSame($first->gatewayTransactionId, $second->gatewayTransactionId);
    }

    public function test_reusing_a_charge_key_with_a_different_amount_is_a_conflict(): void
    {
        $this->charge(10000, 'order:1:payment');

        $this->expectException(IdempotencyConflictException::class);

        $this->charge(20000, 'order:1:payment');
    }

    public function test_repeating_a_refund_with_the_same_key_refunds_once(): void
    {
        $this->charge(10000, 'order:1:payment');
        $payment = Payment::firstOrFail();

        Payline::payment($payment)->refund(amount: 4000, idempotencyKey: 'order:1:refund:1');
        Payline::payment($payment)->refund(amount: 4000, idempotencyKey: 'order:1:refund:1');

        $payment->refresh();
        $this->assertSame(1, $payment->refunds()->count());
        $this->assertSame(4000, $payment->totalRefunded());
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->status);
    }

    public function test_reusing_a_refund_key_with_a_different_amount_is_a_conflict(): void
    {
        $this->charge(10000, 'order:1:payment');
        $payment = Payment::firstOrFail();

        Payline::payment($payment)->refund(amount: 4000, idempotencyKey: 'order:1:refund:1');

        $this->expectException(IdempotencyConflictException::class);

        Payline::payment($payment)->refund(amount: 5000, idempotencyKey: 'order:1:refund:1');
    }

    public function test_a_conflicting_refund_key_records_nothing(): void
    {
        $this->charge(10000, 'order:1:payment');
        $payment = Payment::firstOrFail();

        Payline::payment($payment)->refund(amount: 4000, idempotencyKey: 'order:1:refund:1');

        try {
            Payline::payment($payment)->refund(amount: 5000, idempotencyKey: 'order:1:refund:1');
        } catch (IdempotencyConflictException) {
            // asserted in test_reusing_a_refund_key_with_a_different_amount_is_a_conflict
        }

        $payment->refresh();
        $this->assertSame(1, $payment->refunds()->count());
        $this->assertSame(4000, $payment->totalRefunded());
    }

    public function test_separate_keys_produce_separate_refunds(): void
    {
        $this->charge(10000, 'order:1:payment');
        $payment = Payment::firstOrFail();

        Payline::payment($payment)->refund(amount: 4000, idempotencyKey: 'order:1:refund:1');
        Payline::payment($payment)->refund(amount: 4000, idempotencyKey: 'order:1:refund:2');

        $payment->refresh();
        $this->assertSame(2, $payment->refunds()->count());
        $this->assertSame(8000, $payment->totalRefunded());
    }

    private function charge(int $amount, string $key)
    {
        return Payline::via('fake')
            ->reference('ORD-001')
            ->amount($amount)
            ->idempotencyKey($key)
            ->charge();
    }
}
