<?php

namespace XLaravel\Payline;

use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\DTOs\CaptureData;
use XLaravel\Payline\DTOs\PaymentData;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;

class TransactionRecorder
{
    public function createPayment(
        string $gateway,
        PaymentData $data,
        ?Payable $payable = null,
        ?object $owner = null,
    ): Payment {
        /** @var class-string<Payment> $model */
        $model = config('payline.payment_model', Payment::class);

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
        PaymentData $data,
        int $attempt = 1,
    ): Transaction {
        /** @var class-string<Transaction> $model */
        $model = config('payline.transaction_model', Transaction::class);

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
        $model = config('payline.transaction_model', Transaction::class);

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
        $model = config('payline.transaction_model', Transaction::class);

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
        $model = config('payline.transaction_model', Transaction::class);

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
        $model = config('payline.transaction_model', Transaction::class);

        return $model::where('gateway_transaction_id', $id)->latest()->first();
    }

    public function findTransactionByGatewayOrderId(string $id): ?Transaction
    {
        /** @var class-string<Transaction> $model */
        $model = config('payline.transaction_model', Transaction::class);

        return $model::where('gateway_order_id', $id)->latest()->first();
    }

    private function syncPaymentStatus(string $paymentId, TransactionStatus $status): void
    {
        /** @var class-string<Payment> $model */
        $model = config('payline.payment_model', Payment::class);

        $payment = $model::find($paymentId);
        if (! $payment) {
            return;
        }

        $updates = ['status' => $status->value];

        if ($status->isFinal()) {
            $updates['completed_at'] = now();
        }

        $payment->update($updates);
    }
}