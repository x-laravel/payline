<?php

namespace XLaravel\Payline\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use XLaravel\Payline\DTOs\IncomingNotification;
use XLaravel\Payline\Exceptions\WebhookSignatureException;
use XLaravel\Payline\IncomingNotificationProcessor;

class WebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $gateway,
        IncomingNotificationProcessor $processor,
    ): Response {
        try {
            $processor->process(IncomingNotification::fromRequest($request, $gateway));
        } catch (WebhookSignatureException $exception) {
            abort(403, $exception->getMessage());
        }

        return response()->noContent();
    }
}
