<?php

namespace XLaravel\Payline\Contracts;

use XLaravel\Payline\DTOs\CaptureData;
use XLaravel\Payline\DTOs\PaymentResponse;

interface CapturesPayments
{
    public function capture(CaptureData $data): PaymentResponse;
}
