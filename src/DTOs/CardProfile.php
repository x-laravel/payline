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
