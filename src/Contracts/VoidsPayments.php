<?php

namespace XLaravel\Payline\Contracts;

use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\DTOs\VoidData;

interface VoidsPayments
{
    public function void(VoidData $data): PaymentResponse;
}
