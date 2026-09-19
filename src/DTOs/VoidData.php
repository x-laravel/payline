<?php

namespace XLaravel\Payline\DTOs;

use InvalidArgumentException;

readonly class VoidData
{
    public function __construct(
        public string $gatewayTransactionId,
        public ?array $metadata = null,
        public ?string $idempotencyKey = null,
    ) {
        if (trim($this->gatewayTransactionId) === '') {
            throw new InvalidArgumentException('Gateway transaction ID cannot be empty.');
        }

        if ($this->idempotencyKey !== null && trim($this->idempotencyKey) === '') {
            throw new InvalidArgumentException('Idempotency key cannot be empty.');
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'gateway_transaction_id' => $this->gatewayTransactionId,
        ], JSON_THROW_ON_ERROR));
    }
}
