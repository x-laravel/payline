<?php

namespace XLaravel\Payline\Contracts;

use XLaravel\Payline\DTOs\CallbackResult;

interface CallbackRedirectResolver
{
    public function resolve(string $gateway, CallbackResult $result): string;
}
