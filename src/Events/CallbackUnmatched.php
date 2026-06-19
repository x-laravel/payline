<?php

namespace XLaravel\Payline\Events;

use Illuminate\Foundation\Events\Dispatchable;
use XLaravel\Payline\DTOs\PaymentResponse;

class CallbackUnmatched
{
    use Dispatchable;

    public function __construct(
        public readonly string $gateway,
        public readonly PaymentResponse $response,
    ) {}
}
