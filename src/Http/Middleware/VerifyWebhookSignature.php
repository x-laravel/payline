<?php

namespace XLaravel\Payline\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use XLaravel\Payline\Contracts\HandlesRawWebhooks;
use XLaravel\Payline\Contracts\HandlesWebhooks;
use XLaravel\Payline\DTOs\IncomingNotification;
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

        $gatewayInstance = $this->manager->driver($gateway);
        $notification = IncomingNotification::fromRequest($request, $gateway);

        if ($gatewayInstance instanceof HandlesRawWebhooks) {
            $verified = $gatewayInstance->verifyIncomingNotification($notification);
        } elseif ($gatewayInstance instanceof HandlesWebhooks || method_exists($gatewayInstance, 'verifyWebhook')) {
            $verified = $gatewayInstance->verifyWebhook($notification->payload(), $notification->signature);
        } else {
            throw new WebhookSignatureException("Gateway [{$gateway}] does not support webhooks.");
        }

        if (! $verified) {
            throw new WebhookSignatureException("Invalid webhook signature for gateway [{$gateway}].");
        }

        return $next($request);
    }
}
