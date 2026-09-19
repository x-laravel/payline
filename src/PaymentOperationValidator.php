<?php

namespace XLaravel\Payline;

use XLaravel\Payline\DTOs\CaptureData;
use XLaravel\Payline\DTOs\RefundData;
use XLaravel\Payline\DTOs\VoidData;
use XLaravel\Payline\Enums\PaymentStatus;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Exceptions\InvalidPaymentOperationException;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;

class PaymentOperationValidator
{
    public function capture(CaptureData $data, Payment $payment, Transaction $parent): void
    {
        $payment->refresh();
        $parent->refresh();
        $this->assertParent($payment, $parent);
        $this->assertGatewayTransaction($data->gatewayTransactionId, $parent);
        $this->assertCurrency($data->currency, $payment);

        if ($payment->status !== PaymentStatus::Authorized
            || $parent->type !== TransactionType::Authorization
            || $parent->status !== TransactionStatus::Authorized) {
            throw new InvalidPaymentOperationException('Only an authorized payment can be captured.');
        }

        if ($data->amount > $parent->amount) {
            throw new InvalidPaymentOperationException('Capture amount cannot exceed the authorized amount.');
        }
    }

    public function refund(RefundData $data, Payment $payment, Transaction $parent): void
    {
        $payment->refresh();
        $parent->refresh();
        $this->assertParent($payment, $parent);
        $this->assertGatewayTransaction($data->gatewayTransactionId, $parent);
        $this->assertCurrency($data->currency, $payment);

        if (! $payment->status->hasOutstandingAmount()) {
            throw new InvalidPaymentOperationException('Only a paid payment can be refunded.');
        }

        if (! in_array($parent->type, [TransactionType::Payment, TransactionType::Capture], true)
            || $parent->status !== TransactionStatus::Successful) {
            throw new InvalidPaymentOperationException('Refund parent must be a successful payment or capture transaction.');
        }

        if ($data->amount > $payment->remainingRefundable()) {
            throw new InvalidPaymentOperationException('Refund amount cannot exceed the remaining refundable amount.');
        }
    }

    public function void(VoidData $data, Payment $payment, Transaction $parent): void
    {
        $payment->refresh();
        $parent->refresh();
        $this->assertParent($payment, $parent);
        $this->assertGatewayTransaction($data->gatewayTransactionId, $parent);

        if ($payment->status !== PaymentStatus::Authorized
            || $parent->type !== TransactionType::Authorization
            || $parent->status !== TransactionStatus::Authorized) {
            throw new InvalidPaymentOperationException('Only an authorized payment can be voided.');
        }
    }

    private function assertParent(Payment $payment, Transaction $parent): void
    {
        if ((string) $parent->payment_id !== (string) $payment->getKey()) {
            throw new InvalidPaymentOperationException('Parent transaction does not belong to the payment.');
        }
    }

    private function assertGatewayTransaction(string $gatewayTransactionId, Transaction $parent): void
    {
        if ($parent->gateway_transaction_id !== null
            && ! hash_equals((string) $parent->gateway_transaction_id, $gatewayTransactionId)) {
            throw new InvalidPaymentOperationException('Gateway transaction ID does not match the parent transaction.');
        }
    }

    private function assertCurrency(string $currency, Payment $payment): void
    {
        if (strtoupper($currency) !== strtoupper($payment->currency)) {
            throw new InvalidPaymentOperationException('Operation currency must match the payment currency.');
        }
    }
}
