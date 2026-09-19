<?php

namespace XLaravel\Payline\Enums;

enum PaymentStatus: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Authorized = 'authorized';
    case PartiallyCaptured = 'partially_captured';
    case Paid = 'successful';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Voided = 'voided';
    case Failed = 'failed';
    case Expired = 'expired';
    case Unknown = 'unknown';

    public function wasSuccessful(): bool
    {
        return in_array($this, self::successful(), true);
    }

    public function hasOutstandingAmount(): bool
    {
        return in_array($this, self::outstanding(), true);
    }

    public function isPending(): bool
    {
        return in_array($this, self::pending(), true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [
            self::Paid,
            self::Refunded,
            self::Voided,
            self::Failed,
            self::Expired,
        ], true);
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Unknown, self::Initiated => true,
            self::Pending => in_array($to, [
                self::Authorized,
                self::Paid,
                self::Failed,
                self::Expired,
                self::Unknown,
            ], true),
            self::Authorized => in_array($to, [
                self::PartiallyCaptured,
                self::Paid,
                self::Voided,
                self::Unknown,
            ], true),
            self::PartiallyCaptured => in_array($to, [
                self::Paid,
                self::PartiallyRefunded,
                self::Refunded,
                self::Unknown,
            ], true),
            self::Paid => in_array($to, [
                self::PartiallyRefunded,
                self::Refunded,
                self::Unknown,
            ], true),
            self::PartiallyRefunded => in_array($to, [
                self::Refunded,
                self::Unknown,
            ], true),
            self::Failed,
            self::Expired => in_array($to, [
                self::Pending,
                self::Authorized,
                self::Paid,
            ], true),
            self::Refunded,
            self::Voided => false,
        };
    }

    /** @return array<int, self> */
    public static function successful(): array
    {
        return [self::PartiallyCaptured, self::Paid, self::PartiallyRefunded, self::Refunded];
    }

    /** @return array<int, self> */
    public static function outstanding(): array
    {
        return [self::PartiallyCaptured, self::Paid, self::PartiallyRefunded];
    }

    /** @return array<int, self> */
    public static function pending(): array
    {
        return [self::Initiated, self::Pending];
    }

    /** @return array<int, self> */
    public static function requiresReconciliation(): array
    {
        return [self::Pending, self::Unknown];
    }

    /**
     * @param  array<int, self>  $statuses
     * @return array<int, string>
     */
    public static function valuesOf(array $statuses): array
    {
        return array_column($statuses, 'value');
    }
}
