<?php

namespace XLaravel\Payline\Routing;

use XLaravel\Payline\Contracts\CallbackRedirectResolver;
use XLaravel\Payline\DTOs\CallbackResult;

class ConfigCallbackRedirectResolver implements CallbackRedirectResolver
{
    public function resolve(string $gateway, CallbackResult $result): string
    {
        $outcome = $result->response->isApproved() ? 'success' : 'failure';

        return config("payline.gateways.{$gateway}.callback_{$outcome}_url")
            ?? config("payline.callback_{$outcome}_url", '/');
    }
}
