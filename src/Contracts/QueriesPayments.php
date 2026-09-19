<?php

namespace XLaravel\Payline\Contracts;

use XLaravel\Payline\DTOs\PaymentQuery;
use XLaravel\Payline\DTOs\PaymentResponse;

interface QueriesPayments
{
    public function queryPayment(PaymentQuery $query): PaymentResponse;
}
