<?php

namespace XLaravel\Payline\Payments;

use Throwable;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Events\PaymentAuthorized;
use XLaravel\Payline\Events\PaymentCaptured;
use XLaravel\Payline\Events\PaymentErrored;
use XLaravel\Payline\Events\PaymentFailed;
use XLaravel\Payline\Events\PaymentPending;
use XLaravel\Payline\Events\PaymentRefunded;
use XLaravel\Payline\Events\PaymentSucceeded;
use XLaravel\Payline\Events\PaymentVoided;
use XLaravel\Payline\Exceptions\UnexpectedGatewayResponseException;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;

class TransactionRunner
{
    public function __construct(
        private readonly TransactionUpdater $updater,
    ) {}

    public function run(Payment $payment, Transaction $transaction, callable $action): PaymentResponse
    {
        try {
            $response = $action();
        } catch (Throwable $exception) {
            $this->markUnknown($payment, $transaction, $exception);

            throw $exception;
        }

        try {
            $this->assertMatches($payment, $transaction, $response);
        } catch (UnexpectedGatewayResponseException $exception) {
            $this->markUnknown($payment, $transaction, $exception);

            throw $exception;
        }

        $this->apply($transaction, $response);

        return $response;
    }

    public function apply(Transaction $transaction, PaymentResponse $response): Transaction
    {
        $updated = $this->updater->apply($transaction, $response);

        if ($updated->wasChanged('status')) {
            $this->dispatchStatusEvent($response, $updated->payment()->firstOrFail(), $updated);
        }

        return $updated;
    }

    public function assertMatches(Payment $payment, Transaction $transaction, PaymentResponse $response): void
    {
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

    private function markUnknown(Payment $payment, Transaction $transaction, Throwable $exception): void
    {
        $transaction = $this->updater->markUnknown($transaction, $exception->getMessage());

        event(new PaymentErrored($payment->fresh(), $transaction, $exception));
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
