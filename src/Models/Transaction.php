<?php

namespace XLaravel\Payline\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use XLaravel\Payline\Concerns\UsesPaylineConnection;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;

class Transaction extends Model
{
    use HasUlids, UsesPaylineConnection;

    protected $table = 'payline_transactions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => TransactionStatus::class,
            'type' => TransactionType::class,
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(
            config('payline.models.payment', Payment::class),
            'payment_id',
        );
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_transaction_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_transaction_id');
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

    public function scopeOfType($query, TransactionType $type)
    {
        return $query->where('type', $type->value);
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
}