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
            self::Initiated => 'Başlatıldı',
            self::Pending => 'Beklemede',
            self::Authorized => 'Yetkilendirildi',
            self::Successful => 'Başarılı',
            self::Failed => 'Başarısız',
            self::Refunded => 'İade Edildi',
            self::PartiallyRefunded => 'Kısmi İade',
            self::Voided => 'İptal Edildi',
            self::Expired => 'Süresi Doldu',
        };
    }
}
