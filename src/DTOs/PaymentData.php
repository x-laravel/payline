<?php

namespace XLaravel\Payline\DTOs;

use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\Enums\PaymentMethod;

readonly class PaymentData
{
    public function __construct(
        public int $amount,
        public string $currency,
        public string $reference,
        public ?string $customerEmail = null,
        public ?string $customerName = null,
        public ?string $customerPhone = null,
        public ?string $customerIp = null,
        public ?string $description = null,
        public ?PaymentMethod $method = null,
        public ?CardData $card = null,
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

    public static function fromPayable(Payable $payable, array $extra = []): self
    {
        return new self(
            amount: $payable->getPayableAmount(),
            currency: $payable->getPayableCurrency(),
            reference: $payable->getPayableReference(),
            customerEmail: $payable->getPayableCustomerEmail(),
            customerName: $payable->getPayableCustomerName(),
            description: $payable->getPayableDescription(),
            ...$extra,
        );
    }
}
