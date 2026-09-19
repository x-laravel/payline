<?php

namespace XLaravel\Payline\Routing;

use Illuminate\Contracts\Container\Container;
use LogicException;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\GatewayRoutingPolicy;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\Enums\TransactionType;

class GatewayPolicyPipeline
{
    public function __construct(private readonly Container $container) {}

    public function allows(Gateway $gateway, PaymentRequest $request, TransactionType $operation): bool
    {
        foreach (config('payline.routing.policies', []) as $policyClass) {
            $policy = $this->container->make($policyClass);

            if (! $policy instanceof GatewayRoutingPolicy) {
                throw new LogicException("Routing policy [{$policyClass}] must implement GatewayRoutingPolicy.");
            }

            if (! $policy->allows($gateway, $request, $operation)) {
                return false;
            }
        }

        return true;
    }
}
