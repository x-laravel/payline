<?php

namespace XLaravel\Payline\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\PaylineManager;

class CallbackController extends Controller
{
    public function __invoke(Request $request, string $gateway, PaylineManager $manager): RedirectResponse
    {
        $data = new CallbackData(
            gateway: $gateway,
            requestData: array_merge($request->query(), $request->post()),
            headers: $request->headers->all(),
            rawBody: $request->getContent() ?: null,
        );

        $result = $manager->via($gateway)->handleCallback($data);

        $flash = [
            'payline_status' => $result->response->status->value,
            'payline_payment_id' => $result->transaction?->payment_id,
            'payline_transaction_id' => $result->transaction?->id,
        ];

        if ($result->response->isSuccessful()) {
            $url = config("payline.gateways.{$gateway}.callback_success_url")
                ?? config('payline.callback_success_url', '/');

            return redirect($url)->with($flash);
        }

        $url = config("payline.gateways.{$gateway}.callback_failure_url")
            ?? config('payline.callback_failure_url', '/');

        return redirect($url)->with($flash);
    }
}
