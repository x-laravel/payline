<?php

namespace XLaravel\Payline\DTOs;

readonly class Card
{
    public function __construct(
        public string $holderName,
        public string $number,
        public string $expiryMonth,
        public string $expiryYear,
        public string $cvv,
        public ?CardProfile $profile = null,
    ) {}

    public function resolveProfile(): self
    {
        $profile = app(\XLaravel\Payline\BinLookupManager::class)->lookup($this->number);
        return $profile !== null ? $this->withProfile($profile) : $this;
    }

    public function withProfile(CardProfile $profile): self
    {
        return new self(
            holderName: $this->holderName,
            number: $this->number,
            expiryMonth: $this->expiryMonth,
            expiryYear: $this->expiryYear,
            cvv: $this->cvv,
            profile: $profile,
        );
    }

    public function __debugInfo(): array
    {
        return [
            'holderName' => $this->holderName,
            'number' => $this->maskedNumber(),
            'expiryMonth' => $this->expiryMonth,
            'expiryYear' => $this->expiryYear,
            'cvv' => '***',
            'profile' => $this->profile,
        ];
    }

    public function lastFour(): string
    {
        return substr($this->number, -4);
    }

    public function maskedNumber(): string
    {
        return str_repeat('*', strlen($this->number) - 4) . $this->lastFour();
    }
}
