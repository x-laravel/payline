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

    public function lastFour(): string
    {
        return substr($this->number, -4);
    }

    public function maskedNumber(): string
    {
        return str_repeat('*', strlen($this->number) - 4) . $this->lastFour();
    }
}
