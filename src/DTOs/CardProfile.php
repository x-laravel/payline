<?php

namespace XLaravel\Payline\DTOs;

use XLaravel\Payline\Enums\CardCategory;
use XLaravel\Payline\Enums\CardScheme;
use XLaravel\Payline\Enums\CardType;

readonly class CardProfile
{
    /**
     * @param  CardScheme[]  $localSchemes
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $bin = null,
        public ?CardScheme $scheme = null,
        public array $localSchemes = [],
        public ?CardType $type = null,
        public ?CardCategory $category = null,
        public ?string $family = null,
        public ?string $productId = null,
        public ?string $productType = null,
        public ?string $issuer = null,
        public ?string $issuerCode = null,
        public ?string $issuerCountry = null,
        public ?string $currency = null,
        public ?bool $prepaid = null,
        public ?int $numberLength = null,
        public ?string $source = null,
        public array $raw = [],
    ) {}

    /**
     * Returns a profile carrying this one's fields, with every field it leaves
     * open filled from the other. Nothing already known is overwritten.
     */
    public function mergedWith(?CardProfile $other): self
    {
        if ($other === null) {
            return $this;
        }

        return new self(
            bin: $this->bin ?? $other->bin,
            scheme: $this->scheme ?? $other->scheme,
            localSchemes: $this->localSchemes !== [] ? $this->localSchemes : $other->localSchemes,
            type: $this->type ?? $other->type,
            category: $this->category ?? $other->category,
            family: $this->family ?? $other->family,
            productId: $this->productId ?? $other->productId,
            productType: $this->productType ?? $other->productType,
            issuer: $this->issuer ?? $other->issuer,
            issuerCode: $this->issuerCode ?? $other->issuerCode,
            issuerCountry: $this->issuerCountry ?? $other->issuerCountry,
            currency: $this->currency ?? $other->currency,
            prepaid: $this->prepaid ?? $other->prepaid,
            numberLength: $this->numberLength ?? $other->numberLength,
            source: $this->source ?? $other->source,
            raw: $this->raw + $other->raw,
        );
    }

    public function isCoBadged(): bool
    {
        return $this->localSchemes !== [];
    }

    public function issuedIn(string $country): bool
    {
        return $this->issuerCountry === $country;
    }

    public function issuedOutside(string $country): bool
    {
        return $this->issuerCountry !== null && $this->issuerCountry !== $country;
    }

    public function supports(CardScheme $scheme): bool
    {
        return $this->scheme === $scheme || in_array($scheme, $this->localSchemes, true);
    }
}
