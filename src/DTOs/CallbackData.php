<?php

namespace XLaravel\Payline\DTOs;

readonly class CallbackData
{
    public function __construct(
        public string $gateway,
        public array $requestData,
        public array $headers = [],
        public ?string $rawBody = null,
    ) {}
}
