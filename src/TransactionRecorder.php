<?php

namespace XLaravel\Payline;

use Illuminate\Database\QueryException;
use XLaravel\Payline\Concerns\InteractsWithPaylineStorage;
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
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;
use XLaravel\Payline\Payments\AmountLedger;

class TransactionRecorder
{
    use InteractsWithPaylineStorage;

    public function __construct(
        private readonly AmountLedger $ledger,
    ) {}

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
        $model = $this->paymentModel();
        $storeCardDetails = config('payline.storage.card_details', true);
        $storeCardHolder = config('payline.storage.card_holder_name', true);
        $profile = config('payline.storage.card_profile', true) ? $data->profile() : null;

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
            'card_family' => $profile?->family,
            'card_type' => $profile?->type?->value,
            'card_scheme' => $profile?->scheme?->value,
            'card_issuer' => $profile?->issuer,
            'card_issuer_country' => $profile?->issuerCountry,
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
        $model = $this->transactionModel();

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

    public function createOperationTransaction(
        Payment $payment,
        TransactionType $type,
        CaptureData|RefundData|VoidData $data,
        Transaction $parent,
    ): PaymentAttempt {
        return $this->createChildAttempt(
            $payment,
            $parent,
            $type,
            $data instanceof VoidData ? (int) $parent->amount : $data->amount,
            $data instanceof VoidData ? $parent->currency : $data->currency,
            $data->gatewayTransactionId,
            $data->metadata,
            $data->idempotencyKey,
            $data->fingerprint(),
        );
    }

    public function findTransactionByGatewayTransactionId(
        string $id,
        ?string $gateway = null,
        ?TransactionType $type = null,
    ): ?Transaction {
        return $this->findTransaction('gateway_transaction_id', $id, $gateway, $type);
    }

    public function findTransactionByGatewayOrderId(
        string $id,
        ?string $gateway = null,
        ?TransactionType $type = null,
    ): ?Transaction {
        return $this->findTransaction('gateway_order_id', $id, $gateway, $type);
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

                $this->ledger->assertAvailable($payment, $parent, $type, $amount);

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
        $model = $this->transactionModel();

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
    ): ?Payment {
        $model = $this->paymentModel();

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
    ): ?Transaction {
        $model = $this->transactionModel();
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
}
