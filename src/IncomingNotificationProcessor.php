<?php

namespace XLaravel\Payline;

use Throwable;
use XLaravel\Payline\Contracts\HandlesRawWebhooks;
use XLaravel\Payline\Contracts\HandlesWebhooks;
use XLaravel\Payline\DTOs\IncomingNotification;
use XLaravel\Payline\Enums\WebhookStatus;
use XLaravel\Payline\Events\WebhookReceived;
use XLaravel\Payline\Exceptions\WebhookSignatureException;
use XLaravel\Payline\Models\WebhookLog;

class IncomingNotificationProcessor
{
    public function __construct(private readonly PaylineManager $manager) {}

    public function process(IncomingNotification $notification): void
    {
        $gateway = $this->manager->driver($notification->gateway);
        $payload = $notification->payload();

        if ($gateway instanceof HandlesRawWebhooks) {
            $verified = $gateway->verifyIncomingNotification($notification);
            $response = $gateway->parseIncomingNotification($notification);
        } elseif ($gateway instanceof HandlesWebhooks
            || (method_exists($gateway, 'verifyWebhook') && method_exists($gateway, 'parseWebhook'))) {
            $verified = $gateway->verifyWebhook($payload, $notification->signature);
            $response = $verified ? $gateway->parseWebhook($payload) : null;
        } else {
            throw new \LogicException("Gateway [{$gateway->getName()}] does not support webhooks.");
        }

        if (! $verified) {
            throw new WebhookSignatureException('Invalid webhook signature.');
        }

        $eventId = $response->gatewayEventId ?? $notification->fingerprint();
        $logModel = config('payline.models.webhook_log', WebhookLog::class);
        $storedPayload = config('payline.storage.webhook_payload', true)
            ? $this->redact($payload)
            : [];

        $log = $logModel::firstOrCreate(
            [
                'gateway' => $notification->gateway,
                'gateway_event_id' => $eventId,
            ],
            [
                'event_type' => $response->eventType,
                'payload_hash' => $notification->fingerprint(),
                'payload' => $storedPayload,
                'status' => WebhookStatus::Received,
            ],
        );

        if (! $log->wasRecentlyCreated
            && in_array($log->status, [WebhookStatus::Processing, WebhookStatus::Processed], true)) {
            return;
        }

        $log->markProcessing($storedPayload, $notification->fingerprint(), $response->eventType);

        try {
            $this->manager
                ->via($notification->gateway)
                ->handleResponse($notification->gateway, $response);

            event(new WebhookReceived($notification->gateway, $response, $storedPayload));
            $log->markProcessed();
        } catch (Throwable $exception) {
            $log->markFailed($exception->getMessage());

            throw $exception;
        }
    }

    private function redact(array $payload): array
    {
        $redactedKeys = array_map(
            'strtolower',
            config('payline.security.redacted_payload_keys', []),
        );

        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $redactedKeys, true)) {
                $payload[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }
}
