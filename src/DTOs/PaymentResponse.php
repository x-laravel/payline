<?php

namespace XLaravel\Payline\DTOs;

use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;

readonly class PaymentResponse
{
    public function __construct(
        public TransactionStatus $status,
        public TransactionType $type,
        public string $gatewayName,
        public ?string $gatewayTransactionId = null,
        public ?string $gatewayOrderId = null,
        public ?string $gatewayAuthCode = null,
        public ?string $gatewayResponseCode = null,
        public ?string $gatewayResponseMessage = null,
        public int $amount = 0,
        public string $currency = 'TRY',
        public ?string $redirectUrl = null,
        public ?string $redirectForm = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?array $metadata = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status === TransactionStatus::Successful;
    }

    public function isPending(): bool
    {
        return $this->status === TransactionStatus::Pending;
    }

    public function isFailure(): bool
    {
        return $this->status === TransactionStatus::Failed;
    }

    public function requiresRedirect(): bool
    {
        return $this->redirectUrl !== null || $this->redirectForm !== null;
    }

    public function requiresAction(): bool
    {
        return $this->requiresRedirect();
    }
}
