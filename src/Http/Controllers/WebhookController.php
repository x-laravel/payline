<?php

namespace XLaravel\Payline\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use XLaravel\Payline\DTOs\CallbackData;
use XLaravel\Payline\Enums\WebhookStatus;
use XLaravel\Payline\Events\WebhookReceived;
use XLaravel\Payline\Exceptions\WebhookSignatureException;
use XLaravel\Payline\Models\WebhookLog;
use XLaravel\Payline\PaylineManager;

class WebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, PaylineManager $manager): Response
    {
        $rawBody = $request->getContent();
        $signature = $request->header('X-Webhook-Signature')
            ?? $request->header('X-Gateway-Signature')
            ?? '';

        $gatewayInstance = $manager->driver($gateway);

        /** @var class-string<WebhookLog> $logModel */
        $logModel = config('payline.webhook_log_model', WebhookLog::class);

        $log = $logModel::create([
            'gateway' => $gateway,
            'payload' => $request->all(),
            'status' => WebhookStatus::Received,
        ]);

        try {
            if (! $gatewayInstance->verifyWebhook($request->all(), $signature)) {
                $log->markFailed('Invalid webhook signature');
                abort(403, 'Invalid webhook signature');
            }

            $log->update(['status' => WebhookStatus::Processing]);

            $response = $gatewayInstance->parseWebhook($request->all());

            $log->update([
                'event_type' => $response->gatewayResponseCode,
                'gateway_event_id' => $response->gatewayTransactionId,
            ]);

            event(new WebhookReceived($gateway, $response, $request->all()));

            $callbackData = new CallbackData(
                gateway: $gateway,
                requestData: $request->all(),
                headers: $request->headers->all(),
                rawBody: $rawBody,
            );

            $manager->via($gateway)->handleCallback($callbackData);

            $log->markProcessed();
        } catch (WebhookSignatureException $e) {
            $log->markFailed($e->getMessage());
            abort(403, $e->getMessage());
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }

        return response()->noContent();
    }
}
