<?php

namespace XLaravel\Payline\Enums;

use XLaravel\Payline\Contracts\AuthorizesPayments;
use XLaravel\Payline\Contracts\CapturesPayments;
use XLaravel\Payline\Contracts\ChargesPayments;
use XLaravel\Payline\Contracts\RefundsPayments;
use XLaravel\Payline\Contracts\VoidsPayments;

enum TransactionType: string
{
    case Payment = 'payment';
    case Authorization = 'authorization';
    case Capture = 'capture';
    case Refund = 'refund';
    case Void = 'void';

    /** @return class-string */
    public function gatewayContract(): string
    {
        return match ($this) {
            self::Payment => ChargesPayments::class,
            self::Authorization => AuthorizesPayments::class,
            self::Capture => CapturesPayments::class,
            self::Refund => RefundsPayments::class,
            self::Void => VoidsPayments::class,
        };
    }

    public function gatewayMethod(): string
    {
        return match ($this) {
            self::Payment => 'pay',
            self::Authorization => 'authorize',
            self::Capture => 'capture',
            self::Refund => 'refund',
            self::Void => 'void',
        };
    }
}
