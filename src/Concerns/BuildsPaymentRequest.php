<?php

namespace XLaravel\Payline\Concerns;

use LogicException;
use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\DTOs\Address;
use XLaravel\Payline\DTOs\BasketItem;
use XLaravel\Payline\DTOs\Card;
use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\Enums\PaymentMethod;

trait BuildsPaymentRequest
{
    protected ?string $reference = null;

    protected ?int $amount = null;

    protected ?string $currency = null;

    protected ?string $customerEmail = null;

    protected ?string $customerName = null;

    protected ?string $customerPhone = null;

    protected ?string $customerIp = null;

    protected ?string $description = null;

    protected ?PaymentMethod $method = null;

    protected ?Card $card = null;

    protected ?string $cardToken = null;

    protected bool $saveCard = false;

    protected bool $threeDs = true;

    protected ?int $installments = null;

    protected ?string $callbackUrl = null;

    /** @var BasketItem[] */
    protected array $basketItems = [];

    protected ?Address $billingAddress = null;

    protected ?Address $shippingAddress = null;

    protected ?array $metadata = null;

    protected ?CardProfile $cardProfile = null;

    protected ?string $idempotencyKey = null;

    public function reference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function amount(int $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function currency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function customerEmail(string $email): static
    {
        $this->customerEmail = $email;

        return $this;
    }

    public function customerName(string $name): static
    {
        $this->customerName = $name;

        return $this;
    }

    public function customerPhone(string $phone): static
    {
        $this->customerPhone = $phone;

        return $this;
    }

    public function customerIp(?string $ip): static
    {
        $this->customerIp = $ip;

        return $this;
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function method(PaymentMethod $method): static
    {
        $this->method = $method;

        return $this;
    }

    public function card(Card $card): static
    {
        $this->card = $card;

        return $this;
    }

    public function cardToken(string $token): static
    {
        $this->cardToken = $token;

        return $this;
    }

    public function saveCard(bool $save = true): static
    {
        $this->saveCard = $save;

        return $this;
    }

    public function threeDs(): static
    {
        $this->threeDs = true;

        return $this;
    }

    public function withoutThreeDs(): static
    {
        $this->threeDs = false;

        return $this;
    }

    public function installments(int $installments): static
    {
        $this->installments = $installments;

        return $this;
    }

    public function callbackUrl(string $url): static
    {
        $this->callbackUrl = $url;

        return $this;
    }

    /**
     * @param BasketItem[] $items
     */
    public function basketItems(array $items): static
    {
        $this->basketItems = $items;

        return $this;
    }

    public function billingAddress(Address $address): static
    {
        $this->billingAddress = $address;

        return $this;
    }

    public function shippingAddress(Address $address): static
    {
        $this->shippingAddress = $address;

        return $this;
    }

    public function metadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function cardProfile(CardProfile $profile): static
    {
        $this->cardProfile = $profile;

        return $this;
    }

    public function idempotencyKey(string $key): static
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    protected function toPaymentRequest(?Payable $payable): PaymentRequest
    {
        $reference = $this->reference ?? $payable?->getPayableReference();
        $amount = $this->amount ?? $payable?->getPayableAmount();

        if ($reference === null || $amount === null) {
            throw new LogicException(
                'Charging without a payable requires reference() and amount() on the pending payment.',
            );
        }

        return new PaymentRequest(
            reference: $reference,
            amount: $amount,
            currency: $this->currency
                ?? $payable?->getPayableCurrency()
                ?? config('payline.currency', 'TRY'),
            customerEmail: $this->customerEmail ?? $payable?->getPayableCustomerEmail(),
            customerName: $this->customerName ?? $payable?->getPayableCustomerName(),
            customerPhone: $this->customerPhone,
            customerIp: $this->customerIp,
            description: $this->description ?? $payable?->getPayableDescription(),
            method: $this->method,
            card: $this->card,
            cardToken: $this->cardToken,
            saveCard: $this->saveCard,
            threeDs: $this->threeDs,
            installments: $this->installments,
            callbackUrl: $this->callbackUrl,
            basketItems: $this->basketItems,
            billingAddress: $this->billingAddress,
            shippingAddress: $this->shippingAddress,
            metadata: $this->metadata,
            cardProfile: $this->cardProfile,
            idempotencyKey: $this->idempotencyKey,
        );
    }
}
