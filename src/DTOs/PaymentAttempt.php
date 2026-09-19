<?php

namespace XLaravel\Payline\DTOs;

use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;

readonly class PaymentAttempt
{
    public function __construct(
        public Payment $payment,
        public Transaction $transaction,
        public bool $created,
    ) {}
}
