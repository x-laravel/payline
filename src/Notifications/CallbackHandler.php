<?php

namespace XLaravel\Payline\Notifications;

use LogicException;
use XLaravel\Payline\Contracts\HandlesCallbacks;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\DTOs\CallbackResult;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\Events\CallbackUnmatched;
use XLaravel\Payline\Gateway\GatewayInvoker;
use XLaravel\Payline\Models\Transaction;
use XLaravel\Payline\PaylineManager;
use XLaravel\Payline\Payments\TransactionRunner;
use XLaravel\Payline\TransactionRecorder;

class CallbackHandler
{
    public function __construct(
        private readonly PaylineManager $manager,
        private readonly TransactionRecorder $recorder,
        private readonly GatewayInvoker $invoker,
        private readonly TransactionRunner $runner,
    ) {}

    public function handle(CallbackData $data): CallbackResult
    {
        $gateway = $this->manager->driver($data->gateway);

        $response = $this->invoker->call($gateway, HandlesCallbacks::class, 'handleCallback', $data);

        return $this->apply($gateway->getName(), $response);
    }

    public function apply(string $gateway, PaymentResponse $response): CallbackResult
    {
        if ($response->gatewayName !== $gateway) {
            throw new LogicException(sprintf(
                'Gateway response belongs to [%s], [%s] expected.',
                $response->gatewayName,
                $gateway,
            ));
        }

        $transaction = $this->match($gateway, $response);

        if ($transaction === null) {
            event(new CallbackUnmatched($gateway, $response));

            return new CallbackResult($response, null);
        }

        return new CallbackResult($response, $this->runner->apply($transaction, $response));
    }

    private function match(string $gateway, PaymentResponse $response): ?Transaction
    {
        if ($response->gatewayOrderId !== null) {
            $transaction = $this->recorder->findTransactionByGatewayOrderId(
                $response->gatewayOrderId,
                $gateway,
                $response->type,
            );

            if ($transaction !== null) {
                return $transaction;
            }
        }

        if ($response->gatewayTransactionId === null) {
            return null;
        }

        return $this->recorder->findTransactionByGatewayTransactionId(
            $response->gatewayTransactionId,
            $gateway,
            $response->type,
        );
    }
}
