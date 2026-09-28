<?php

namespace XLaravel\Payline;

use XLaravel\Payline\Contracts\Payable;

class PendingPaymentBuilder
{
    public function __construct(
        protected readonly PaylineManager $manager,
        protected readonly Payable $payable,
    ) {}

    public function via(?string $gateway = null): PendingPayment
    {
        return $this->manager->via($gateway)->for($this->payable);
    }
}
