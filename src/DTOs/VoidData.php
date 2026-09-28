<?php

namespace XLaravel\Payline\DTOs;

use InvalidArgumentException;

readonly class VoidData
{
    public function __construct(
        public string $gatewayTransactionId,
        public int $amount,
        public string $currency = 'TRY',
        public ?array $metadata = null,
        public ?string $idempotencyKey = null,
    ) {
        if (trim($this->gatewayTransactionId) === '') {
            throw new InvalidArgumentException('Gateway transaction ID cannot be empty.');
        }

        if ($this->amount <= 0) {
            throw new InvalidArgumentException('Void amount must be greater than zero.');
        }

        if (! preg_match('/^[A-Z]{3}$/', strtoupper($this->currency))) {
            throw new InvalidArgumentException('Void currency must be a three-letter ISO code.');
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
