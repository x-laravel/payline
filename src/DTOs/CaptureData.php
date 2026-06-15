<?php

namespace XLaravel\Payline\DTOs;

readonly class CaptureData
{
    public function __construct(
        public string $gatewayTransactionId,
        public int $amount,
        public string $currency,
        public ?array $metadata = null,
    ) {}
}
