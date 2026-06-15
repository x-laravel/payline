<?php

namespace XLaravel\Payline\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use XLaravel\Payline\Exceptions\WebhookSignatureException;
use XLaravel\Payline\PaylineManager;

class VerifyWebhookSignature
{
    public function __construct(protected readonly PaylineManager $manager) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $gateway = $request->route('gateway');

        if (! $gateway) {
            throw new WebhookSignatureException('Gateway not specified in route.');
        }

        $signature = $request->header('X-Webhook-Signature')
            ?? $request->header('X-Gateway-Signature')
            ?? '';

        $gatewayInstance = $this->manager->driver($gateway);

        if (! $gatewayInstance->verifyWebhook($request->all(), $signature)) {
            throw new WebhookSignatureException("Invalid webhook signature for gateway [{$gateway}].");
        }

        return $next($request);
    }
}
