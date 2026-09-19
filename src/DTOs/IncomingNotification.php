<?php

namespace XLaravel\Payline\DTOs;

use Illuminate\Http\Request;
use JsonException;

readonly class IncomingNotification
{
    public function __construct(
        public string $gateway,
        public array $query,
        public array $body,
        public array $headers,
        public string $rawBody,
        public string $signature,
    ) {}

    public static function fromRequest(Request $request, string $gateway): self
    {
        return new self(
            gateway: $gateway,
            query: $request->query(),
            body: self::bodyOf($request),
            headers: $request->headers->all(),
            rawBody: $request->getContent(),
            signature: $request->header('X-Webhook-Signature')
                ?? $request->header('X-Gateway-Signature')
                ?? '',
        );
    }

    public function payload(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->gateway . "\0" . $this->rawBody);
    }

    private static function bodyOf(Request $request): array
    {
        try {
            return $request->getPayload()->all();
        } catch (JsonException) {
            return [];
        }
    }
}
