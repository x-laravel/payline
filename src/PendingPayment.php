<?php

namespace XLaravel\Payline;

use LogicException;
use Throwable;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\DTOs\CaptureData;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Events\PaymentAuthorized;
use XLaravel\Payline\Events\PaymentCaptured;
use XLaravel\Payline\Events\PaymentFailed;
use XLaravel\Payline\Events\PaymentInitiated;
use XLaravel\Payline\Events\PaymentRefunded;
use XLaravel\Payline\Events\PaymentSucceeded;
use XLaravel\Payline\Events\PaymentVoided;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;
use XLaravel\Payline\Routing\GatewayRouter;

class PendingPayment
{
    protected ?Payable $payable = null;
    protected ?object $owner = null;

    public function __construct(
        protected readonly ?Gateway $gateway,
        protected readonly TransactionRecorder $recorder,
        protected readonly ?PaylineManager $manager = null,
    ) {}

    protected function resolveGateway(PaymentRequest $data): Gateway
    {
        if ($this->gateway !== null) {
            return $this->gateway;
        }

        $cardProfile = $data->card?->profile ?? $data->cardProfile;

        if ($this->manager !== null && $cardProfile !== null) {
            $driver = app(GatewayRouter::class)->cheapestFor(
                $cardProfile,
                $data->installments ?? 1,
            );

            if ($driver !== null) {
                return $this->manager->driver($driver);
            }
        }

        if ($this->manager !== null) {
            return $this->manager->driver();
        }

        throw new LogicException('Gateway could not be resolved: no driver specified and cardProfile is missing.');
    }

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

    public function charge(PaymentRequest $data): PaymentResponse
    {
        $gateway = $this->resolveGateway($data);

        if ($data->callbackUrl === null) {
            $data = $data->withCallbackUrl(route('payline.callback', ['gateway' => $gateway->getName()]));
        }

        $payment = $this->recorder->createPayment(
            gateway: $gateway->getName(),
            data: $data,
            payable: $this->payable,
            owner: $this->owner,
        );

        $tx = $this->recorder->createTransaction(
            payment: $payment,
            type: TransactionType::Payment,
            data: $data,
            attempt: 1,
        );

        event(new PaymentInitiated($payment, $tx, $data));

        return $this->run($payment, $tx, fn () => $gateway->pay($data));
    }

    public function authorize(PaymentRequest $data): PaymentResponse
    {
        $gateway = $this->resolveGateway($data);

        if ($data->callbackUrl === null) {
            $data = $data->withCallbackUrl(route('payline.callback', ['gateway' => $gateway->getName()]));
        }

        $payment = $this->recorder->createPayment(
            gateway: $gateway->getName(),
            data: $data,
            payable: $this->payable,
            owner: $this->owner,
        );

        $tx = $this->recorder->createTransaction(
            payment: $payment,
            type: TransactionType::Authorization,
            data: $data,
            attempt: 1,
        );

        event(new PaymentInitiated($payment, $tx, $data));

        return $this->run($payment, $tx, fn () => $gateway->authorize($data));
    }

    public function capture(CaptureData $data, Payment $payment, Transaction $parent): PaymentResponse
    {
        $gateway = $this->gateway ?? $this->manager?->driver()
            ?? throw new LogicException('A driver must be specified for capture.');

        $tx = $this->recorder->createCaptureTransaction(
            payment: $payment,
            data: $data,
            parent: $parent,
        );

        return $this->run($payment, $tx, fn () => $gateway->capture($data));
    }

    public function refund(RefundData $data, Payment $payment, Transaction $parent): PaymentResponse
    {
        $gateway = $this->gateway ?? $this->manager?->driver()
            ?? throw new LogicException('A driver must be specified for refund.');

        $tx = $this->recorder->createRefundTransaction(
            payment: $payment,
            data: $data,
            parent: $parent,
        );

        return $this->run($payment, $tx, fn () => $gateway->refund($data));
    }

    public function void(VoidData $data, Payment $payment, Transaction $parent): PaymentResponse
    {
        $gateway = $this->gateway ?? $this->manager?->driver()
            ?? throw new LogicException('A driver must be specified for void.');

        $tx = $this->recorder->createVoidTransaction(
            payment: $payment,
            data: $data,
            parent: $parent,
        );

        return $this->run($payment, $tx, fn () => $gateway->void($data));
    }

    public function handleCallback(CallbackData $data): PaymentResponse
    {
        $gateway = $this->gateway ?? $this->manager?->driver()
            ?? throw new LogicException('A driver must be specified for callback handling.');

        $response = $gateway->handleCallback($data);

        $tx = $this->recorder->findTransactionByGatewayOrderId($response->gatewayOrderId ?? '')
            ?? $this->recorder->findTransactionByGatewayTransactionId($response->gatewayTransactionId ?? '');

        if ($tx) {
            $this->recorder->updateTransaction($tx, $response);
            $this->dispatchStatusEvent($response, $tx->payment, $tx);
        }

        return $response;
    }

    private function run(Payment $payment, Transaction $tx, callable $action): PaymentResponse
    {
        try {
            $response = $action();
            $this->recorder->updateTransaction($tx, $response);
            $this->dispatchStatusEvent($response, $payment, $tx);
            return $response;
        } catch (Throwable $e) {
            $this->recorder->failTransaction($tx, $e->getMessage());
            event(new PaymentFailed($payment, $tx, null, $e->getMessage()));
            throw $e;
        }
    }

    private function dispatchStatusEvent(PaymentResponse $response, ?Payment $payment, Transaction $tx): void
    {
        if ($response->isFailure()) {
            event(new PaymentFailed($payment, $tx, $response));
            return;
        }

        match ($response->type) {
            TransactionType::Payment => $response->isSuccessful()
                ? event(new PaymentSucceeded($payment, $tx, $response))
                : null,
            TransactionType::Authorization => $response->status === TransactionStatus::Authorized
                ? event(new PaymentAuthorized($payment, $tx, $response))
                : null,
            TransactionType::Capture => $response->isSuccessful()
                ? event(new PaymentCaptured($payment, $tx, $response))
                : null,
            TransactionType::Refund => event(new PaymentRefunded($payment, $tx, $response)),
            TransactionType::Void => event(new PaymentVoided($payment, $tx, $response)),
        };
    }
}