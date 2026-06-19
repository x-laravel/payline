<?php

namespace XLaravel\Payline;

use Illuminate\Support\Manager;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\DTOs\CardProfile;
use XLaravel\Payline\Routing\GatewayRouter;

class PaylineManager extends Manager
{
    public function getDefaultDriver(): string
    {
        $driver = $this->config->get('payline.default');

        if (! $driver) {
            throw new \RuntimeException('No default Payline gateway configured. Set PAYLINE_DRIVER or payline.default in config.');
        }

        return $driver;
    }

    /**
     * Returns the raw gateway instance. No transaction recording occurs.
     * Intended for driver package development and advanced usage.
     *
     * Payline::driver('iyzico') → Gateway
     */
    public function driver($driver = null): Gateway
    {
        /** @var Gateway $instance */
        $instance = parent::driver($driver);
        return $instance;
    }

    /**
     * Returns a PendingPayment with transaction recording and events enabled.
     *
     * Payline::via('iyzico')->charge($data)
     */
    public function via(?string $driver = null): PendingPayment
    {
        return new PendingPayment(
            gateway: $this->driver($driver),
            recorder: $this->container->make(TransactionRecorder::class),
            manager: $this,
        );
    }

    /**
     * Returns a PendingPayment in auto-route mode.
     * The gateway is resolved via PaymentRequest::$cardProfile at charge() time.
     *
     * Payline::viaAuto()->charge($data)
     */
    public function viaAuto(): PendingPayment
    {
        return new PendingPayment(
            gateway: null,
            recorder: $this->container->make(TransactionRecorder::class),
            manager: $this,
            router: $this->container->make(GatewayRouter::class),
        );
    }

    /**
     * Facade shortcut: Payline::cheapestFor($profile, 3)
     */
    public function cheapestFor(CardProfile $profile, int $installments = 1): ?string
    {
        return $this->container->make(GatewayRouter::class)->cheapestFor($profile, $installments);
    }

    /**
     * Starts a fluent chain scoped to a Payable model.
     *
     * Payline::for($order)->via('iyzico')->charge($data)
     */
    public function for(Payable $payable): PendingPaymentBuilder
    {
        return new PendingPaymentBuilder($this, $payable);
    }

    /**
     * Automatically injects config into the driver factory.
     * Driver packages must use the ($app, array $config) signature.
     */
    protected function callCustomCreator($driver): Gateway
    {
        $config = $this->config->get("payline.gateways.{$driver}", []);
        return $this->customCreators[$driver]($this->container, $config);
    }
}
