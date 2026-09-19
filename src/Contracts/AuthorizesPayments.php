<?php

namespace XLaravel\Payline\Contracts;

use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\PaymentResponse;

interface AuthorizesPayments
{
    public function authorize(PaymentRequest $data): PaymentResponse;
}
