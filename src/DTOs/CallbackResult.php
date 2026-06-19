<?php

namespace XLaravel\Payline\DTOs;

use XLaravel\Payline\Models\Transaction;

readonly class CallbackResult
{
    public function __construct(
        public PaymentResponse $response,
        public ?Transaction $transaction,
    ) {}
}