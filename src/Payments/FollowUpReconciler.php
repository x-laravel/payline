<?php

namespace XLaravel\Payline\Payments;

use Illuminate\Support\Collection;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;
use XLaravel\Payline\StateMachine\PaymentStatusResolver;

class FollowUpReconciler
{
    public function __construct(
        private readonly TransactionRunner $runner,
        private readonly PaymentStatusResolver $statuses,
    ) {}

    public function settle(Payment $payment, PaymentResponse $order): void
    {
        $this->settleRefunds($payment, $order);
        $this->settleVoids($payment, $order);
        $this->resyncRefundState($payment->refresh());
    }

    private function resyncRefundState(Payment $payment): void
    {
        $refunded = (int) $payment->refunds()
            ->where('status', TransactionStatus::Successful->value)
            ->sum('amount');

        if ($refunded === 0) {
            return;
        }

        $target = $this->statuses->forRefund(TransactionStatus::Successful, $refunded, (int) $payment->amount);

        if ($target === null || $payment->status === $target || ! $payment->status->canTransitionTo($target)) {
            return;
        }

        $payment->update(['status' => $target->value]);
    }

    private function settleRefunds(Payment $payment, PaymentResponse $order): void
    {
        if ($order->refundedAmount === null) {
            return;
        }

        $accepted = (int) $payment->refunds()
            ->where('status', TransactionStatus::Successful->value)
            ->sum('amount');

        foreach ($this->openTransactions($payment, TransactionType::Refund) as $refund) {
            $accountedFor = $accepted + (int) $refund->amount <= $order->refundedAmount;

            $this->record($refund, $order, $accountedFor
                ? TransactionStatus::Successful
                : TransactionStatus::Failed);

            if ($accountedFor) {
                $accepted += (int) $refund->amount;
            }
        }
    }

    private function settleVoids(Payment $payment, PaymentResponse $order): void
    {
        if ($order->voided === null) {
            return;
        }

        foreach ($this->openTransactions($payment, TransactionType::Void) as $void) {
            $this->record($void, $order, $order->voided
                ? TransactionStatus::Voided
                : TransactionStatus::Failed);
        }
    }

    /** @return Collection<int, Transaction> */
    private function openTransactions(Payment $payment, TransactionType $type): Collection
    {
        return $payment->transactions()
            ->where('type', $type->value)
            ->whereIn('status', [
                TransactionStatus::Pending->value,
                TransactionStatus::Unknown->value,
            ])
            ->oldest('created_at')
            ->get();
    }

    private function record(Transaction $transaction, PaymentResponse $order, TransactionStatus $status): void
    {
        $this->runner->apply($transaction, new PaymentResponse(
            status: $status,
            type: $transaction->type,
            gatewayName: $order->gatewayName,
            gatewayTransactionId: $transaction->gateway_transaction_id,
            amount: (int) $transaction->amount,
            currency: $transaction->currency,
            errorMessage: $status === TransactionStatus::Failed
                ? 'The provider does not report this operation on the order.'
                : null,
        ));
    }
}
