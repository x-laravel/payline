<?php

namespace XLaravel\Payline;

use Illuminate\Support\Manager;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\Payable;

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
     * Payline::via('iyzico')->pay($data)
     */
    public function via(?string $driver = null): PendingPayment
    {
        return new PendingPayment(
            gateway: $this->driver($driver),
            recorder: $this->container->make(TransactionRecorder::class),
        );
    }

    /**
     * Payable model ile başlayan fluent zincir.
     *
     * Payline::for($order)->via('iyzico')->pay($data)
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
