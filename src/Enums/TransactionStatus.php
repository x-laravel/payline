<?php

namespace XLaravel\Payline\Enums;

enum TransactionStatus: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Successful = 'successful';
    case Failed = 'failed';
    case Voided = 'voided';
    case Expired = 'expired';
    case Unknown = 'unknown';

    public function isFinal(): bool
    {
        return in_array($this, [
            self::Authorized,
            self::Successful,
            self::Failed,
            self::Voided,
            self::Expired,
        ], true);
    }

    public function canTransitionTo(self $to): bool
    {
        if ($this === $to) {
            return true;
        }

        return match ($this) {
            self::Initiated, self::Unknown => true,
            self::Pending => in_array($to, [
                self::Authorized,
                self::Successful,
                self::Failed,
                self::Expired,
                self::Voided,
                self::Unknown,
            ], true),
            self::Authorized,
            self::Successful,
            self::Failed,
            self::Expired,
            self::Voided => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Initiated',
            self::Pending => 'Pending',
            self::Authorized => 'Authorized',
            self::Successful => 'Successful',
            self::Failed => 'Failed',
            self::Voided => 'Voided',
            self::Expired => 'Expired',
            self::Unknown => 'Unknown',
        };
    }
}
