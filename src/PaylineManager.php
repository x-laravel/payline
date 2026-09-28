<?php

namespace XLaravel\Payline;

use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use RuntimeException;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Gateway\GatewayInvoker;
use XLaravel\Payline\Gateway\GatewayResolver;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Payments\TransactionRunner;
use XLaravel\Payline\Routing\GatewayRouter;

class PaylineManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('payline.default')
            ?? throw new RuntimeException(
                'No default Payline gateway configured. Set PAYLINE_DRIVER or payline.default in config.',
            );
    }

    /**
     * Returns the raw gateway instance. No transaction recording occurs.
     * Intended for driver package development and advanced usage.
     */
    public function driver($driver = null): Gateway
    {
        /** @var Gateway $instance */
        $instance = parent::driver($driver);

        return $instance;
    }

    public function hasDriver(string $driver): bool
    {
        return isset($this->customCreators[$driver])
            || method_exists($this, 'create' . Str::studly($driver) . 'Driver');
    }

    /** @return string[] */
    public function registeredDrivers(): array
    {
        return array_keys($this->customCreators);
    }

    public function via(?string $driver = null): PendingPayment
    {
        return $this->pendingPayment(
            $driver !== null ? $this->driver($driver) : null,
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
        );
    }

    public function cheapestFor(CardProfile $profile, int $installments = 1): ?string
    {
        return $this->container->make(GatewayRouter::class)->cheapestFor($profile, $installments);
    }

    /**
     * Driver factories receive the gateway config as their second argument.
     */
    protected function callCustomCreator($driver): Gateway
    {
        return $this->customCreators[$driver](
            $this->container,
            $this->config->get("payline.gateways.{$driver}", []),
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
