<?php

namespace XLaravel\Payline\Events;

use Illuminate\Foundation\Events\Dispatchable;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;

class PaymentCaptured
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $payment,
        public readonly Transaction $transaction,
        public readonly PaymentResponse $response,
    ) {}
}