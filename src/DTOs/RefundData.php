<?php

namespace XLaravel\Payline\DTOs;

readonly class RefundData
{
    public function __construct(
        public string $gatewayTransactionId,
        public int $amount,
        public string $currency,
        public ?string $reason = null,
        public ?array $metadata = null,
    ) {}
}
