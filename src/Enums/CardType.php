<?php

namespace XLaravel\Payline\Enums;

enum CardType: string
{
    case Credit = 'credit';
    case Debit = 'debit';
    case ForeignCredit = 'foreign_credit';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Kredi Kartı',
            self::Debit => 'Banka Kartı',
            self::ForeignCredit => 'Yabancı Kredi Kartı',
        };
    }
}