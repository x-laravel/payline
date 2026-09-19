<?php

namespace XLaravel\Payline\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use XLaravel\Payline\Contracts\CallbackRedirectResolver;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\Notifications\CallbackHandler;

class CallbackController extends Controller
{
    public function __invoke(
        Request $request,
        string $gateway,
        CallbackHandler $callbacks,
        CallbackRedirectResolver $redirects,
    ): Response {
        $data = new CallbackData(
            gateway: $gateway,
            requestData: array_merge($request->query(), $request->post()),
            headers: $request->headers->all(),
            rawBody: $request->getContent() ?: null,
        );

        $result = $callbacks->handle($data);

        $flash = [
            'payline_status' => $result->response->status->value,
            'payline_payment_id' => $result->transaction?->payment_id,
            'payline_transaction_id' => $result->transaction?->id,
        ];

        $destination = $redirects->resolve($gateway, $result);

        if (! config('payline.routes.callback_breakout', true)) {
            return redirect($destination)->with($flash);
        }

        foreach ($flash as $key => $value) {
            $request->session()->flash($key, $value);
        }

        return response($this->breakoutPage($destination))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function breakoutPage(string $destination): string
    {
        $url = htmlspecialchars($destination, ENT_QUOTES, 'UTF-8');
        $script = json_encode($destination, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <title>Redirecting</title>
        </head>
        <body>
        <script>window.top.location.replace({$script});</script>
        <noscript><a href="{$url}">Continue</a></noscript>
        </body>
        </html>
        HTML;
    }
}
