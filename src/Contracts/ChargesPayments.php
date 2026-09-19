<?php

namespace XLaravel\Payline\Contracts;

use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\PaymentResponse;

interface ChargesPayments
{
    public function pay(PaymentRequest $data): PaymentResponse;
}
