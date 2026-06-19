<?php

namespace XLaravel\Payline;

use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\DTOs\CaptureData;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;

class TransactionRecorder
{
    private string $paymentModel;
    private string $transactionModel;

    public function __construct()
    {
        $this->paymentModel = config('payline.payment_model', Payment::class);
        $this->transactionModel = config('payline.transaction_model', Transaction::class);
    }

    public function createPayment(
        string $gateway,
        PaymentRequest $data,
        ?Payable $payable = null,
        ?object $owner = null,
    ): Payment {
        /** @var class-string<Payment> $model */
        $model = $this->paymentModel;

        return $model::create([
            'gateway' => $gateway,
            'status' => TransactionStatus::Initiated->value,
            'amount' => $data->amount,
            'currency' => $data->currency,
            'payable_type' => $payable ? get_class($payable) : null,
            'payable_id' => $payable ? $payable->getKey() : null,
            'owner_type' => $owner ? get_class($owner) : null,
            'owner_id' => $owner ? $owner->getKey() : null,
            'reference' => $data->reference,
            'description' => $data->description,
            'metadata' => $data->metadata,
        ]);
    }

    public function createTransaction(
        Payment $payment,
        TransactionType $type,
        PaymentRequest $data,
        int $attempt = 1,
    ): Transaction {
        /** @var class-string<Transaction> $model */
        $model = $this->transactionModel;

        return $model::create([
            'payment_id' => $payment->id,
            'type' => $type->value,
            'status' => TransactionStatus::Initiated->value,
            'amount' => $data->amount,
            'currency' => $data->currency,
            'attempt' => $attempt,
            'metadata' => $data->metadata,
        ]);
    }

    public function createCaptureTransaction(
        Payment $payment,
        CaptureData $data,
        Transaction $parent,
    ): Transaction {
        /** @var class-string<Transaction> $model */
        $model = $this->transactionModel;

        return $model::create([
            'payment_id' => $payment->id,
            'type' => TransactionType::Capture->value,
            'status' => TransactionStatus::Initiated->value,
            'amount' => $data->amount,
            'currency' => $data->currency,
            'attempt' => 1,
            'gateway_transaction_id' => $data->gatewayTransactionId,
            'parent_transaction_id' => $parent->id,
            'metadata' => $data->metadata,
        ]);
    }

    public function createRefundTransaction(
        Payment $payment,
        RefundData $data,
        Transaction $parent,
    ): Transaction {
        /** @var class-string<Transaction> $model */
        $model = $this->transactionModel;

        return $model::create([
            'payment_id' => $payment->id,
            'type' => TransactionType::Refund->value,
            'status' => TransactionStatus::Initiated->value,
            'amount' => $data->amount,
            'currency' => $data->currency,
            'attempt' => 1,
            'gateway_transaction_id' => $data->gatewayTransactionId,
            'parent_transaction_id' => $parent->id,
            'metadata' => $data->metadata,
        ]);
    }

    public function createVoidTransaction(
        Payment $payment,
        VoidData $data,
        Transaction $parent,
    ): Transaction {
        /** @var class-string<Transaction> $model */
        $model = $this->transactionModel;

        return $model::create([
            'payment_id' => $payment->id,
            'type' => TransactionType::Void->value,
            'status' => TransactionStatus::Initiated->value,
            'amount' => $parent->amount,
            'currency' => $parent->currency,
            'attempt' => 1,
            'gateway_transaction_id' => $data->gatewayTransactionId,
            'parent_transaction_id' => $parent->id,
            'metadata' => $data->metadata,
        ]);
    }

    public function updateTransaction(Transaction $tx, PaymentResponse $response): Transaction
    {
        $tx->fill([
            'status' => $response->status->value,
            'gateway_transaction_id' => $response->gatewayTransactionId,
            'gateway_order_id' => $response->gatewayOrderId,
            'gateway_auth_code' => $response->gatewayAuthCode,
            'gateway_response_code' => $response->gatewayResponseCode,
            'gateway_response_message' => $response->gatewayResponseMessage,
            'error_code' => $response->errorCode,
            'error_message' => $response->errorMessage,
            'redirect_url' => $response->redirectUrl,
            'completed_at' => $response->status->isFinal() ? now() : null,
        ])->save();

        $this->syncPaymentStatus($tx->payment_id, $response->status);

        return $tx;
    }

    public function failTransaction(Transaction $tx, string $message): void
    {
        $tx->update([
            'status' => TransactionStatus::Failed->value,
            'error_message' => $message,
            'completed_at' => now(),
        ]);

        $this->syncPaymentStatus($tx->payment_id, TransactionStatus::Failed);
    }

    public function findTransactionByGatewayTransactionId(string $id): ?Transaction
    {
        /** @var class-string<Transaction> $model */
        $model = $this->transactionModel;

        return $model::where('gateway_transaction_id', $id)->latest()->first();
    }

    public function findTransactionByGatewayOrderId(string $id): ?Transaction
    {
        /** @var class-string<Transaction> $model */
        $model = $this->transactionModel;

        return $model::where('gateway_order_id', $id)->latest()->first();
    }

    private function syncPaymentStatus(string $paymentId, TransactionStatus $status): void
    {
        /** @var class-string<Payment> $model */
        $model = $this->paymentModel;

        $updates = ['status' => $status->value];

        if ($status->isFinal()) {
            $updates['completed_at'] = now();
        }

        // Single atomic UPDATE — prevents backwards transitions under concurrent
        // webhooks/callbacks. A more advanced status cannot be overwritten by a
        // less advanced one (e.g. successful → failed is blocked).
        $model::where('id', $paymentId)
            ->whereNotIn('status', $this->statusesBlockedBy($status))
            ->update($updates);
    }

    private function statusesBlockedBy(TransactionStatus $incoming): array
    {
        return match ($incoming) {
            // These cannot overwrite any finalized positive outcome
            TransactionStatus::Initiated,
            TransactionStatus::Pending,
            TransactionStatus::Authorized,
            TransactionStatus::Failed,
            TransactionStatus::Expired => [
                TransactionStatus::Successful->value,
                TransactionStatus::PartiallyRefunded->value,
                TransactionStatus::Refunded->value,
                TransactionStatus::Voided->value,
            ],
            // Successful cannot overwrite a refund or void
            TransactionStatus::Successful => [
                TransactionStatus::PartiallyRefunded->value,
                TransactionStatus::Refunded->value,
                TransactionStatus::Voided->value,
            ],
            // PartiallyRefunded cannot overwrite a full refund or void
            TransactionStatus::PartiallyRefunded => [
                TransactionStatus::Refunded->value,
                TransactionStatus::Voided->value,
            ],
            // Refunded and Voided are terminal — no restrictions on reaching them
            TransactionStatus::Refunded,
            TransactionStatus::Voided => [],
        };
    }
}