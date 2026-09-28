<?php

namespace XLaravel\Payline\Enums;

enum CardScheme: string
{
    case Visa = 'visa';
    case Mastercard = 'mastercard';
    case Amex = 'amex';
    case Troy = 'troy';
    case Discover = 'discover';
    case Diners = 'diners';
    case Jcb = 'jcb';
    case UnionPay = 'unionpay';
    case Maestro = 'maestro';

    public static function parse(?string $value): ?self
    {
        return match (self::normalise($value)) {
            'visa' => self::Visa,
            'mastercard', 'master', 'mc' => self::Mastercard,
            'amex', 'americanexpress' => self::Amex,
            'troy' => self::Troy,
            'discover' => self::Discover,
            'diners', 'dinersclub' => self::Diners,
            'jcb' => self::Jcb,
            'unionpay', 'chinaunionpay', 'cup' => self::UnionPay,
            'maestro' => self::Maestro,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Visa => 'Visa',
            self::Mastercard => 'Mastercard',
            self::Amex => 'American Express',
            self::Troy => 'Troy',
            self::Discover => 'Discover',
            self::Diners => 'Diners Club',
            self::Jcb => 'JCB',
            self::UnionPay => 'UnionPay',
            self::Maestro => 'Maestro',
        };
    }

    private static function normalise(?string $value): string
    {
        return preg_replace('/[^a-z]/', '', mb_strtolower((string) $value, 'UTF-8'));
    }
}
