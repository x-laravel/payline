<?php

namespace XLaravel\Payline\Console;

use Illuminate\Console\Command;
use Throwable;
use XLaravel\Payline\Models\Payment;
use XLaravel\Payline\PaylineManager;

class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payline:reconcile {--gateway=} {--limit=100}';

    protected $description = 'Query gateways for pending and unknown payments';

    public function handle(PaylineManager $manager): int
    {
        $model = config('payline.models.payment', Payment::class);
        $query = $model::query()
            ->requiresReconciliation()
            ->oldest('updated_at')
            ->limit(max(1, (int) $this->option('limit')));

        if ($gateway = $this->option('gateway')) {
            $query->where('gateway', $gateway);
        }

        $processed = 0;
        $failed = 0;

        foreach ($query->get() as $payment) {
            try {
                $manager->payment($payment)->reconcile();
                $processed++;
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        $this->components->info("Reconciled {$processed} payments; {$failed} failed or unsupported.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
