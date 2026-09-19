<?php

namespace XLaravel\Payline\Contracts;

use XLaravel\Payline\DTOs\GatewayCapabilities;

interface ProvidesGatewayCapabilities
{
    public function capabilities(): GatewayCapabilities;
}
