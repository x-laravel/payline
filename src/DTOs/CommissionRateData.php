<?php

namespace XLaravel\Payline\DTOs;

use InvalidArgumentException;
use XLaravel\Payline\Enums\CardType;

readonly class CommissionRateData
{
    public function __construct(
        public float $rate,
        public int $installments = 1,
        public ?string $cardFamily = null,
        public ?CardType $cardType = null,
        public ?int $blockingDays = null,
    ) {
        if ($this->rate < 0) {
            throw new InvalidArgumentException('A commission rate cannot be negative.');
        }

        if ($this->installments < 1) {
            throw new InvalidArgumentException('A commission rate needs an installment count of at least one.');
        }
    }

    public function key(string $gateway): array
    {
        return [
            'gateway' => $gateway,
            'card_family' => $this->cardFamily,
            'card_type' => $this->cardType?->value,
            'installments' => $this->installments,
        ];
    }

    public function values(): array
    {
        return [
            'rate' => $this->rate,
            'blocking_days' => $this->blockingDays,
        ];
    }
}
