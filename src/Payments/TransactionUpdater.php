<?php

namespace XLaravel\Payline\Payments;

use DateTimeInterface;
use XLaravel\Payline\Concerns\InteractsWithPaylineStorage;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\Enums\PaymentStatus;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;
use XLaravel\Payline\StateMachine\PaymentStatusResolver;

class TransactionUpdater
{
    use InteractsWithPaylineStorage;

    public function __construct(
        private readonly PaymentStatusResolver $statuses,
    ) {}

    public function apply(Transaction $transaction, PaymentResponse $response): Transaction
    {
        return $this->connection()->transaction(function () use ($transaction, $response) {
            $transactionModel = $this->transactionModel();
            $paymentModel = $this->paymentModel();

            $lockedTransaction = $transactionModel::query()
                ->lockForUpdate()
                ->findOrFail($transaction->getKey());

            $status = $this->settledStatus($lockedTransaction, $response);

            if (! $lockedTransaction->status->canTransitionTo($status)) {
                return $lockedTransaction;
            }

            $payment = $paymentModel::query()
                ->lockForUpdate()
                ->findOrFail($lockedTransaction->payment_id);

            $lockedTransaction->fill([
                'status' => $status->value,
                'amount' => $this->confirmedAmount($lockedTransaction, $response),
                'gateway_transaction_id' => $response->gatewayTransactionId ?? $lockedTransaction->gateway_transaction_id,
                'gateway_order_id' => $response->gatewayOrderId ?? $lockedTransaction->gateway_order_id,
                'gateway_auth_code' => $response->gatewayAuthCode ?? $lockedTransaction->gateway_auth_code,
                'gateway_response_code' => $response->gatewayResponseCode ?? $lockedTransaction->gateway_response_code,
                'gateway_response_message' => $response->gatewayResponseMessage ?? $lockedTransaction->gateway_response_message,
                'error_code' => $response->errorCode,
                'error_message' => $response->errorMessage,
                'redirect_url' => $response->redirectUrl ?? $lockedTransaction->redirect_url,
                'metadata' => $this->mergedMetadata($lockedTransaction, $response),
                'expires_at' => $response->expiresAt ?? $lockedTransaction->expires_at,
                'completed_at' => $status->isFinal()
                    ? ($lockedTransaction->completed_at ?? now())
                    : null,
            ]);
            $lockedTransaction->save();

            $this->syncPaymentStatus($payment, $lockedTransaction);

            return $lockedTransaction;
        });
    }

    public function markFailed(Transaction $transaction, string $message): Transaction
    {
        return $this->mark($transaction, TransactionStatus::Failed, $message);
    }

    public function markUnknown(Transaction $transaction, string $message): Transaction
    {
        return $this->mark($transaction, TransactionStatus::Unknown, $message);
    }

    private function mark(Transaction $transaction, TransactionStatus $status, string $message): Transaction
    {
        return $this->connection()->transaction(function () use ($transaction, $status, $message) {
            $transactionModel = $this->transactionModel();
            $paymentModel = $this->paymentModel();

            $lockedTransaction = $transactionModel::query()
                ->lockForUpdate()
                ->findOrFail($transaction->getKey());

            if ($lockedTransaction->status->isFinal()) {
                return $lockedTransaction;
            }

            $lockedTransaction->update([
                'status' => $status->value,
                'error_message' => $message,
                'completed_at' => $status->isFinal() ? now() : null,
            ]);

            $payment = $paymentModel::query()
                ->lockForUpdate()
                ->findOrFail($lockedTransaction->payment_id);

            $this->syncPaymentStatus($payment, $lockedTransaction);

            return $lockedTransaction;
        });
    }

    private function syncPaymentStatus(Payment $payment, Transaction $transaction): void
    {
        $incoming = $this->paymentStatusFor($payment, $transaction);

        if ($incoming === null || $incoming === $payment->status || ! $payment->status->canTransitionTo($incoming)) {
            return;
        }

        $updates = ['status' => $incoming->value];

        if ($incoming->isFinal() && $payment->completed_at === null) {
            $updates['completed_at'] = now();
        }

        $payment->update($updates);
    }

    private function paymentStatusFor(Payment $payment, Transaction $transaction): ?PaymentStatus
    {
        return match ($transaction->type) {
            TransactionType::Refund => $this->statuses->forRefund(
                $transaction->status,
                $this->refundedTotal($payment),
                (int) $payment->amount,
            ),
            TransactionType::Capture => $this->statuses->forCapture(
                $transaction->status,
                $this->capturedTotal($payment),
                (int) $payment->amount,
            ),
            default => $this->statuses->forTransaction($transaction->type, $transaction->status),
        };
    }

    private function refundedTotal(Payment $payment): int
    {
        return (int) $payment->refunds()
            ->where('status', TransactionStatus::Successful->value)
            ->sum('amount');
    }

    private function capturedTotal(Payment $payment): int
    {
        return (int) $payment->transactions()
            ->where('type', TransactionType::Capture->value)
            ->where('status', TransactionStatus::Successful->value)
            ->sum('amount');
    }

    private function settledStatus(Transaction $transaction, PaymentResponse $response): TransactionStatus
    {
        if ($response->status !== TransactionStatus::Pending) {
            return $response->status;
        }

        $deadline = $response->expiresAt ?? $transaction->expires_at ?? $this->defaultDeadline($transaction);

        return $deadline !== null && now()->greaterThan($deadline)
            ? TransactionStatus::Expired
            : TransactionStatus::Pending;
    }

    private function defaultDeadline(Transaction $transaction): ?DateTimeInterface
    {
        $minutes = config('payline.transactions.pending_ttl');

        return $minutes === null
            ? null
            : $transaction->created_at->copy()->addMinutes((int) $minutes);
    }

    private function mergedMetadata(Transaction $transaction, PaymentResponse $response): ?array
    {
        if ($response->metadata === null) {
            return $transaction->metadata;
        }

        return array_merge($transaction->metadata ?? [], $response->metadata);
    }

    private function confirmedAmount(Transaction $transaction, PaymentResponse $response): int
    {
        return $response->amount > 0 && $response->amount <= (int) $transaction->amount
            ? $response->amount
            : (int) $transaction->amount;
    }
}
