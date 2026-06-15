<?php

namespace XLaravel\Payline\Enums;

enum Currency: string
{
    case TRY = 'TRY';
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';

    public function symbol(): string
    {
        return match ($this) {
            self::TRY => '₺',
            self::USD => '$',
            self::EUR => '€',
            self::GBP => '£',
        };
    }
}
