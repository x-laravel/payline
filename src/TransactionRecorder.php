<?php

namespace XLaravel\Payline;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\DTOs\CaptureData;
use XLaravel\Payline\DTOs\PaymentAttempt;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;
use XLaravel\Payline\Enums\PaymentStatus;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Exceptions\IdempotencyConflictException;
use XLaravel\Payline\Exceptions\InvalidPaymentOperationException;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;

class TransactionRecorder
{
    private string $paymentModel;
    private string $transactionModel;

    public function __construct()
    {
        $this->paymentModel = config('payline.models.payment', Payment::class);
        $this->transactionModel = config('payline.models.transaction', Transaction::class);
    }

    public function createPaymentAttempt(
        string $gateway,
        TransactionType $type,
        PaymentRequest $data,
        ?Payable $payable = null,
        ?object $owner = null,
    ): PaymentAttempt {
        try {
            return $this->connection()->transaction(function () use ($gateway, $type, $data, $payable, $owner) {
                if ($data->idempotencyKey !== null) {
                    $existing = $this->findPaymentByIdempotencyKey($gateway, $type, $data->idempotencyKey);

                    if ($existing !== null) {
                        return $this->existingAttempt($existing, $type, $data->fingerprint());
                    }
                }

                $payment = $this->createPayment($gateway, $data, $payable, $owner, $type);
                $transaction = $this->createTransaction($payment, $type, $data);

                return new PaymentAttempt($payment, $transaction, true);
            });
        } catch (QueryException $exception) {
            if ($data->idempotencyKey === null) {
                throw $exception;
            }

            $existing = $this->findPaymentByIdempotencyKey($gateway, $type, $data->idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            return $this->existingAttempt($existing, $type, $data->fingerprint());
        }
    }

    public function createPayment(
        string $gateway,
        PaymentRequest $data,
        ?Payable $payable = null,
        ?object $owner = null,
        TransactionType $type = TransactionType::Payment,
    ): Payment {
        $model = $this->paymentModel;
        $storeCardDetails = config('payline.storage.card_details', true);
        $storeCardHolder = config('payline.storage.card_holder_name', true);

        return $model::create([
            'gateway' => $gateway,
            'initial_type' => $type->value,
            'status' => PaymentStatus::Initiated->value,
            'idempotency_key' => $data->idempotencyKey,
            'request_hash' => $data->fingerprint(),
            'amount' => $data->amount,
            'currency' => strtoupper($data->currency),
            'payable_type' => $payable ? get_class($payable) : null,
            'payable_id' => $payable?->getKey(),
            'owner_type' => $owner ? get_class($owner) : null,
            'owner_id' => $owner?->getKey(),
            'card_bin' => $storeCardDetails ? $data->card?->bin() : null,
            'card_last_four' => $storeCardDetails ? $data->card?->lastFour() : null,
            'card_holder_name' => $storeCardHolder ? $data->card?->holderName : null,
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
        $model = $this->transactionModel;

        return $model::create([
            'payment_id' => $payment->id,
            'type' => $type->value,
            'status' => TransactionStatus::Initiated->value,
            'amount' => $data->amount,
            'currency' => strtoupper($data->currency),
            'attempt' => $attempt,
            'request_hash' => $data->fingerprint(),
            'metadata' => $data->metadata,
        ]);
    }

    public function createCaptureTransaction(Payment $payment, CaptureData $data, Transaction $parent): PaymentAttempt
    {
        return $this->createChildAttempt(
            $payment,
            $parent,
            TransactionType::Capture,
            $data->amount,
            $data->currency,
            $data->gatewayTransactionId,
            $data->metadata,
            $data->idempotencyKey,
            $data->fingerprint(),
        );
    }

    public function createRefundTransaction(Payment $payment, RefundData $data, Transaction $parent): PaymentAttempt
    {
        return $this->createChildAttempt(
            $payment,
            $parent,
            TransactionType::Refund,
            $data->amount,
            $data->currency,
            $data->gatewayTransactionId,
            $data->metadata,
            $data->idempotencyKey,
            $data->fingerprint(),
        );
    }

    public function createVoidTransaction(Payment $payment, VoidData $data, Transaction $parent): PaymentAttempt
    {
        return $this->createChildAttempt(
            $payment,
            $parent,
            TransactionType::Void,
            $parent->amount,
            $parent->currency,
            $data->gatewayTransactionId,
            $data->metadata,
            $data->idempotencyKey,
            $data->fingerprint(),
        );
    }

    public function updateTransaction(Transaction $transaction, PaymentResponse $response): Transaction
    {
        return $this->connection()->transaction(function () use ($transaction, $response) {
            $transactionModel = $this->transactionModel;
            $paymentModel = $this->paymentModel;

            $lockedTransaction = $transactionModel::query()
                ->lockForUpdate()
                ->findOrFail($transaction->getKey());

            if (! $this->canTransitionTransaction($lockedTransaction->status, $response->status)) {
                return $lockedTransaction;
            }

            $payment = $paymentModel::query()
                ->lockForUpdate()
                ->findOrFail($lockedTransaction->payment_id);

            $lockedTransaction->fill([
                'status' => $response->status->value,
                'amount' => $this->confirmedAmount($lockedTransaction, $response),
                'gateway_transaction_id' => $response->gatewayTransactionId ?? $lockedTransaction->gateway_transaction_id,
                'gateway_order_id' => $response->gatewayOrderId ?? $lockedTransaction->gateway_order_id,
                'gateway_auth_code' => $response->gatewayAuthCode ?? $lockedTransaction->gateway_auth_code,
                'gateway_response_code' => $response->gatewayResponseCode ?? $lockedTransaction->gateway_response_code,
                'gateway_response_message' => $response->gatewayResponseMessage ?? $lockedTransaction->gateway_response_message,
                'error_code' => $response->errorCode,
                'error_message' => $response->errorMessage,
                'redirect_url' => $response->redirectUrl ?? $lockedTransaction->redirect_url,
                'metadata' => $response->metadata ?? $lockedTransaction->metadata,
                'completed_at' => $response->status->isFinal()
                    ? ($lockedTransaction->completed_at ?? now())
                    : null,
            ]);
            $lockedTransaction->save();

            $this->syncPaymentStatus($payment, $lockedTransaction);

            return $lockedTransaction;
        });
    }

    public function failTransaction(Transaction $transaction, string $message): Transaction
    {
        return $this->markTransaction($transaction, TransactionStatus::Failed, $message);
    }

    public function markTransactionUnknown(Transaction $transaction, string $message): Transaction
    {
        return $this->markTransaction($transaction, TransactionStatus::Unknown, $message);
    }

    private function markTransaction(
        Transaction $transaction,
        TransactionStatus $status,
        string $message,
    ): Transaction
    {
        return $this->connection()->transaction(function () use ($transaction, $status, $message) {
            $transactionModel = $this->transactionModel;
            $paymentModel = $this->paymentModel;

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

    public function findTransactionByGatewayTransactionId(
        string $id,
        ?string $gateway = null,
        ?TransactionType $type = null,
    ): ?Transaction
    {
        return $this->findTransaction('gateway_transaction_id', $id, $gateway, $type);
    }

    public function findTransactionByGatewayOrderId(
        string $id,
        ?string $gateway = null,
        ?TransactionType $type = null,
    ): ?Transaction
    {
        return $this->findTransaction('gateway_order_id', $id, $gateway, $type);
    }

    public function responseFromTransaction(Transaction $transaction): PaymentResponse
    {
        $transaction->loadMissing('payment');

        return new PaymentResponse(
            status: $transaction->status,
            type: $transaction->type,
            gatewayName: $transaction->payment->gateway,
            gatewayTransactionId: $transaction->gateway_transaction_id,
            gatewayOrderId: $transaction->gateway_order_id,
            gatewayAuthCode: $transaction->gateway_auth_code,
            gatewayResponseCode: $transaction->gateway_response_code,
            gatewayResponseMessage: $transaction->gateway_response_message,
            amount: $transaction->amount,
            currency: $transaction->currency,
            redirectUrl: $transaction->redirect_url,
            errorCode: $transaction->error_code,
            errorMessage: $transaction->error_message,
            metadata: $transaction->metadata,
        );
    }

    public function findOperationByIdempotencyKey(
        Payment $payment,
        TransactionType $type,
        ?string $key,
        ?string $requestHash = null,
    ): ?Transaction {
        if ($key === null) {
            return null;
        }

        $transaction = $this->findChildByIdempotencyKey($payment, $type, $key);

        if ($transaction !== null && $requestHash !== null) {
            $this->assertSameRequest($transaction->request_hash, $requestHash);
        }

        return $transaction;
    }

    private function createChildAttempt(
        Payment $payment,
        Transaction $parent,
        TransactionType $type,
        int $amount,
        string $currency,
        string $gatewayTransactionId,
        ?array $metadata,
        ?string $idempotencyKey,
        string $requestHash,
    ): PaymentAttempt {
        try {
            return $this->connection()->transaction(function () use (
                $payment,
                $parent,
                $type,
                $amount,
                $currency,
                $gatewayTransactionId,
                $metadata,
                $idempotencyKey,
                $requestHash,
            ) {
                if ($idempotencyKey !== null) {
                    $existing = $this->findChildByIdempotencyKey($payment, $type, $idempotencyKey);

                    if ($existing !== null) {
                        $this->assertSameRequest($existing->request_hash, $requestHash);
                        return new PaymentAttempt($payment, $existing, false);
                    }
                }

                $this->assertOperationAmountAvailable($payment, $parent, $type, $amount);

                $transaction = $this->createChildTransaction(
                    $payment,
                    $parent,
                    $type,
                    $amount,
                    $currency,
                    $gatewayTransactionId,
                    $metadata,
                    $idempotencyKey,
                    $requestHash,
                );

                return new PaymentAttempt($payment, $transaction, true);
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey === null) {
                throw $exception;
            }

            $existing = $this->findChildByIdempotencyKey($payment, $type, $idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            $this->assertSameRequest($existing->request_hash, $requestHash);

            return new PaymentAttempt($payment, $existing, false);
        }
    }

    private function createChildTransaction(
        Payment $payment,
        Transaction $parent,
        TransactionType $type,
        int $amount,
        string $currency,
        string $gatewayTransactionId,
        ?array $metadata,
        ?string $idempotencyKey,
        string $requestHash,
    ): Transaction {
        $model = $this->transactionModel;

        return $model::create([
            'payment_id' => $payment->id,
            'type' => $type->value,
            'status' => TransactionStatus::Initiated->value,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'attempt' => $this->nextAttempt($payment, $type),
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'gateway_transaction_id' => $gatewayTransactionId,
            'parent_transaction_id' => $parent->id,
            'metadata' => $metadata,
        ]);
    }

    private function findChildByIdempotencyKey(
        Payment $payment,
        TransactionType $type,
        string $key,
    ): ?Transaction {
        return $payment->transactions()
            ->where('type', $type->value)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function assertOperationAmountAvailable(
        Payment $payment,
        Transaction $parent,
        TransactionType $type,
        int $amount,
    ): void {
        if (! in_array($type, [TransactionType::Capture, TransactionType::Refund], true)) {
            return;
        }

        $transactionModel = $this->transactionModel;
        $lockedParent = $transactionModel::query()
            ->lockForUpdate()
            ->findOrFail($parent->getKey());

        $reserved = $this->reservedAmount(
            $transactionModel::query()->where('parent_transaction_id', $lockedParent->getKey()),
            $type,
        );

        if ($reserved + $amount > $lockedParent->amount) {
            throw new InvalidPaymentOperationException(
                $type === TransactionType::Refund
                    ? 'Refund amount exceeds the unreserved amount of the parent transaction.'
                    : 'Capture amount exceeds the unreserved authorized amount.',
            );
        }

        if ($type !== TransactionType::Refund) {
            return;
        }

        $paymentModel = $this->paymentModel;
        $lockedPayment = $paymentModel::query()
            ->lockForUpdate()
            ->findOrFail($payment->getKey());

        if ($this->reservedAmount($lockedPayment->transactions(), $type) + $amount > $lockedPayment->amount) {
            throw new InvalidPaymentOperationException(
                'Refund amount exceeds the unreserved refundable amount.',
            );
        }
    }

    private function reservedAmount($query, TransactionType $type): int
    {
        return (int) $query
            ->where('type', $type->value)
            ->whereNotIn('status', [
                TransactionStatus::Failed->value,
                TransactionStatus::Expired->value,
            ])
            ->sum('amount');
    }

    private function confirmedAmount(Transaction $transaction, PaymentResponse $response): int
    {
        return $response->amount > 0 && $response->amount <= (int) $transaction->amount
            ? $response->amount
            : (int) $transaction->amount;
    }

    private function existingAttempt(Payment $payment, TransactionType $type, string $requestHash): PaymentAttempt
    {
        $this->assertSameRequest($payment->request_hash, $requestHash);

        $transaction = $payment->transactions()
            ->where('type', $type->value)
            ->latest('created_at')
            ->firstOrFail();

        return new PaymentAttempt($payment, $transaction, false);
    }

    private function assertSameRequest(string $storedHash, string $incomingHash): void
    {
        if (! hash_equals($storedHash, $incomingHash)) {
            throw new IdempotencyConflictException(
                'The idempotency key has already been used with a different request.',
            );
        }
    }

    private function findPaymentByIdempotencyKey(
        string $gateway,
        TransactionType $type,
        string $key,
    ): ?Payment
    {
        $model = $this->paymentModel;

        return $model::query()
            ->where('gateway', $gateway)
            ->where('initial_type', $type->value)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function findTransaction(
        string $column,
        string $id,
        ?string $gateway,
        ?TransactionType $type,
    ): ?Transaction
    {
        $model = $this->transactionModel;
        $query = $model::query()->where($column, $id);

        if ($gateway !== null) {
            $query->whereHas('payment', fn ($payment) => $payment->where('gateway', $gateway));
        }

        if ($type !== null) {
            $query->where('type', $type->value);
        }

        return $query->latest('created_at')->first();
    }

    private function nextAttempt(Payment $payment, TransactionType $type): int
    {
        return ((int) $payment->transactions()->where('type', $type->value)->max('attempt')) + 1;
    }

    private function syncPaymentStatus(Payment $payment, Transaction $transaction): void
    {
        $incoming = $this->paymentStatusFor($payment, $transaction);

        if ($incoming === null || $incoming === $payment->status || ! $this->canTransition($payment->status, $incoming)) {
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
            TransactionType::Payment => $this->initialPaymentStatus($transaction->status),
            TransactionType::Authorization => $this->authorizationStatus($transaction->status),
            TransactionType::Capture => match ($transaction->status) {
                TransactionStatus::Successful => PaymentStatus::Paid,
                TransactionStatus::Pending => PaymentStatus::Pending,
                TransactionStatus::Expired => PaymentStatus::Expired,
                TransactionStatus::Unknown => PaymentStatus::Unknown,
                default => null,
            },
            TransactionType::Refund => $this->refundStatus($payment, $transaction->status),
            TransactionType::Void => match ($transaction->status) {
                TransactionStatus::Voided => PaymentStatus::Voided,
                TransactionStatus::Unknown => PaymentStatus::Unknown,
                default => null,
            },
        };
    }

    private function initialPaymentStatus(TransactionStatus $status): ?PaymentStatus
    {
        return match ($status) {
            TransactionStatus::Initiated => PaymentStatus::Initiated,
            TransactionStatus::Pending => PaymentStatus::Pending,
            TransactionStatus::Authorized => PaymentStatus::Authorized,
            TransactionStatus::Successful => PaymentStatus::Paid,
            TransactionStatus::Failed => PaymentStatus::Failed,
            TransactionStatus::Expired => PaymentStatus::Expired,
            TransactionStatus::Unknown => PaymentStatus::Unknown,
            default => null,
        };
    }

    private function authorizationStatus(TransactionStatus $status): ?PaymentStatus
    {
        return match ($status) {
            TransactionStatus::Initiated => PaymentStatus::Initiated,
            TransactionStatus::Pending => PaymentStatus::Pending,
            TransactionStatus::Authorized => PaymentStatus::Authorized,
            TransactionStatus::Failed => PaymentStatus::Failed,
            TransactionStatus::Expired => PaymentStatus::Expired,
            TransactionStatus::Unknown => PaymentStatus::Unknown,
            default => null,
        };
    }

    private function refundStatus(Payment $payment, TransactionStatus $status): ?PaymentStatus
    {
        if ($status !== TransactionStatus::Successful) {
            return $status === TransactionStatus::Unknown ? PaymentStatus::Unknown : null;
        }

        $refunded = (int) $payment->refunds()
            ->where('status', TransactionStatus::Successful->value)
            ->sum('amount');

        return $refunded >= $payment->amount
            ? PaymentStatus::Refunded
            : PaymentStatus::PartiallyRefunded;
    }

    private function canTransition(PaymentStatus $from, PaymentStatus $to): bool
    {
        if ($from === PaymentStatus::Unknown) {
            return true;
        }

        return match ($from) {
            PaymentStatus::Initiated => true,
            PaymentStatus::Pending => in_array($to, [
                PaymentStatus::Authorized,
                PaymentStatus::Paid,
                PaymentStatus::Failed,
                PaymentStatus::Expired,
                PaymentStatus::Unknown,
            ], true),
            PaymentStatus::Authorized => in_array($to, [
                PaymentStatus::Paid,
                PaymentStatus::Voided,
                PaymentStatus::Unknown,
            ], true),
            PaymentStatus::Paid => in_array($to, [
                PaymentStatus::PartiallyRefunded,
                PaymentStatus::Refunded,
                PaymentStatus::Unknown,
            ], true),
            PaymentStatus::PartiallyRefunded => in_array($to, [
                PaymentStatus::Refunded,
                PaymentStatus::Unknown,
            ], true),
            PaymentStatus::Failed,
            PaymentStatus::Expired => in_array($to, [
                PaymentStatus::Pending,
                PaymentStatus::Authorized,
                PaymentStatus::Paid,
            ], true),
            PaymentStatus::Refunded,
            PaymentStatus::Voided => false,
        };
    }

    private function canTransitionTransaction(TransactionStatus $from, TransactionStatus $to): bool
    {
        if ($from === $to || $from === TransactionStatus::Initiated) {
            return true;
        }

        return match ($from) {
            TransactionStatus::Pending => in_array($to, [
                TransactionStatus::Authorized,
                TransactionStatus::Successful,
                TransactionStatus::Failed,
                TransactionStatus::Expired,
                TransactionStatus::Voided,
                TransactionStatus::Unknown,
            ], true),
            TransactionStatus::Unknown => true,
            TransactionStatus::Initiated => true,
            TransactionStatus::Authorized,
            TransactionStatus::Successful,
            TransactionStatus::Failed,
            TransactionStatus::Expired,
            TransactionStatus::Voided => false,
        };
    }

    private function connection()
    {
        return DB::connection(config('payline.database.connection'));
    }
}
