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

class TransactionUpdateTest extends TestCase
{
    public function test_a_late_failure_does_not_overwrite_a_successful_transaction(): void
    {
        Payline::via('fake')->reference('ORD-001')->amount(10000)->charge();

        $transaction = Transaction::firstOrFail();

        app(TransactionUpdater::class)->apply($transaction, new PaymentResponse(
            status: TransactionStatus::Failed,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            errorMessage: 'Late decline.',
        ));

        $this->assertSame(TransactionStatus::Successful, $transaction->fresh()->status);
        $this->assertNull($transaction->fresh()->error_message);
        $this->assertSame(PaymentStatus::Paid, Payment::firstOrFail()->status);
    }

    public function test_a_confirmed_amount_below_the_requested_amount_is_recorded(): void
    {
        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Successful,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-txn-1',
            amount: 9000,
        ));

        Payline::via('fake')->reference('ORD-001')->amount(10000)->charge();

        $this->assertSame(9000, (int) Transaction::firstOrFail()->amount);
        $this->assertSame(10000, (int) Payment::firstOrFail()->amount);
    }

    public function test_a_confirmed_amount_above_the_requested_amount_is_ignored(): void
    {
        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Successful,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-txn-1',
            amount: 12000,
        ));

        Payline::via('fake')->reference('ORD-001')->amount(10000)->charge();

        $this->assertSame(10000, (int) Transaction::firstOrFail()->amount);
    }

    public function test_an_unknown_transaction_can_still_be_settled(): void
    {
        Payline::via('fake')->reference('ORD-001')->amount(10000)->charge();

        $transaction = Transaction::firstOrFail();
        $updater = app(TransactionUpdater::class);

        $unknown = Transaction::create([
            'payment_id' => $transaction->payment_id,
            'type' => TransactionType::Payment->value,
            'status' => TransactionStatus::Unknown->value,
            'amount' => 10000,
            'currency' => 'TRY',
            'attempt' => 2,
            'request_hash' => str_repeat('a', 64),
        ]);

        $updater->apply($unknown, new PaymentResponse(
            status: TransactionStatus::Successful,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-txn-2',
        ));

        $this->assertSame(TransactionStatus::Successful, $unknown->fresh()->status);
    }
}
