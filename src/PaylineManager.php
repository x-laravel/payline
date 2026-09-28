<?php

namespace XLaravel\Payline;

use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use RuntimeException;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Dispatch\GatewayInvoker;
use XLaravel\Payline\Dispatch\GatewayResolver;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Payments\FollowUpReconciler;
use XLaravel\Payline\Payments\TransactionRunner;
use XLaravel\Payline\Routing\GatewayRouter;

class PaylineManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('payline.default')
            ?? throw new RuntimeException(
                'No default Payline gateway configured. Set PAYLINE_GATEWAY or payline.default in config.',
            );
    }

    /**
     * Returns the raw gateway instance. No transaction recording occurs.
     * Intended for gateway package development and advanced usage.
     */
    public function gateway(?string $name = null): Gateway
    {
        /** @var Gateway $instance */
        $instance = parent::driver($name);

        return $instance;
    }

    public function driver($driver = null): Gateway
    {
        return $this->gateway($driver);
    }

    public function hasGateway(string $name): bool
    {
        return isset($this->customCreators[$name])
            || method_exists($this, 'create' . Str::studly($name) . 'Driver');
    }

    /** @return string[] */
    public function registeredGateways(): array
    {
        return array_keys($this->customCreators);
    }

    public function testMode(): bool
    {
        return (bool) $this->config->get('payline.test_mode', false);
    }

    public function via(?string $gateway = null): PendingPayment
    {
        return $this->pendingPayment(
            $gateway !== null ? $this->gateway($gateway) : null,
            autoRoute: false,
        );
    }

    public function viaAuto(): PendingPayment
    {
        return $this->pendingPayment(null, autoRoute: true);
    }

    public function for(Payable $payable): PendingPaymentBuilder
    {
        return new PendingPaymentBuilder($this, $payable);
    }

    public function payment(Payment $payment): PaymentOperations
    {
        return new PaymentOperations(
            payment: $payment,
            recorder: $this->container->make(TransactionRecorder::class),
            validator: $this->container->make(PaymentOperationValidator::class),
            resolver: $this->container->make(GatewayResolver::class),
            invoker: $this->container->make(GatewayInvoker::class),
            runner: $this->container->make(TransactionRunner::class),
            followUps: $this->container->make(FollowUpReconciler::class),
        );
    }

    public function cheapestFor(CardProfile $profile, int $installments = 1): ?string
    {
        return $this->container->make(GatewayRouter::class)->cheapestFor($profile, $installments);
    }

    /**
     * Gateway factories receive the gateway config as their second argument.
     */
    protected function callCustomCreator($driver): Gateway
    {
        return $this->customCreators[$driver](
            $this->container,
            [
                'test_mode' => $this->testMode(),
                ...$this->config->get("payline.gateways.{$driver}", []),
            ],
        );
    }

    private function pendingPayment(?Gateway $gateway, bool $autoRoute): PendingPayment
    {
        return new PendingPayment(
            gateway: $gateway,
            recorder: $this->container->make(TransactionRecorder::class),
            resolver: $this->container->make(GatewayResolver::class),
            invoker: $this->container->make(GatewayInvoker::class),
            runner: $this->container->make(TransactionRunner::class),
            autoRoute: $autoRoute,
        );
    }
}
