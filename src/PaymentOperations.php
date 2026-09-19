<?php

namespace XLaravel\Payline;

use LogicException;
use XLaravel\Payline\DTOs\CaptureData;
use XLaravel\Payline\DTOs\PaymentQuery;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;

class PaymentOperations
{
    public function __construct(
        private readonly PaylineManager $manager,
        private readonly Payment $payment,
    ) {}

    public function capture(
        ?int $amount = null,
        ?string $idempotencyKey = null,
        ?array $metadata = null,
    ): PaymentResponse {
        $parent = $this->parent(TransactionType::Authorization, TransactionStatus::Authorized);

        return $this->manager->via()->capture(
            new CaptureData(
                gatewayTransactionId: $this->providerTransactionId($parent),
                amount: $amount ?? $parent->amount,
                currency: $parent->currency,
                metadata: $metadata,
                idempotencyKey: $idempotencyKey,
            ),
            $this->payment,
            $parent,
        );
    }

    public function refund(
        int $amount,
        ?string $reason = null,
        ?string $idempotencyKey = null,
        ?array $metadata = null,
    ): PaymentResponse {
        $parent = $this->payment->transactions()
            ->whereIn('type', [TransactionType::Payment->value, TransactionType::Capture->value])
            ->where('status', TransactionStatus::Successful->value)
            ->latest('created_at')
            ->first()
            ?? throw new LogicException('Payment has no successful transaction to refund.');

        return $this->manager->via()->refund(
            new RefundData(
                gatewayTransactionId: $this->providerTransactionId($parent),
                amount: $amount,
                currency: $parent->currency,
                reason: $reason,
                metadata: $metadata,
                idempotencyKey: $idempotencyKey,
            ),
            $this->payment,
            $parent,
        );
    }

    public function void(?string $idempotencyKey = null, ?array $metadata = null): PaymentResponse
    {
        $parent = $this->parent(TransactionType::Authorization, TransactionStatus::Authorized);

        return $this->manager->via()->void(
            new VoidData(
                gatewayTransactionId: $this->providerTransactionId($parent),
                metadata: $metadata,
                idempotencyKey: $idempotencyKey,
            ),
            $this->payment,
            $parent,
        );
    }

    public function reconcile(?PaymentQuery $query = null): PaymentResponse
    {
        return $this->manager->via()->reconcile($this->payment, $query);
    }

    private function parent(TransactionType $type, TransactionStatus $status): Transaction
    {
        return $this->payment->transactions()
            ->where('type', $type->value)
            ->where('status', $status->value)
            ->latest('created_at')
            ->first()
            ?? throw new LogicException("Payment has no {$status->value} {$type->value} transaction.");
    }

    private function providerTransactionId(Transaction $transaction): string
    {
        return $transaction->gateway_transaction_id
            ?? throw new LogicException('Transaction has no gateway transaction ID.');
    }
}
