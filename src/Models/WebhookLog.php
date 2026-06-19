<?php

namespace XLaravel\Payline\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use XLaravel\Payline\Enums\WebhookStatus;

class WebhookLog extends Model
{
    use HasUlids;

    protected $table = 'payline_webhook_logs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => WebhookStatus::class,
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function markProcessed(): void
    {
        $this->update([
            'status' => WebhookStatus::Processed,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $exception): void
    {
        $this->update([
            'status' => WebhookStatus::Failed,
            'exception' => $exception,
        ]);
    }
}
