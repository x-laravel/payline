<?php

namespace XLaravel\Payline\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use XLaravel\Payline\Concerns\UsesPaylineConnection;
use XLaravel\Payline\Facades\Payline;
use XLaravel\Payline\Enums\PaymentStatus;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;

class Payment extends Model
{
    use HasUlids, UsesPaylineConnection;

    protected $table = 'payline_payments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'initial_type' => TransactionType::class,
            'metadata' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(
            Payline::transactionModel(),
            'payment_id',
        );
    }

    public function latestTransaction(): HasOne
    {
        return $this->hasOne(
            Payline::transactionModel(),
            'payment_id',
        )->latestOfMany();
    }

    public function successfulTransaction(): HasOne
    {
        return $this->hasOne(
            Payline::transactionModel(),
            'payment_id',
        )->ofMany(
            ['id' => 'max'],
            fn ($q) => $q
                ->where('status', TransactionStatus::Successful->value)
                ->where('type', TransactionType::Payment->value)
        );
    }

    public function refunds(): HasMany
    {
        return $this->transactions()
            ->where('type', TransactionType::Refund->value);
    }

    public function scopeSuccessful($query)
    {
        return $query->whereIn('status', PaymentStatus::valuesOf(PaymentStatus::successful()));
    }

    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', PaymentStatus::valuesOf(PaymentStatus::outstanding()));
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', PaymentStatus::valuesOf(PaymentStatus::pending()));
    }

    public function scopeRequiresReconciliation($query)
    {
        return $query->whereIn('status', PaymentStatus::valuesOf(PaymentStatus::requiresReconciliation()));
    }

    public function scopeForGateway($query, string $gateway)
    {
        return $query->where('gateway', $gateway);
    }

    public function wasSuccessful(): bool
    {
        return $this->status->wasSuccessful();
    }

    public function hasOutstandingAmount(): bool
    {
        return $this->status->hasOutstandingAmount();
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function capturedAmount(): int
    {
        return (int) $this->transactions()
            ->whereIn('type', [TransactionType::Payment->value, TransactionType::Capture->value])
            ->where('status', TransactionStatus::Successful->value)
            ->sum('amount');
    }

    public function totalRefunded(): int
    {
        return (int) $this->refunds()
            ->where('status', TransactionStatus::Successful->value)
            ->sum('amount');
    }

    public function remainingRefundable(): int
    {
        return max(0, $this->capturedAmount() - $this->totalRefunded());
    }

    public function nextAttemptNumber(): int
    {
        return $this->transactions()->max('attempt') + 1;
    }

    public function maskedNumber(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->card_bin !== null && $this->card_last_four !== null
                ? $this->card_bin . '****' . $this->card_last_four
                : null,
        );
    }
}
