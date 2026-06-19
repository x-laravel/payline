<?php

namespace XLaravel\Payline\DTOs;

readonly class BasketItem
{
    public function __construct(
        public string $id,
        public string $name,
        public string $category,
        public int $price,
        public int $quantity = 1,
    ) {}
}
