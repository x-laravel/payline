<?php

namespace XLaravel\Payline\Tests\Feature\Payment;

use Illuminate\Support\Facades\Event;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\Enums\PaymentStatus;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Events\PaymentFailed;
use XLaravel\Payline\Events\PaymentRefunded;
use XLaravel\Payline\Facades\Payline;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;
use XLaravel\Payline\Tests\Fixtures\Gateways\FakeGateway;
use XLaravel\Payline\Tests\TestCase;

class ReconcileTest extends TestCase
{
    public function test_reconciliation_asks_about_the_order_rather_than_the_last_follow_up(): void
    {
        $payment = $this->paymentWithUnknownRefund(4000);

        $this->orderAnswers(refundedAmount: 4000);

        $response = Payline::payment($payment)->reconcile();

        $this->assertSame(TransactionType::Payment, $response->type);
    }

    public function test_an_unknown_refund_the_order_confirms_is_settled(): void
    {
        $payment = $this->paymentWithUnknownRefund(4000);

        $this->orderAnswers(refundedAmount: 4000);

        Payline::payment($payment)->reconcile();

        $this->assertSame(TransactionStatus::Successful, $this->refund()->status);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->fresh()->status);
        $this->assertSame(4000, $payment->fresh()->totalRefunded());
    }

    public function test_an_unknown_refund_the_order_does_not_report_is_failed(): void
    {
        $payment = $this->paymentWithUnknownRefund(4000);

        $this->orderAnswers(refundedAmount: 0);

        Payline::payment($payment)->reconcile();

        $this->assertSame(TransactionStatus::Failed, $this->refund()->status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_a_payment_freed_from_an_unknown_refund_can_be_refunded_again(): void
    {
        $payment = $this->paymentWithUnknownRefund(4000);

        $this->orderAnswers(refundedAmount: 0);
        Payline::payment($payment)->reconcile();

        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Successful,
            type: TransactionType::Refund,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-sale-1',
        ));

        Payline::payment($payment->fresh())->refund(amount: 4000, idempotencyKey: 'retry-1');

        $this->assertSame(4000, $payment->fresh()->totalRefunded());
    }

    public function test_a_refund_beyond_what_the_order_reports_is_failed(): void
    {
        $payment = $this->paidPayment();

        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Successful,
            type: TransactionType::Refund,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-sale-1',
        ));
        Payline::payment($payment)->refund(amount: 4000, idempotencyKey: 'first');

        $this->addUnknownRefund($payment, 3000, 'second');

        $this->orderAnswers(refundedAmount: 4000);

        Payline::payment($payment->fresh())->reconcile();

        $refunds = $payment->fresh()->refunds()->oldest('created_at')->get();

        $this->assertSame(TransactionStatus::Successful, $refunds[0]->status);
        $this->assertSame(TransactionStatus::Failed, $refunds[1]->status);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->fresh()->status);
    }

    public function test_a_settled_refund_keeps_its_own_provider_reference(): void
    {
        $payment = $this->paidPayment();

        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Unknown,
            type: TransactionType::Refund,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-refund-9',
        ));
        Payline::payment($payment)->refund(amount: 4000, idempotencyKey: 'first');

        $this->orderAnswers(refundedAmount: 4000);

        Payline::payment($payment->fresh())->reconcile();

        $this->assertSame('fake-refund-9', $this->refund()->gateway_transaction_id);
    }

    public function test_a_refund_still_on_its_way_to_the_provider_is_left_alone(): void
    {
        $payment = $this->paidPayment();

        $refund = Transaction::create([
            'payment_id' => $payment->getKey(),
            'type' => TransactionType::Refund->value,
            'status' => TransactionStatus::Initiated->value,
            'amount' => 4000,
            'currency' => 'TRY',
            'attempt' => 1,
            'request_hash' => str_repeat('b', 64),
        ]);

        $this->orderAnswers(refundedAmount: 0);

        Payline::payment($payment->fresh())->reconcile();

        $this->assertSame(TransactionStatus::Initiated, $refund->fresh()->status);
    }

    public function test_a_settled_refund_dispatches_its_event(): void
    {
        $payment = $this->paymentWithUnknownRefund(4000);

        $this->orderAnswers(refundedAmount: 4000);

        Event::fake([PaymentRefunded::class]);

        Payline::payment($payment)->reconcile();

        Event::assertDispatched(PaymentRefunded::class);
    }

    public function test_an_unknown_void_the_order_confirms_is_settled(): void
    {
        $payment = $this->paymentWithUnknownVoid();

        $this->orderAnswers(refundedAmount: 0, voided: true);

        Payline::payment($payment)->reconcile();

        $this->assertSame(PaymentStatus::Voided, $payment->fresh()->status);
    }

    public function test_an_order_the_gateway_cannot_describe_leaves_the_follow_up_open(): void
    {
        $payment = $this->paymentWithUnknownRefund(4000);

        $this->orderAnswers(refundedAmount: null, voided: null, status: TransactionStatus::Unknown);

        Payline::payment($payment)->reconcile();

        $this->assertSame(TransactionStatus::Unknown, $this->refund()->status);
    }

    public function test_an_expired_transaction_reports_a_failure(): void
    {
        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Pending,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-sale-1',
            redirectUrl: 'https://acs.test/3ds',
            expiresAt: now()->addMinutes(30),
        ));

        Payline::via('fake')->reference('ORD-3DS')->amount(10000)->charge();
        $payment = Payment::firstOrFail();

        $this->travelTo(now()->addMinutes(31));
        $this->orderAnswers(refundedAmount: null, voided: null, status: TransactionStatus::Pending);

        Event::fake([PaymentFailed::class]);

        Payline::payment($payment)->reconcile();

        Event::assertDispatched(PaymentFailed::class);
        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
    }

    public function test_a_late_settlement_overrides_an_expiry(): void
    {
        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Pending,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-sale-1',
            redirectUrl: 'https://acs.test/3ds',
            expiresAt: now()->addMinutes(30),
        ));

        Payline::via('fake')->reference('ORD-3DS')->amount(10000)->charge();
        $payment = Payment::firstOrFail();

        $this->travelTo(now()->addMinutes(31));
        $this->orderAnswers(refundedAmount: null, voided: null, status: TransactionStatus::Pending);
        Payline::payment($payment)->reconcile();

        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);

        $this->orderAnswers(refundedAmount: 0);
        Payline::payment($payment->fresh())->reconcile();

        $this->assertSame(TransactionStatus::Successful, Transaction::firstOrFail()->status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    private function orderAnswers(
        ?int $refundedAmount,
        ?bool $voided = false,
        TransactionStatus $status = TransactionStatus::Successful,
    ): void {
        FakeGateway::willAnswerQuery(new PaymentResponse(
            status: $status,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-sale-1',
            refundedAmount: $refundedAmount,
            voided: $voided,
        ));
    }

    private function paymentWithUnknownRefund(int $amount): Payment
    {
        $payment = $this->paidPayment();

        $this->addUnknownRefund($payment, $amount, 'first');

        return $payment->fresh();
    }

    private function paymentWithUnknownVoid(): Payment
    {
        $payment = $this->paidPayment();

        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Unknown,
            type: TransactionType::Void,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-sale-1',
        ));

        Payline::payment($payment)->void();

        return $payment->fresh();
    }

    private function addUnknownRefund(Payment $payment, int $amount, string $key): void
    {
        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Unknown,
            type: TransactionType::Refund,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-sale-1',
        ));

        Payline::payment($payment->fresh())->refund(amount: $amount, idempotencyKey: $key);
    }

    private function refund(): Transaction
    {
        return Transaction::query()
            ->where('type', TransactionType::Refund->value)
            ->oldest('created_at')
            ->firstOrFail();
    }

    private function paidPayment(): Payment
    {
        FakeGateway::willReturn(new PaymentResponse(
            status: TransactionStatus::Successful,
            type: TransactionType::Payment,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-sale-1',
        ));

        Payline::via('fake')->reference('ORD-PAID')->amount(10000)->charge();

        return Payment::firstOrFail();
    }
}
