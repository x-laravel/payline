<?php

namespace XLaravel\Payline\DTOs;

use XLaravel\Payline\Enums\CardType;

readonly class CardProfile
{
    public function __construct(
        public string $family,
        public CardType $type,
    ) {}
}