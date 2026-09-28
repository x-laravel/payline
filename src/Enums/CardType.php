<?php

namespace XLaravel\Payline\Enums;

enum CardType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case Prepaid = 'prepaid';
    case Charge = 'charge';

    public static function parse(?string $value): ?self
    {
        return match (preg_replace('/[^a-z]/', '', mb_strtolower((string) $value, 'UTF-8'))) {
            'credit', 'creditcard' => self::Credit,
            'debit', 'debitcard' => self::Debit,
            'prepaid', 'prepaidcard' => self::Prepaid,
            'charge', 'chargecard' => self::Charge,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Credit Card',
            self::Debit => 'Debit Card',
            self::Prepaid => 'Prepaid Card',
            self::Charge => 'Charge Card',
        };
    }
}
