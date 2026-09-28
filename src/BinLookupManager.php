<?php

namespace XLaravel\Payline;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Manager;
use XLaravel\Payline\BinLookup\NullBinLookupProvider;
use XLaravel\Payline\Contracts\BinLookupProvider;
use XLaravel\Payline\DTOs\CardProfile;

class BinLookupManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->providers()[0] ?? 'null';
    }

    /**
     * Every configured provider is asked in order, and a field belongs to the
     * first one that fills it.
     */
    public function lookup(string $cardNumber): ?CardProfile
    {
        $bin = substr($cardNumber, 0, 8);
        $profile = null;

        foreach ($this->providers() as $name) {
            $answer = $this->ask($name, $bin);

            if ($answer === null) {
                continue;
            }

            $profile = $profile?->mergedWith($answer) ?? $answer;
        }

        return $profile;
    }

    /** @return string[] */
    public function providers(): array
    {
        return array_values(array_filter((array) $this->config->get('payline.bin_lookup.providers', [])));
    }

    protected function createNullDriver(): BinLookupProvider
    {
        return new NullBinLookupProvider();
    }

    protected function callCustomCreator($driver): BinLookupProvider
    {
        $config = [
            'test_mode' => (bool) $this->config->get('payline.test_mode', false),
            ...$this->config->get("payline.bin_lookup.drivers.{$driver}", []),
        ];
        return $this->customCreators[$driver]($this->container, $config);
    }

    private function ask(string $name, string $bin): ?CardProfile
    {
        try {
            return $this->driver($name)->lookup($bin);
        } catch (ConnectionException) {
            return null;
        }
    }
}
