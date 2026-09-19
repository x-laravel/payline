<?php

namespace XLaravel\Payline\Contracts;

use XLaravel\Payline\DTOs\PaymentResponse;

interface HandlesWebhooks
{
    public function verifyWebhook(array $payload, string $signature): bool;

    public function parseWebhook(array $payload): PaymentResponse;
}
