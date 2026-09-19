<?php

namespace XLaravel\Payline\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use XLaravel\Payline\Enums\PaymentStatus;
use XLaravel\Payline\Facades\Payline;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\PaylineManager;
use XLaravel\Payline\PendingPayment;

/**
 * Can be added to any Eloquent model such as Order, Invoice, User.
 * No naming conflict with Laravel Cashier's Billable trait.
 *
 * Example:
 *   class User extends Model { use Billable, HasPayline; }
 *   class Order extends Model implements Payable { use HasPayline; }
 */
trait HasPayline
{
    public function payments(): MorphMany
    {
        return $this->morphMany(
            Payline::paymentModel(),
            'payable',
        );
    }

    public function successfulPayments(): MorphMany
    {
        return $this->payments()
            ->whereIn('status', PaymentStatus::valuesOf(PaymentStatus::successful()));
    }

    public function pendingPayments(): MorphMany
    {
        return $this->payments()
            ->whereIn('status', PaymentStatus::valuesOf(PaymentStatus::pending()));
    }

    public function amountPaid(): int
    {
        return (int) $this->successfulPayments()->sum('amount');
    }

    public function amountRefunded(): int
    {
        return (int) $this->successfulPayments()
            ->withSum(
                ['refunds as refunded_amount' => fn ($query) => $query->where(
                    'status',
                    TransactionStatus::Successful->value,
                )],
                'amount',
            )
            ->get()
            ->sum(fn (Payment $payment) => min((int) $payment->amount, (int) $payment->refunded_amount));
    }

    public function amountNet(): int
    {
        return $this->amountPaid() - $this->amountRefunded();
    }

    public function lastPayment(): ?Payment
    {
        return $this->payments()->latest()->first();
    }

    /**
     * $order->pay()           → GatewayRouter selects the cheapest gateway (PaymentRequest::$cardProfile required)
     * $order->pay('iyzico')   → explicit driver
     */
    public function pay(?string $driver = null): PendingPayment
    {
        /** @var PaylineManager $manager */
        $manager = app('payline');

        $pending = $driver !== null
            ? $manager->via($driver)
            : $manager->viaAuto();

        if ($this instanceof \XLaravel\Payline\Contracts\Payable) {
            $pending->for($this);
        }

        return $pending;
    }

    // --- Default implementations for the Payable interface ---
    // Used when the model does not override these methods.

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
