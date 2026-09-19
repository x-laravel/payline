<?php

namespace XLaravel\Payline\DTOs;

use InvalidArgumentException;
use JsonSerializable;
use SensitiveParameter;
use XLaravel\Payline\BinLookupManager;

readonly class Card implements JsonSerializable
{
    public function __construct(
        public string $holderName,
        #[SensitiveParameter]
        public string $number,
        public string $expiryMonth,
        public string $expiryYear,
        #[SensitiveParameter]
        public string $cvv,
        public ?CardProfile $profile = null,
    ) {
        if (! preg_match('/^\d{13,19}$/', $this->number)) {
            throw new InvalidArgumentException('Card number must contain 13 to 19 digits.');
        }

        if (! preg_match('/^(0[1-9]|1[0-2])$/', $this->expiryMonth)) {
            throw new InvalidArgumentException('Card expiry month is invalid.');
        }

        if (! preg_match('/^\d{2,4}$/', $this->expiryYear)) {
            throw new InvalidArgumentException('Card expiry year is invalid.');
        }

        if (! preg_match('/^\d{3,4}$/', $this->cvv)) {
            throw new InvalidArgumentException('Card security code must contain three or four digits.');
        }
    }

    public function resolveProfile(BinLookupManager $manager): self
    {
        $profile = $manager->lookup($this->number);
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
        return $this->safeData();
    }

    public function jsonSerialize(): array
    {
        return $this->safeData();
    }

    private function safeData(): array
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

    public function bin(): string
    {
        return substr($this->number, 0, 8);
    }

    public function lastFour(): string
    {
        return substr($this->number, -4);
    }

    public function maskedNumber(): string
    {
        return $this->bin() . str_repeat('*', strlen($this->number) - 12) . $this->lastFour();
    }
}
