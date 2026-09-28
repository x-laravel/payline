<?php

namespace XLaravel\Payline\Tests\Fixtures\Gateways;

use XLaravel\Payline\Contracts\ChargesPayments;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\Contracts\ProvidesGatewayCapabilities;
use XLaravel\Payline\DTOs\GatewayCapabilities;
use XLaravel\Payline\DTOs\PaymentRequest;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\Enums\PaymentMethod;
use XLaravel\Payline\Enums\TransactionStatus;
use XLaravel\Payline\Enums\TransactionType;

class LimitedGateway implements ChargesPayments, Gateway, ProvidesGatewayCapabilities
{
    public function capabilities(): GatewayCapabilities
    {
        return new GatewayCapabilities(
            operations: [TransactionType::Payment],
            methods: [PaymentMethod::CreditCard],
            currencies: ['TRY'],
            installments: [1, 3],
            threeDs: true,
            nonThreeDs: false,
        );
    }

    public function pay(PaymentRequest $data): PaymentResponse
    {
        return new PaymentResponse(
            status: TransactionStatus::Successful,
            type: TransactionType::Payment,
            gatewayName: $this->getName(),
            gatewayTransactionId: 'limited-txn-1',
        );
    }

    public function getName(): string
    {
        return 'limited';
    }
}
