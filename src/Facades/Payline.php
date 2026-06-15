<?php

namespace XLaravel\Payline\Facades;

use Illuminate\Support\Facades\Facade;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\PendingPayment;
use XLaravel\Payline\PendingPaymentBuilder;
use XLaravel\Payline\PaylineManager;

/**
 * @method static Gateway driver(?string $driver = null)
 * @method static PendingPayment via(?string $driver = null)
 * @method static PendingPaymentBuilder for(Payable $payable)
 * @method static PaylineManager extend(string $driver, \Closure $callback)
 *
 * @see PaylineManager
 */
class Payline extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'payline';
    }
}
