<?php

namespace XLaravel\Payline\Contracts;

use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\DTOs\CaptureData;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;

interface Gateway
{
    public function pay(PaymentRequest $data): PaymentResponse;

    public function authorize(PaymentRequest $data): PaymentResponse;

    public function capture(CaptureData $data): PaymentResponse;

    public function refund(RefundData $data): PaymentResponse;

    public function void(VoidData $data): PaymentResponse;

    public function handleCallback(CallbackData $data): PaymentResponse;

    public function verifyWebhook(array $payload, string $signature): bool;

    public function parseWebhook(array $payload): PaymentResponse;

    public function supportedMethods(): array;

    public function getName(): string;
}
