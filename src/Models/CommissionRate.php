<?php

namespace XLaravel\Payline\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommissionRate extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'payline_commission_rates';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'blocking_days' => 'integer',
        ];
    }

    public function scopeForGateway($query, string $gateway)
    {
        return $query->where('gateway', $gateway);
    }
}