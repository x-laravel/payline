<?php

namespace XLaravel\Payline\Tests\Fixtures\Gateways;

use RuntimeException;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\ProvidesCommissionRates;
use XLaravel\Payline\DTOs\CommissionRateData;

class RatedGateway implements Gateway, ProvidesCommissionRates
{
    /** @var CommissionRateData[] */
    private static array $rates = [];

    private static bool $throws = false;

    /** @param CommissionRateData[] $rates */
    public static function willReturn(array $rates): void
    {
        self::$rates = $rates;
        self::$throws = false;
    }

    public static function willFail(): void
    {
        self::$throws = true;
    }

    public static function reset(): void
    {
        self::$rates = [];
        self::$throws = false;
    }

    public function commissionRates(): array
    {
        if (self::$throws) {
            throw new RuntimeException('The provider refused the rate listing.');
        }

        return self::$rates;
    }

    public function getName(): string
    {
        return 'rated';
    }
}
