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
        return $this->config->get('payline.default', 'hoppa');
    }

    /**
     * Raw gateway döndürür. Transaction kaydı yapılmaz.
     * Driver paket geliştirme ve ileri düzey kullanım içindir.
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
     * Transaction kaydı + event'ler etkin PendingPayment döndürür.
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
     * Auto-route modunda PendingPayment döndürür.
     * Gateway, pay($data) çağrısında PaymentRequest::$cardProfile üzerinden çözülür.
     *
     * Payline::viaAuto()->charge($data)
     */
    public function viaAuto(): PendingPayment
    {
        return new PendingPayment(
            gateway: null,
            recorder: $this->container->make(TransactionRecorder::class),
            manager: $this,
        );
    }

    /**
     * Facade kısayolu: Payline::cheapestFor($profile, 3)
     */
    public function cheapestFor(CardProfile $profile, int $installments = 1): ?string
    {
        return $this->container->make(GatewayRouter::class)->cheapestFor($profile, $installments);
    }

    /**
     * Payable model ile başlayan fluent zincir.
     *
     * Payline::for($order)->via('iyzico')->charge($data)
     */
    public function for(Payable $payable): PendingPaymentBuilder
    {
        return new PendingPaymentBuilder($this, $payable);
    }

    /**
     * Driver factory'ye config'i otomatik inject eder.
     * Driver paketleri ($app, array $config) imzasını kullanmalıdır.
     */
    protected function callCustomCreator($driver): Gateway
    {
        $config = $this->config->get("payline.gateways.{$driver}", []);
        return $this->customCreators[$driver]($this->container, $config);
    }
}
