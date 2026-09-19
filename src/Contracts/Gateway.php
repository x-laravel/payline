<?php

namespace XLaravel\Payline\Contracts;

interface Gateway
{
    public function supportedMethods(): array;

    public function getName(): string;
}
