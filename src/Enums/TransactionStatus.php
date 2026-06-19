<?php

namespace XLaravel\Payline\Enums;

enum TransactionStatus: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Successful = 'successful';
    case Failed = 'failed';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Voided = 'voided';
    case Expired = 'expired';

    public function isFinal(): bool
    {
        return in_array($this, [
            self::Successful,
            self::Failed,
            self::Refunded,
            self::Voided,
            self::Expired,
        ]);
    }

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Initiated',
            self::Pending => 'Pending',
            self::Authorized => 'Authorized',
            self::Successful => 'Successful',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
            self::PartiallyRefunded => 'Partially Refunded',
            self::Voided => 'Voided',
            self::Expired => 'Expired',
        };
    }
}
