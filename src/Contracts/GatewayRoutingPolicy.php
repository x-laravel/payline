<?php

namespace XLaravel\Payline\Contracts;

use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\Enums\TransactionType;

interface GatewayRoutingPolicy
{
    public function allows(Gateway $gateway, PaymentRequest $request, TransactionType $operation): bool;
}
