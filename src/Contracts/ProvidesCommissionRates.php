<?php

namespace XLaravel\Payline\Contracts;

use XLaravel\Payline\DTOs\CommissionRateData;

interface ProvidesCommissionRates
{
    /** @return CommissionRateData[] */
    public function commissionRates(): array;
}
