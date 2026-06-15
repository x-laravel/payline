<?php

namespace XLaravel\Payline\DTOs;

readonly class VoidData
{
    public function __construct(
        public string $gatewayTransactionId,
        public ?array $metadata = null,
    ) {}
}
