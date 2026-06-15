<?php

namespace XLaravel\Payline\Contracts;

use Illuminate\Http\Request;

interface WebhookHandler
{
    public function handle(Request $request, string $gateway): void;
}
