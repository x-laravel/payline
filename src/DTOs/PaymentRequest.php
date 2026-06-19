<?php

namespace XLaravel\Payline\DTOs;

use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\Enums\PaymentMethod;

readonly class PaymentRequest
{
    /**
     * @param BasketItem[] $basketItems
     */
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
        public array $basketItems = [],
        public ?Address $billingAddress = null,
        public ?Address $shippingAddress = null,
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

    /**
     * @param BasketItem[] $basketItems
     */
    public static function fromPayable(
        Payable $payable,
        ?Card $card = null,
        ?string $cardToken = null,
        bool $saveCard = false,
        bool $threeDs = true,
        ?int $installments = null,
        ?string $callbackUrl = null,
        array $basketItems = [],
        ?Address $billingAddress = null,
        ?Address $shippingAddress = null,
        ?array $metadata = null,
        ?CardProfile $cardProfile = null,
        ?string $customerEmail = null,
        ?string $customerName = null,
        ?string $customerPhone = null,
        ?string $customerIp = null,
        ?string $description = null,
        ?PaymentMethod $method = null,
    ): self {
        return new self(
            reference: $payable->getPayableReference(),
            amount: $payable->getPayableAmount(),
            currency: $payable->getPayableCurrency(),
            customerEmail: $customerEmail ?? $payable->getPayableCustomerEmail(),
            customerName: $customerName ?? $payable->getPayableCustomerName(),
            customerPhone: $customerPhone,
            customerIp: $customerIp,
            description: $description ?? $payable->getPayableDescription(),
            method: $method,
            card: $card,
            cardToken: $cardToken,
            saveCard: $saveCard,
            threeDs: $threeDs,
            installments: $installments,
            callbackUrl: $callbackUrl,
            basketItems: $basketItems,
            billingAddress: $billingAddress,
            shippingAddress: $shippingAddress,
            metadata: $metadata,
            cardProfile: $cardProfile,
        );
    }
}