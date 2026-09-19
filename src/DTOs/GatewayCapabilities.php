<?php

namespace XLaravel\Payline\DTOs;

use XLaravel\Payline\Enums\PaymentMethod;
use XLaravel\Payline\Enums\TransactionType;

readonly class GatewayCapabilities
{
    public function __construct(
        public array $operations = [],
        public array $methods = [],
        public array $currencies = [],
        public array $installments = [],
        public bool $threeDs = true,
        public bool $nonThreeDs = false,
        public bool $partialRefunds = false,
        public bool $webhooks = false,
        public bool $statusQueries = false,
    ) {}

    public function supports(PaymentRequest $request, TransactionType $operation = TransactionType::Payment): bool
    {
        if ($this->operations !== [] && ! in_array($operation, $this->operations, true)) {
            return false;
        }

        if ($this->methods !== [] && $request->method instanceof PaymentMethod && ! in_array($request->method, $this->methods, true)) {
            return false;
        }

        if ($this->currencies !== [] && ! in_array(strtoupper($request->currency), $this->currencies, true)) {
            return false;
        }

        if ($this->installments !== [] && ! in_array($request->installments ?? 1, $this->installments, true)) {
            return false;
        }

        return $request->threeDs ? $this->threeDs : $this->nonThreeDs;
    }
}
