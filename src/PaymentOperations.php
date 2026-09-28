<?php

namespace XLaravel\Payline;

use LogicException;
use XLaravel\Payline\Contracts\QueriesPayments;
use XLaravel\Payline\DTOs\CaptureData;
use XLaravel\Payline\DTOs\PaymentQuery;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Gateway\GatewayInvoker;
use XLaravel\Payline\Gateway\GatewayResolver;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;
use XLaravel\Payline\Payments\FollowUpReconciler;
use XLaravel\Payline\Payments\TransactionRunner;

class PaymentOperations
{
    public function __construct(
        private readonly Payment $payment,
        private readonly TransactionRecorder $recorder,
        private readonly PaymentOperationValidator $validator,
        private readonly GatewayResolver $resolver,
        private readonly GatewayInvoker $invoker,
        private readonly TransactionRunner $runner,
        private readonly FollowUpReconciler $followUps,
    ) {}

    public function capture(
        ?int $amount = null,
        ?string $idempotencyKey = null,
        ?array $metadata = null,
    ): PaymentResponse {
        $parent = $this->authorizedParent();

        return $this->perform(TransactionType::Capture, new CaptureData(
            gatewayTransactionId: $this->providerTransactionId($parent),
            amount: $amount ?? $parent->amount,
            currency: $parent->currency,
            metadata: $metadata,
            idempotencyKey: $idempotencyKey,
        ), $parent);
    }

    public function refund(
        int $amount,
        ?string $reason = null,
        ?string $idempotencyKey = null,
        ?array $metadata = null,
    ): PaymentResponse {
        $parent = $this->refundableParent();

        return $this->perform(TransactionType::Refund, new RefundData(
            gatewayTransactionId: $this->providerTransactionId($parent),
            amount: $amount,
            currency: $parent->currency,
            reason: $reason,
            metadata: $metadata,
            idempotencyKey: $idempotencyKey,
        ), $parent);
    }

    public function void(?string $idempotencyKey = null, ?array $metadata = null): PaymentResponse
    {
        $parent = $this->voidableParent();

        return $this->perform(TransactionType::Void, new VoidData(
            gatewayTransactionId: $this->providerTransactionId($parent),
            amount: (int) $parent->amount,
            currency: $parent->currency,
            metadata: $metadata,
            idempotencyKey: $idempotencyKey,
        ), $parent);
    }

    public function reconcile(?PaymentQuery $query = null): PaymentResponse
    {
        $gateway = $this->resolver->forPayment($this->payment);

        if (! $gateway instanceof QueriesPayments) {
            throw new LogicException(sprintf(
                'Gateway [%s] does not implement [%s].',
                $gateway->getName(),
                QueriesPayments::class,
            ));
        }

        $transaction = $this->queryableTransaction();

        $query ??= new PaymentQuery(
            gatewayTransactionId: $transaction->gateway_transaction_id,
            gatewayOrderId: $transaction->gateway_order_id,
            reference: $this->payment->reference,
            currency: $transaction->currency,
        );

        $response = $gateway->queryPayment($query);

        $this->runner->assertMatches($this->payment, $transaction, $response);
        $this->runner->apply($transaction, $response);

        $this->followUps->settle($this->payment->refresh(), $response);

        return $response;
    }

    private function queryableTransaction(): Transaction
    {
        return $this->payment->transactions()
            ->whereIn('type', [TransactionType::Payment->value, TransactionType::Authorization->value])
            ->latest('created_at')
            ->first()
            ?? $this->payment->latestTransaction()->first()
            ?? throw new LogicException('Payment has no transaction to reconcile.');
    }

    private function perform(
        TransactionType $type,
        CaptureData|RefundData|VoidData $data,
        Transaction $parent,
    ): PaymentResponse {
        $existing = $this->recorder->findOperationByIdempotencyKey(
            $this->payment,
            $type,
            $data->idempotencyKey,
            $data->fingerprint(),
        );

        if ($existing !== null) {
            return $this->recorder->responseFromTransaction($existing);
        }

        $this->validator->validate($type, $data, $this->payment, $parent);

        $gateway = $this->resolver->forPayment($this->payment);
        $attempt = $this->recorder->createOperationTransaction($this->payment, $type, $data, $parent);

        if (! $attempt->created) {
            return $this->recorder->responseFromTransaction($attempt->transaction);
        }

        return $this->runner->run(
            $this->payment,
            $attempt->transaction,
            fn () => $this->invoker->operation($gateway, $type, $data),
        );
    }

    private function authorizedParent(): Transaction
    {
        return $this->payment->transactions()
            ->where('type', TransactionType::Authorization->value)
            ->where('status', TransactionStatus::Authorized->value)
            ->latest('created_at')
            ->first()
            ?? throw new LogicException('Payment has no authorized authorization transaction.');
    }

    private function voidableParent(): Transaction
    {
        return $this->payment->transactions()
            ->where('type', TransactionType::Authorization->value)
            ->where('status', TransactionStatus::Authorized->value)
            ->latest('created_at')
            ->first()
            ?? $this->payment->transactions()
                ->where('type', TransactionType::Payment->value)
                ->where('status', TransactionStatus::Successful->value)
                ->latest('created_at')
                ->first()
            ?? throw new LogicException('Payment has no authorization or sale to void.');
    }

    private function refundableParent(): Transaction
    {
        return $this->payment->transactions()
            ->whereIn('type', [TransactionType::Payment->value, TransactionType::Capture->value])
            ->where('status', TransactionStatus::Successful->value)
            ->latest('created_at')
            ->first()
            ?? throw new LogicException('Payment has no successful transaction to refund.');
    }

    private function providerTransactionId(Transaction $transaction): string
    {
        return $transaction->gateway_transaction_id
            ?? throw new LogicException('Transaction has no gateway transaction ID.');
    }
}
