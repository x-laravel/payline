<?php

namespace XLaravel\Payline\StateMachine;

use LogicException;
use XLaravel\Payline\Enums\PaymentStatus;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;

class PaymentStatusResolver
{
    public function forTransaction(TransactionType $type, TransactionStatus $status): ?PaymentStatus
    {
        return match ($type) {
            TransactionType::Payment => $this->afterPayment($status),
            TransactionType::Authorization => $this->afterAuthorization($status),
            TransactionType::Void => $this->afterVoid($status),
            TransactionType::Capture => throw new LogicException(
                'Capture status must be resolved with forCapture().',
            ),
            TransactionType::Refund => throw new LogicException(
                'Refund status must be resolved with forRefund().',
            ),
        };
    }

    public function forCapture(TransactionStatus $status, int $captured, int $paymentAmount): ?PaymentStatus
    {
        return match ($status) {
            TransactionStatus::Successful => $captured >= $paymentAmount
                ? PaymentStatus::Paid
                : PaymentStatus::PartiallyCaptured,
            TransactionStatus::Pending => PaymentStatus::Pending,
            TransactionStatus::Expired => PaymentStatus::Expired,
            TransactionStatus::Unknown => PaymentStatus::Unknown,
            default => null,
        };
    }

    public function forRefund(TransactionStatus $status, int $refunded, int $paymentAmount): ?PaymentStatus
    {
        if ($status !== TransactionStatus::Successful) {
            return $status === TransactionStatus::Unknown ? PaymentStatus::Unknown : null;
        }

        return $refunded >= $paymentAmount
            ? PaymentStatus::Refunded
            : PaymentStatus::PartiallyRefunded;
    }

    private function afterPayment(TransactionStatus $status): ?PaymentStatus
    {
        return match ($status) {
            TransactionStatus::Initiated => PaymentStatus::Initiated,
            TransactionStatus::Pending => PaymentStatus::Pending,
            TransactionStatus::Authorized => PaymentStatus::Authorized,
            TransactionStatus::Successful => PaymentStatus::Paid,
            TransactionStatus::Failed => PaymentStatus::Failed,
            TransactionStatus::Expired => PaymentStatus::Expired,
            TransactionStatus::Unknown => PaymentStatus::Unknown,
            default => null,
        };
    }

    private function afterAuthorization(TransactionStatus $status): ?PaymentStatus
    {
        return match ($status) {
            TransactionStatus::Initiated => PaymentStatus::Initiated,
            TransactionStatus::Pending => PaymentStatus::Pending,
            TransactionStatus::Authorized => PaymentStatus::Authorized,
            TransactionStatus::Failed => PaymentStatus::Failed,
            TransactionStatus::Expired => PaymentStatus::Expired,
            TransactionStatus::Unknown => PaymentStatus::Unknown,
            default => null,
        };
    }

    private function afterVoid(TransactionStatus $status): ?PaymentStatus
    {
        return match ($status) {
            TransactionStatus::Voided => PaymentStatus::Voided,
            TransactionStatus::Unknown => PaymentStatus::Unknown,
            default => null,
        };
    }
}
