<?php

namespace XLaravel\Payline\Gateway;

use LogicException;
use XLaravel\Payline\Contracts\Gateway;
use XLaravel\Payline\DTOs\PaymentResponse;
use XLaravel\Payline\Enums\TransactionType;

class GatewayInvoker
{
    public function operation(Gateway $gateway, TransactionType $type, mixed $data): PaymentResponse
    {
        return $this->call($gateway, $type->gatewayContract(), $type->gatewayMethod(), $data);
    }

    public function call(Gateway $gateway, string $contract, string $method, mixed $data): PaymentResponse
    {
        if (! $gateway instanceof $contract) {
            throw new LogicException(sprintf(
                'Gateway [%s] does not implement [%s]. Implement the contract to support [%s].',
                $gateway->getName(),
                $contract,
                $method,
            ));
        }

        return $gateway->{$method}($data);
    }

    public function supports(Gateway $gateway, TransactionType $type): bool
    {
        $contract = $type->gatewayContract();

        return $gateway instanceof $contract;
    }
}
