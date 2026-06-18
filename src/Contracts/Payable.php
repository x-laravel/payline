<?php

namespace XLaravel\Payline\Contracts;

interface Payable
{
    public function getPayableReference(): string;

    public function getPayableAmount(): int;

    public function getPayableCurrency(): string;

    public function getPayableCustomerEmail(): ?string;

    public function getPayableCustomerName(): ?string;

    public function getPayableDescription(): ?string;
}
