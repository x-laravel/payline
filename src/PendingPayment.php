<?php

namespace XLaravel\Payline;

use LogicException;
use Throwable;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\AuthorizesPayments;
use XLaravel\Payline\Contracts\CapturesPayments;
use XLaravel\Payline\Contracts\ChargesPayments;
use XLaravel\Payline\Contracts\HandlesCallbacks;
use XLaravel\Payline\Contracts\Payable;
use XLaravel\Payline\Contracts\ProvidesGatewayCapabilities;
use XLaravel\Payline\Contracts\QueriesPayments;
use XLaravel\Payline\Contracts\RefundsPayments;
use XLaravel\Payline\Contracts\VoidsPayments;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\DTOs\CallbackResult;
use XLaravel\Payline\DTOs\CaptureData;
use XLaravel\Payline\DTOs\PaymentQuery;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Events\CallbackUnmatched;
use XLaravel\Payline\Events\PaymentAuthorized;
use XLaravel\Payline\Events\PaymentCaptured;
use XLaravel\Payline\Events\PaymentErrored;
use XLaravel\Payline\Events\PaymentFailed;
use XLaravel\Payline\Events\PaymentInitiated;
use XLaravel\Payline\Events\PaymentPending;
use XLaravel\Payline\Events\PaymentRefunded;
use XLaravel\Payline\Events\PaymentSucceeded;
use XLaravel\Payline\Events\PaymentVoided;
use XLaravel\Payline\Exceptions\UnexpectedGatewayResponseException;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;
use XLaravel\Payline\Routing\GatewayRouter;
use XLaravel\Payline\Routing\GatewayPolicyPipeline;

class PendingPayment
{
    protected ?Payable $payable = null;
    protected ?object $owner = null;

