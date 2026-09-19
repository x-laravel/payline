<?php

namespace XLaravel\Payline\DTOs;

use InvalidArgumentException;
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
        public ?string $idempotencyKey = null,
    ) {
        if (trim($this->reference) === '') {
            throw new InvalidArgumentException('Payment reference cannot be empty.');
        }

        if ($this->amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        if (! preg_match('/^[A-Z]{3}$/', strtoupper($this->currency))) {
            throw new InvalidArgumentException('Payment currency must be a three-letter ISO code.');
        }

        if ($this->installments !== null && $this->installments < 1) {
            throw new InvalidArgumentException('Installments must be greater than zero.');
        }

        if ($this->idempotencyKey !== null && trim($this->idempotencyKey) === '') {
            throw new InvalidArgumentException('Idempotency key cannot be empty.');
        }
    }

    public function withCallbackUrl(string $url): self
    {
        return $this->with(['callbackUrl' => $url]);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'reference' => $this->reference,
            'amount' => $this->amount,
            'currency' => strtoupper($this->currency),
            'method' => $this->method?->value,
            'card_bin' => $this->card?->bin(),
            'card_last_four' => $this->card?->lastFour(),
            'card_token' => $this->cardToken,
            'three_ds' => $this->threeDs,
            'installments' => $this->installments ?? 1,
        ], JSON_THROW_ON_ERROR));
    }

    private function with(array $overrides): self
    {
        $args = [];
        foreach ((new \ReflectionClass($this))->getProperties() as $prop) {
            $name = $prop->getName();
            $args[$name] = array_key_exists($name, $overrides) ? $overrides[$name] : $this->{$name};
        }
        return new self(...$args);
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
        ?string $idempotencyKey = null,
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
            idempotencyKey: $idempotencyKey,
        );
    }
}
