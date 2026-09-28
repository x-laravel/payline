<?php

namespace XLaravel\Payline\Console;

use Illuminate\Console\Command;
use Throwable;
use XLaravel\Payline\Contracts\ProvidesCommissionRates;
use XLaravel\Payline\DTOs\CommissionRateData;
use XLaravel\Payline\Facades\Payline;
use XLaravel\Payline\PaylineManager;

class SyncCommissionRatesCommand extends Command
{
    protected $signature = 'payline:sync-rates {--gateway=*} {--dry-run}';

    protected $description = 'Read commission rates from the gateways that publish them';

    public function handle(PaylineManager $manager): int
    {
        $names = $this->option('gateway') ?: $manager->registeredGateways();
        $failed = false;

        foreach ($names as $name) {
            if (! $manager->hasGateway($name)) {
                $this->components->twoColumnDetail($name, '<fg=red>not registered</>');
                $failed = true;

                continue;
            }

            $gateway = $manager->gateway($name);

            if (! $gateway instanceof ProvidesCommissionRates) {
                $this->components->twoColumnDetail($name, '<fg=gray>no rate service</>');

                continue;
            }

            try {
                $this->sync($name, $gateway->commissionRates());
            } catch (Throwable $exception) {
                report($exception);
                $this->components->twoColumnDetail($name, '<fg=red>failed</>');
                $this->line("  {$exception->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param CommissionRateData[] $rates */
    private function sync(string $gateway, array $rates): void
    {
        if ($this->option('dry-run')) {
            $this->preview($gateway, $rates);

            return;
        }

        $model = Payline::commissionRateModel();
        $created = 0;
        $updated = 0;

        foreach ($rates as $rate) {
            $row = $model::withTrashed()->updateOrCreate(
                $rate->key($gateway),
                $rate->values() + ['deleted_at' => null],
            );

            $row->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->components->twoColumnDetail(
            $gateway,
            "<fg=green>{$created} added</>, {$updated} updated",
        );
    }

    /** @param CommissionRateData[] $rates */
    private function preview(string $gateway, array $rates): void
    {
        $this->components->twoColumnDetail($gateway, '<fg=yellow>' . count($rates) . ' rates, nothing written</>');

        $this->table(
            ['family', 'type', 'installments', 'rate', 'blocking days'],
            array_map(fn (CommissionRateData $rate) => [
                $rate->cardFamily ?? '*',
                $rate->cardType?->value ?? '*',
                $rate->installments,
                $rate->rate,
                $rate->blockingDays ?? '-',
            ], $rates),
        );
    }
}
