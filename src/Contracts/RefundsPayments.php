<?php

namespace XLaravel\Payline\Contracts;

use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\DTOs\RefundData;

interface RefundsPayments
{
    public function refund(RefundData $data): PaymentResponse;
}
