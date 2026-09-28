<?php

namespace XLaravel\Payline\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;
use XLaravel\Payline\PaylineManager;

class PaylineDoctorCommand extends Command
{
    protected $signature = 'payline:doctor';

    protected $description = 'Validate Payline configuration, gateway registration, and database tables';

    public function handle(PaylineManager $manager): int
    {
        $checks = [
            'Mode' => fn () => $manager->testMode() ? 'test' : 'live',
            'Default gateway' => fn () => $manager->gateway()->getName(),
            'Payments table' => fn () => $this->assertTable('payline_payments'),
            'Transactions table' => fn () => $this->assertTable('payline_transactions'),
            'Webhook logs table' => fn () => $this->assertTable('payline_webhook_logs'),
            'Commission rates table' => fn () => $this->assertTable('payline_commission_rates'),
        ];

        $failed = false;

        foreach ($checks as $label => $check) {
            try {
                $result = $check();
                $this->components->twoColumnDetail($label, '<fg=green>OK</>' . ($result ? " ({$result})" : ''));
            } catch (Throwable $exception) {
                $failed = true;
                $this->components->twoColumnDetail($label, '<fg=red>FAILED</>');
                $this->line("  {$exception->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function assertTable(string $table): string
    {
        $connection = config('payline.database.connection');

        if (! Schema::connection($connection)->hasTable($table)) {
            throw new \RuntimeException("Table [{$table}] does not exist.");
        }

        return $connection;
    }
}
