<?php

namespace XLaravel\Payline\Enums;

enum CardCategory: string
{
    case Consumer = 'consumer';
    case Commercial = 'commercial';

    public static function parse(?string $value): ?self
    {
        return match (preg_replace('/[^a-z]/', '', mb_strtolower((string) $value, 'UTF-8'))) {
            'consumer', 'personal', 'individual', 'classic' => self::Consumer,
            'commercial', 'business', 'corporate' => self::Commercial,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Consumer => 'Consumer Card',
            self::Commercial => 'Commercial Card',
        };
    }
}
