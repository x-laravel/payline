<?php

namespace XLaravel\Payline\Gateway;

use LogicException;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\ProvidesGatewayCapabilities;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\PaylineManager;
use XLaravel\Payline\Routing\GatewayPolicyPipeline;
use XLaravel\Payline\Routing\GatewayRouter;

class GatewayResolver
{
    public function __construct(
        private readonly PaylineManager $manager,
        private readonly GatewayRouter $router,
        private readonly GatewayPolicyPipeline $policies,
        private readonly GatewayInvoker $invoker,
    ) {}

    public function forRequest(
        PaymentRequest $data,
        TransactionType $type,
        ?Gateway $preferred,
        bool $autoRoute,
    ): Gateway {
        if ($preferred !== null) {
            $this->assertSupports($preferred, $data, $type);

            return $preferred;
        }

        $profile = $data->card?->profile ?? $data->cardProfile;

        if ($autoRoute && $profile !== null) {
            foreach (array_keys($this->router->rankedFor($profile, $data->installments ?? 1)) as $driver) {
                if (! $this->manager->hasDriver($driver)) {
                    continue;
                }

                $gateway = $this->manager->driver($driver);

                if ($this->supports($gateway, $data, $type)) {
                    return $gateway;
                }
            }
        }

        $gateway = $this->manager->driver();
        $this->assertSupports($gateway, $data, $type);

        return $gateway;
    }

    public function forPayment(Payment $payment, ?Gateway $preferred = null): Gateway
    {
        if ($preferred === null) {
            return $this->manager->driver($payment->gateway);
        }

        if ($preferred->getName() !== $payment->gateway) {
            throw new LogicException(sprintf(
                'Follow-up operations must use the payment gateway [%s], [%s] given.',
                $payment->gateway,
                $preferred->getName(),
            ));
        }

        return $preferred;
    }

    public function supports(Gateway $gateway, PaymentRequest $data, TransactionType $type): bool
    {
        if (! $this->invoker->supports($gateway, $type)) {
            return false;
        }

        if ($gateway instanceof ProvidesGatewayCapabilities
            && ! $gateway->capabilities()->supports($data, $type)) {
            return false;
        }

        return $this->policies->allows($gateway, $data, $type);
    }

    private function assertSupports(Gateway $gateway, PaymentRequest $data, TransactionType $type): void
    {
        if (! $this->supports($gateway, $data, $type)) {
            throw new LogicException(sprintf(
                'Gateway [%s] does not support this %s request.',
                $gateway->getName(),
                $type->value,
            ));
        }
    }
}
