<?php

namespace XLaravel\Payline\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;
use XLaravel\Payline\PendingPayment;
use XLaravel\Payline\PaylineManager;

/**
 * Order, Invoice, User gibi herhangi bir Eloquent modele eklenebilir.
 * Laravel Cashier Billable trait'i ile isim çakışması yoktur.
 *
 * Örnek:
 *   class User extends Model { use Billable, HasPayline; }
 *   class Order extends Model implements Payable { use HasPayline; }
 */
trait HasPayline
{
    public function payments(): MorphMany
    {
        return $this->morphMany(
            config('payline.payment_model', Payment::class),
            'payable',
        );
    }

    public function successfulPayments(): MorphMany
    {
        return $this->payments()
            ->where('status', TransactionStatus::Successful->value);
    }

    public function pendingPayments(): MorphMany
    {
        return $this->payments()
            ->whereIn('status', [
                TransactionStatus::Initiated->value,
                TransactionStatus::Pending->value,
            ]);
    }

    public function amountPaid(): int
    {
        return (int) $this->successfulPayments()->sum('amount');
    }

    public function lastPayment(): ?Payment
    {
        return $this->payments()->latest()->first();
    }

    /**
     * $order->payWith('iyzico')->pay($data)
     * Model Payable implemente ediyorsa otomatik olarak payable olarak bağlanır.
     */
    public function payWith(?string $driver = null): PendingPayment
    {
        /** @var PaylineManager $manager */
        $manager = app('payline');
        $pending = $manager->via($driver);

        if ($this instanceof \XLaravel\Payline\Contracts\Payable) {
            $pending->for($this);
        }

        return $pending;
    }

    // --- Payable interface için default uygulamalar ---
    // Model bunları override etmezse kullanılır.

    public function getPayableCurrency(): string
    {
        return 'TRY';
    }

    public function getPayableCustomerEmail(): ?string
    {
        return $this->getAttribute('email');
    }

    public function getPayableCustomerName(): ?string
    {
        return $this->getAttribute('name');
    }

    public function getPayableDescription(): ?string
    {
        return null;
    }
}