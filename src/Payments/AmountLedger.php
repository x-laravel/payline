<?php

namespace XLaravel\Payline\Payments;

use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;
use XLaravel\Payline\Exceptions\InvalidPaymentOperationException;
use XLaravel\Payline\Facades\Payline;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\Models\Transaction;

class AmountLedger
{
    public function assertAvailable(
        Payment $payment,
        Transaction $parent,
        TransactionType $type,
        int $amount,
    ): void {
        if (! in_array($type, [TransactionType::Capture, TransactionType::Refund], true)) {
            return;
        }

        $transactionModel = Payline::transactionModel();

        $lockedParent = $transactionModel::query()
            ->lockForUpdate()
            ->findOrFail($parent->getKey());

        $reserved = $this->reserved(
            $transactionModel::query()->where('parent_transaction_id', $lockedParent->getKey()),
            $type,
        );

        if ($reserved + $amount > $lockedParent->amount) {
            throw new InvalidPaymentOperationException(
                $type === TransactionType::Refund
                    ? 'Refund amount exceeds the unreserved amount of the parent transaction.'
                    : 'Capture amount exceeds the unreserved authorized amount.',
            );
        }

        if ($type !== TransactionType::Refund) {
            return;
        }

        $paymentModel = Payline::paymentModel();

        $lockedPayment = $paymentModel::query()
            ->lockForUpdate()
            ->findOrFail($payment->getKey());

        if ($this->reserved($lockedPayment->transactions(), $type) + $amount > $lockedPayment->amount) {
            throw new InvalidPaymentOperationException(
                'Refund amount exceeds the unreserved refundable amount.',
            );
        }
    }

    public function reserved($query, TransactionType $type): int
    {
        return (int) $query
            ->where('type', $type->value)
            ->whereNotIn('status', [
                TransactionStatus::Failed->value,
                TransactionStatus::Expired->value,
            ])
            ->sum('amount');
    }
}
