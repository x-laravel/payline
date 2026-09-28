<?php

namespace XLaravel\Payline\Tests\Fixtures\Gateways;

use XLaravel\Payline\Contracts\AuthorizesPayments;
use XLaravel\Payline\Contracts\CapturesPayments;
use XLaravel\Payline\Contracts\ChargesPayments;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\HandlesCallbacks;
use XLaravel\Payline\Contracts\HandlesWebhooks;
use XLaravel\Payline\Contracts\RefundsPayments;
use XLaravel\Payline\Contracts\VoidsPayments;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\DTOs\CaptureData;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;

class FakeGateway implements
    AuthorizesPayments,
    CapturesPayments,
    ChargesPayments,
    Gateway,
    HandlesCallbacks,
    HandlesWebhooks,
    RefundsPayments,
    VoidsPayments
{
    private static ?PaymentResponse $nextResponse = null;

    public static function willReturn(PaymentResponse $response): void
    {
        self::$nextResponse = $response;
    }

    public static function reset(): void
    {
        self::$nextResponse = null;
    }

    public function pay(PaymentRequest $data): PaymentResponse
    {
        return $this->response(TransactionType::Payment);
    }

    public function authorize(PaymentRequest $data): PaymentResponse
    {
        return $this->response(TransactionType::Authorization, TransactionStatus::Authorized);
    }

    public function capture(CaptureData $data): PaymentResponse
    {
        return $this->response(TransactionType::Capture);
    }

    public function refund(RefundData $data): PaymentResponse
    {
        return $this->response(TransactionType::Refund);
    }

    public function void(VoidData $data): PaymentResponse
    {
        return $this->response(TransactionType::Void, TransactionStatus::Voided);
    }

    public function handleCallback(CallbackData $data): PaymentResponse
    {
        return $this->response(TransactionType::Payment);
    }

    public function verifyWebhook(array $payload, string $signature): bool
    {
        return true;
    }

    public function parseWebhook(array $payload): PaymentResponse
    {
        return $this->response(TransactionType::Payment);
    }

    public function getName(): string
    {
        return 'fake';
    }

    private function response(
        TransactionType $type,
        TransactionStatus $status = TransactionStatus::Successful,
    ): PaymentResponse {
        if (self::$nextResponse !== null) {
            $response = self::$nextResponse;
            self::$nextResponse = null;
            return $response;
        }

        return new PaymentResponse(
            status: $status,
            type: $type,
            gatewayName: 'fake',
            gatewayTransactionId: 'fake-txn-' . uniqid(),
        );
    }
}
