<?php

namespace XLaravel\Payline;

use XLaravel\Payline\Concerns\BuildsPaymentRequest;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Events\PaymentInitiated;
use XLaravel\Payline\Gateway\GatewayInvoker;
use XLaravel\Payline\Gateway\GatewayResolver;
use XLaravel\Payline\Payments\TransactionRunner;

class PendingPayment
{
    use BuildsPaymentRequest;

    protected ?Payable $payable = null;

    protected ?object $owner = null;

    public function __construct(
        protected readonly ?Gateway $gateway,
        protected readonly TransactionRecorder $recorder,
        protected readonly GatewayResolver $resolver,
        protected readonly GatewayInvoker $invoker,
        protected readonly TransactionRunner $runner,
        protected readonly bool $autoRoute = false,
    ) {}

    public function for(Payable $payable): static
    {
        $this->payable = $payable;

        return $this;
    }

    public function by(object $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function charge(?PaymentRequest $data = null): PaymentResponse
    {
        return $this->start($data ?? $this->toPaymentRequest($this->payable), TransactionType::Payment);
    }

    public function authorize(?PaymentRequest $data = null): PaymentResponse
    {
        return $this->start($data ?? $this->toPaymentRequest($this->payable), TransactionType::Authorization);
    }

    private function start(PaymentRequest $data, TransactionType $type): PaymentResponse
    {
        $gateway = $this->resolver->forRequest($data, $type, $this->gateway, $this->autoRoute);

        if ($data->callbackUrl === null && config('payline.routes.enabled', true)) {
            $data = $data->withCallbackUrl(route('payline.callback', ['gateway' => $gateway->getName()]));
        }

        $attempt = $this->recorder->createPaymentAttempt(
            gateway: $gateway->getName(),
            type: $type,
            data: $data,
            payable: $this->payable,
            owner: $this->owner,
        );

        if (! $attempt->created) {
            return $this->recorder->responseFromTransaction($attempt->transaction);
        }

        event(new PaymentInitiated($attempt->payment, $attempt->transaction, $data));

        return $this->runner->run(
            $attempt->payment,
            $attempt->transaction,
            fn () => $this->invoker->operation($gateway, $type, $data),
        );
    }
}
