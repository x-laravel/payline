<?php

namespace XLaravel\Payline\Contracts;

use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\DTOs\PaymentResponse;

interface HandlesCallbacks
{
    public function handleCallback(CallbackData $data): PaymentResponse;
}
