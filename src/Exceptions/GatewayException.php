<?php

namespace XLaravel\Payline\Exceptions;

class GatewayException extends PaylineException
{
    public function __construct(
        string $message,
        private readonly ?string $gatewayCode = null,
        private readonly ?string $gateway = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getGatewayCode(): ?string
    {
        return $this->gatewayCode;
    }

    public function getGateway(): ?string
    {
        return $this->gateway;
    }
}
