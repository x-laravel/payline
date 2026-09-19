<?php

namespace XLaravel\Payline\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\Contracts\CallbackRedirectResolver;
use XLaravel\Payline\PaylineManager;

class CallbackController extends Controller
{
    public function __invoke(
        Request $request,
        string $gateway,
        PaylineManager $manager,
        CallbackRedirectResolver $redirects,
    ): RedirectResponse {
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

        return redirect($redirects->resolve($gateway, $result))->with($flash);
    }
}
