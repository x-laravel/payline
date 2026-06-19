<?php

namespace XLaravel\Payline\DTOs;

readonly class Address
{
    public function __construct(
        public string $name,
        public string $line1,
        public ?string $line2 = null,
        public string $city = '',
        public ?string $state = null,
        public string $country = '',
        public ?string $zipCode = null,
        public ?string $phone = null,
    ) {}
}
