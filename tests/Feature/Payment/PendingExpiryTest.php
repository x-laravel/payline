<?php

namespace XLaravel\Payline\Tests\Feature\Payment;

use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\Enums\PaymentStatus;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Facades\Payline;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;
use XLaravel\Payline\Payments\TransactionUpdater;
use XLaravel\Payline\Tests\Fixtures\Gateways\FakeGateway;
use XLaravel\Payline\Tests\TestCase;

class PendingExpiryTest extends TestCase
{
    public function test_a_pending_answer_past_the_gateway_deadline_expires_the_payment(): void
    {
        $transaction = $this->pendingCharge(now()->addMinutes(30));

        $this->travelTo(now()->addMinutes(31));
        $this->answerStillPending($transaction);

        $this->assertSame(TransactionStatus::Expired, $transaction->fresh()->status);
        $this->assertNotNull($transaction->fresh()->completed_at);
        $this->assertSame(PaymentStatus::Expired, Payment::firstOrFail()->status);
    }

    public function test_a_pending_answer_before_the_deadline_leaves_the_payment_waiting(): void
    {
        $transaction = $this->pendingCharge(now()->addMinutes(30));

        $this->travelTo(now()->addMinutes(29));
        $this->answerStillPending($transaction);

        $this->assertSame(TransactionStatus::Pending, $transaction->fresh()->status);
        $this->assertNull($transaction->fresh()->completed_at);
        $this->assertSame(PaymentStatus::Pending, Payment::firstOrFail()->status);
    }

    public function test_a_gateway_without_a_deadline_falls_back_to_the_configured_ttl(): void
    {
        config(['payline.transactions.pending_ttl' => 45]);

        $transaction = $this->pendingCharge();

        $this->travelTo(now()->addMinutes(46));
        $this->answerStillPending($transaction);

        $this->assertSame(TransactionStatus::Expired, $transaction->fresh()->status);
    }

    public function test_the_configured_ttl_can_be_turned_off(): void
    {
        config(['payline.transactions.pending_ttl' => null]);

        $transaction = $this->pendingCharge();

        $this->travelTo(now()->addYear());
        $this->answerStillPending($transaction);

        $this->assertSame(TransactionStatus::Pending, $transaction->fresh()->status);
    }

    public function test_a_settled_answer_past_the_deadline_still_wins(): void
    {
        $transaction = $this->pendingCharge(now()->addMinutes(30));

        $this->travelTo(now()->addMinutes(31));

        app(TransactionUpdater::class)->apply($transaction, new PaymentResponse(
            status: TransactionStatus::Successful,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-txn-1',
        ));

        $this->assertSame(TransactionStatus::Successful, $transaction->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, Payment::firstOrFail()->status);
    }

    private function pendingCharge(?object $expiresAt = null): Transaction
    {
        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Pending,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-txn-1',
            redirectUrl: 'https://acs.test/3ds',
            expiresAt: $expiresAt,
        ));

        Payline::via('fake')->reference('ORD-001')->amount(10000)->charge();

        return Transaction::firstOrFail();
    }

    private function answerStillPending(Transaction $transaction): void
    {
        app(TransactionUpdater::class)->apply($transaction, new PaymentResponse(
            status: TransactionStatus::Pending,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-txn-1',
        ));
    }
}
