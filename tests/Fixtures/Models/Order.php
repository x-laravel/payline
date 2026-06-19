<?php

namespace XLaravel\Payline\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\Traits\HasPayline;

class Order extends Model implements Payable
{
    use HasPayline;

    protected $fillable = ['reference', 'amount', 'currency', 'customer_email', 'customer_name'];

    public function getPayableReference(): string
    {
        return $this->reference;
    }

    public function getPayableAmount(): int
    {
        return $this->amount;
    }

    public function getPayableCurrency(): string
    {
        return $this->currency ?? 'TRY';
    }

    public function getPayableCustomerEmail(): ?string
    {
        return $this->customer_email;
    }

    public function getPayableCustomerName(): ?string
    {
        return $this->customer_name;
    }

    public function getPayableDescription(): ?string
    {
        return null;
    }
}