    public function __construct(
        protected readonly ?Gateway $gateway,
        protected readonly TransactionRecorder $recorder,
        protected readonly PaymentOperationValidator $validator,
        protected readonly GatewayPolicyPipeline $policies,
        protected readonly ?PaylineManager $manager = null,
        protected readonly ?GatewayRouter $router = null,
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

    public function charge(PaymentRequest $data): PaymentResponse
    {
        return $this->start($data, TransactionType::Payment);
    }

    public function authorize(PaymentRequest $data): PaymentResponse
    {
        return $this->start($data, TransactionType::Authorization);
    }

    public function capture(CaptureData $data, Payment $payment, Transaction $parent): PaymentResponse
    {
        if ($existing = $this->recorder->findOperationByIdempotencyKey(
            $payment,
            TransactionType::Capture,
            $data->idempotencyKey,
            $data->fingerprint(),
        )) {
            return $this->recorder->responseFromTransaction($existing);
        }

        $this->validator->capture($data, $payment, $parent);
        $gateway = $this->resolvePaymentGateway($payment);
        $attempt = $this->recorder->createCaptureTransaction($payment, $data, $parent);

        return $attempt->created
            ? $this->run(
                $payment,
                $attempt->transaction,
                fn () => $this->invokeGateway($gateway, CapturesPayments::class, 'capture', $data),
            )
            : $this->recorder->responseFromTransaction($attempt->transaction);
    }

    public function refund(RefundData $data, Payment $payment, Transaction $parent): PaymentResponse
    {
        if ($existing = $this->recorder->findOperationByIdempotencyKey(
            $payment,
            TransactionType::Refund,
            $data->idempotencyKey,
            $data->fingerprint(),
        )) {
            return $this->recorder->responseFromTransaction($existing);
        }

        $this->validator->refund($data, $payment, $parent);
        $gateway = $this->resolvePaymentGateway($payment);
        $attempt = $this->recorder->createRefundTransaction($payment, $data, $parent);

        return $attempt->created
            ? $this->run(
                $payment,
                $attempt->transaction,
                fn () => $this->invokeGateway($gateway, RefundsPayments::class, 'refund', $data),
            )
            : $this->recorder->responseFromTransaction($attempt->transaction);
    }

    public function void(VoidData $data, Payment $payment, Transaction $parent): PaymentResponse
    {
        if ($existing = $this->recorder->findOperationByIdempotencyKey(
            $payment,
            TransactionType::Void,
            $data->idempotencyKey,
            $data->fingerprint(),
        )) {
            return $this->recorder->responseFromTransaction($existing);
        }

        $this->validator->void($data, $payment, $parent);
        $gateway = $this->resolvePaymentGateway($payment);
        $attempt = $this->recorder->createVoidTransaction($payment, $data, $parent);

        return $attempt->created
            ? $this->run(
                $payment,
                $attempt->transaction,
                fn () => $this->invokeGateway($gateway, VoidsPayments::class, 'void', $data),
            )
            : $this->recorder->responseFromTransaction($attempt->transaction);
    }

    public function reconcile(Payment $payment, ?PaymentQuery $query = null): PaymentResponse
    {
        $gateway = $this->resolvePaymentGateway($payment);

        if (! $gateway instanceof QueriesPayments) {
            throw new LogicException("Gateway [{$gateway->getName()}] does not support payment status queries.");
        }

        $transaction = $payment->latestTransaction()->first()
            ?? throw new LogicException('Payment has no transaction to reconcile.');

        $query ??= new PaymentQuery(
            gatewayTransactionId: $transaction->gateway_transaction_id,
            gatewayOrderId: $transaction->gateway_order_id,
            reference: $payment->reference,
        );

        $response = $gateway->queryPayment($query);
        $this->assertResponseMatches($payment, $transaction, $response);
        $updated = $this->recorder->updateTransaction($transaction, $response);

        if ($updated->wasChanged('status')) {
            $this->dispatchStatusEvent($response, $updated->payment()->firstOrFail(), $updated);
        }

        return $response;
    }

    public function handleCallback(CallbackData $data): CallbackResult
    {
        $gateway = $this->gateway ?? $this->manager?->driver($data->gateway)
            ?? throw new LogicException('A driver must be specified for callback handling.');

        if ($gateway->getName() !== $data->gateway) {
            throw new LogicException('Callback gateway does not match the selected driver.');
        }

        $response = $this->invokeGateway($gateway, HandlesCallbacks::class, 'handleCallback', $data);

        return $this->handleResponse($gateway->getName(), $response);
    }

    public function handleResponse(string $gateway, PaymentResponse $response): CallbackResult
    {
        if ($response->gatewayName !== $gateway) {
            throw new LogicException('Gateway response does not match the selected driver.');
        }

        $transaction = ($response->gatewayOrderId
                ? $this->recorder->findTransactionByGatewayOrderId($response->gatewayOrderId, $gateway, $response->type)
                : null)
            ?? ($response->gatewayTransactionId
                ? $this->recorder->findTransactionByGatewayTransactionId($response->gatewayTransactionId, $gateway, $response->type)
                : null);

        if ($transaction === null) {
            event(new CallbackUnmatched($gateway, $response));

            return new CallbackResult($response, null);
        }

        $transaction = $this->recorder->updateTransaction($transaction, $response);

        if ($transaction->wasChanged('status')) {
            $this->dispatchStatusEvent($response, $transaction->payment()->firstOrFail(), $transaction);
        }

        return new CallbackResult($response, $transaction);
    }

    private function start(PaymentRequest $data, TransactionType $type): PaymentResponse
    {
        $gateway = $this->resolveGateway($data, $type);

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

        return $this->run(
            $attempt->payment,
            $attempt->transaction,
            $type === TransactionType::Authorization
                ? fn () => $this->invokeGateway($gateway, AuthorizesPayments::class, 'authorize', $data)
                : fn () => $this->invokeGateway($gateway, ChargesPayments::class, 'pay', $data),
        );
    }

    private function resolveGateway(PaymentRequest $data, TransactionType $type): Gateway
    {
        if ($this->gateway !== null) {
            $this->assertGatewaySupports($this->gateway, $data, $type);

            return $this->gateway;
        }

        $profile = $data->card?->profile ?? $data->cardProfile;

        if ($this->router !== null && $this->manager !== null && $profile !== null) {
            foreach ($this->router->rankedFor($profile, $data->installments ?? 1) as $driver => $rate) {
                $gateway = $this->manager->driver($driver);

                if ($this->gatewaySupports($gateway, $data, $type)) {
                    return $gateway;
                }
            }
        }

        if ($this->manager !== null) {
            $gateway = $this->manager->driver();
            $this->assertGatewaySupports($gateway, $data, $type);

            return $gateway;
        }

        throw new LogicException('Gateway could not be resolved.');
    }

    private function resolvePaymentGateway(Payment $payment): Gateway
    {
        if ($this->gateway !== null) {
            if ($this->gateway->getName() !== $payment->gateway) {
                throw new LogicException('Follow-up operations must use the payment gateway.');
            }

            return $this->gateway;
        }

        return $this->manager?->driver($payment->gateway)
            ?? throw new LogicException('The payment gateway could not be resolved.');
    }

    private function gatewaySupports(Gateway $gateway, PaymentRequest $data, TransactionType $type): bool
    {
        if (! $this->supportsOperation($gateway, $type)) {
            return false;
        }

        return ! $gateway instanceof ProvidesGatewayCapabilities
            ? $this->policies->allows($gateway, $data, $type)
            : $gateway->capabilities()->supports($data, $type)
                && $this->policies->allows($gateway, $data, $type);
    }

    private function supportsOperation(Gateway $gateway, TransactionType $type): bool
    {
        [$contract, $method] = match ($type) {
            TransactionType::Payment => [ChargesPayments::class, 'pay'],
            TransactionType::Authorization => [AuthorizesPayments::class, 'authorize'],
            TransactionType::Capture => [CapturesPayments::class, 'capture'],
            TransactionType::Refund => [RefundsPayments::class, 'refund'],
            TransactionType::Void => [VoidsPayments::class, 'void'],
        };

        return $gateway instanceof $contract || method_exists($gateway, $method);
    }

    private function invokeGateway(
        Gateway $gateway,
        string $contract,
        string $method,
        mixed $data,
    ): PaymentResponse {
        if (! $gateway instanceof $contract && ! method_exists($gateway, $method)) {
            throw new LogicException("Gateway [{$gateway->getName()}] does not support [{$method}].");
        }

        return $gateway->{$method}($data);
    }

    private function assertGatewaySupports(Gateway $gateway, PaymentRequest $data, TransactionType $type): void
    {
        if (! $this->gatewaySupports($gateway, $data, $type)) {
            throw new LogicException("Gateway [{$gateway->getName()}] does not support this payment request.");
        }
    }

    private function run(Payment $payment, Transaction $transaction, callable $action): PaymentResponse
    {
        try {
            $response = $action();
        } catch (Throwable $exception) {
            $transaction = $this->recorder->markTransactionUnknown($transaction, $exception->getMessage());
            event(new PaymentErrored($payment->fresh(), $transaction, $exception));

            throw $exception;
        }

        try {
            $this->assertResponseMatches($payment, $transaction, $response);
        } catch (UnexpectedGatewayResponseException $exception) {
            $transaction = $this->recorder->markTransactionUnknown($transaction, $exception->getMessage());
            event(new PaymentErrored($payment->fresh(), $transaction, $exception));

            throw $exception;
        }

        $transaction = $this->recorder->updateTransaction($transaction, $response);

        if ($transaction->wasChanged('status')) {
            $this->dispatchStatusEvent($response, $transaction->payment()->firstOrFail(), $transaction);
        }

        return $response;
    }

    private function assertResponseMatches(
        Payment $payment,
        Transaction $transaction,
        PaymentResponse $response,
    ): void {
        if ($response->gatewayName !== $payment->gateway) {
            throw new UnexpectedGatewayResponseException('Gateway response belongs to a different gateway.');
        }

        if ($response->type !== $transaction->type) {
            throw new UnexpectedGatewayResponseException('Gateway response operation does not match the transaction.');
        }

        if (strtoupper($response->currency) !== strtoupper($transaction->currency)) {
            throw new UnexpectedGatewayResponseException('Gateway response currency does not match the transaction.');
        }
    }

    private function dispatchStatusEvent(PaymentResponse $response, Payment $payment, Transaction $transaction): void
    {
        if ($response->isFailure()) {
            event(new PaymentFailed($payment, $transaction, $response));

            return;
        }

        match ($response->type) {
            TransactionType::Payment => match (true) {
                $response->isSuccessful() => event(new PaymentSucceeded($payment, $transaction, $response)),
                $response->isPending() => event(new PaymentPending($payment, $transaction, $response)),
                default => null,
            },
            TransactionType::Authorization => $response->status === TransactionStatus::Authorized
                ? event(new PaymentAuthorized($payment, $transaction, $response))
                : null,
            TransactionType::Capture => $response->isSuccessful()
                ? event(new PaymentCaptured($payment, $transaction, $response))
                : null,
            TransactionType::Refund => event(new PaymentRefunded($payment, $transaction, $response)),
            TransactionType::Void => event(new PaymentVoided($payment, $transaction, $response)),
        };
    }
}
