<?php

namespace XLaravel\Payline\Contracts;

use XLaravel\Payline\DTOs\IncomingNotification;
use XLaravel\Payline\DTOs\PaymentResponse;

interface HandlesRawWebhooks
{
    public function verifyIncomingNotification(IncomingNotification $notification): bool;

    public function parseIncomingNotification(IncomingNotification $notification): PaymentResponse;
}
