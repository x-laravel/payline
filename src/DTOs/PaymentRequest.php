<?php

namespace XLaravel\Payline\DTOs;

use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\Enums\PaymentMethod;

readonly class PaymentRequest
{
    public function __construct(
        public string $reference,
        public int $amount,
        public string $currency,
        public ?string $customerEmail = null,
        public ?string $customerName = null,
        public ?string $customerPhone = null,
        public ?string $customerIp = null,
        public ?string $description = null,
        public ?PaymentMethod $method = null,
        public ?Card $card = null,
        public ?string $cardToken = null,
        public bool $saveCard = false,
        public bool $threeDs = true,
        public ?int $installments = null,
        public ?string $callbackUrl = null,
        public ?array $basketItems = null,
        public ?array $billingAddress = null,
        public ?array $shippingAddress = null,
        public ?array $metadata = null,
        public ?CardProfile $cardProfile = null,
    ) {}

    public function withCallbackUrl(string $url): self
    {
        return new self(
            reference: $this->reference,
            amount: $this->amount,
            currency: $this->currency,
            customerEmail: $this->customerEmail,
            customerName: $this->customerName,
            customerPhone: $this->customerPhone,
            customerIp: $this->customerIp,
            description: $this->description,
            method: $this->method,
            card: $this->card,
            cardToken: $this->cardToken,
            saveCard: $this->saveCard,
            threeDs: $this->threeDs,
            installments: $this->installments,
            callbackUrl: $url,
            basketItems: $this->basketItems,
            billingAddress: $this->billingAddress,
            shippingAddress: $this->shippingAddress,
            metadata: $this->metadata,
            cardProfile: $this->cardProfile,
        );
    }

    public static function fromPayable(Payable $payable, array $extra = []): self
    {
        return new self(
            reference: $payable->getPayableReference(),
            amount: $payable->getPayableAmount(),
            currency: $payable->getPayableCurrency(),
            customerEmail: $extra['customerEmail'] ?? $payable->getPayableCustomerEmail(),
            customerName: $extra['customerName'] ?? $payable->getPayableCustomerName(),
            customerPhone: $extra['customerPhone'] ?? null,
            customerIp: $extra['customerIp'] ?? null,
            description: $extra['description'] ?? $payable->getPayableDescription(),
            method: $extra['method'] ?? null,
            card: $extra['card'] ?? null,
            cardToken: $extra['cardToken'] ?? null,
            saveCard: $extra['saveCard'] ?? false,
            threeDs: $extra['threeDs'] ?? true,
            installments: $extra['installments'] ?? null,
            callbackUrl: $extra['callbackUrl'] ?? null,
            basketItems: $extra['basketItems'] ?? null,
            billingAddress: $extra['billingAddress'] ?? null,
            shippingAddress: $extra['shippingAddress'] ?? null,
            metadata: $extra['metadata'] ?? null,
            cardProfile: $extra['cardProfile'] ?? null,
        );
    }
}
