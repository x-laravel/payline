<?php

namespace XLaravel\Payline\DTOs;

use InvalidArgumentException;

readonly class PaymentQuery
{
    public function __construct(
        public ?string $gatewayTransactionId = null,
        public ?string $gatewayOrderId = null,
        public ?string $reference = null,
        public ?array $metadata = null,
    ) {
        if ($this->gatewayTransactionId === null
            && $this->gatewayOrderId === null
            && $this->reference === null) {
            throw new InvalidArgumentException('A payment query requires at least one provider or merchant reference.');
        }
    }
}
