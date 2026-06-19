<?php

namespace XLaravel\Payline\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use XLaravel\Payline\Concerns\UsesPaylineConnection;
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
            'status' => TransactionStatus::class,
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
            config('payline.transaction_model', Transaction::class),
            'payment_id',
        );
    }

    public function latestTransaction(): HasOne
    {
        return $this->hasOne(
            config('payline.transaction_model', Transaction::class),
            'payment_id',
        )->latestOfMany();
    }

    public function successfulTransaction(): HasOne
    {
        return $this->hasOne(
            config('payline.transaction_model', Transaction::class),
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
        return $query->where('status', TransactionStatus::Successful->value);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', [
            TransactionStatus::Initiated->value,
            TransactionStatus::Pending->value,
        ]);
    }

    public function scopeForGateway($query, string $gateway)
    {
        return $query->where('gateway', $gateway);
    }

    public function isSuccessful(): bool
    {
        return $this->status === TransactionStatus::Successful;
    }

    public function isPending(): bool
    {
        return in_array($this->status, [
            TransactionStatus::Initiated,
            TransactionStatus::Pending,
        ]);
    }

    public function totalRefunded(): int
    {
        return (int) $this->refunds()
            ->where('status', TransactionStatus::Refunded->value)
            ->sum('amount');
    }

    public function remainingRefundable(): int
    {
        return max(0, $this->amount - $this->totalRefunded());
    }

    public function nextAttemptNumber(): int
    {
        return $this->transactions()->max('attempt') + 1;
    }
}