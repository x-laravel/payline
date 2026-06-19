<?php

namespace XLaravel\Payline;

use Illuminate\Support\Manager;
use XLaravel\Payline\BinLookup\NullBinLookupProvider;
use XLaravel\Payline\Contracts\BinLookupProvider;
use XLaravel\Payline\DTOs\CardProfile;

class BinLookupManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('payline.bin_lookup.default', 'null');
    }

    public function lookup(string $cardNumber): ?CardProfile
    {
        return $this->driver()->lookup(substr($cardNumber, 0, 8));
    }

    protected function createNullDriver(): BinLookupProvider
    {
        return new NullBinLookupProvider();
    }

    protected function callCustomCreator($driver): BinLookupProvider
    {
        $config = $this->config->get("payline.bin_lookup.drivers.{$driver}", []);
        return $this->customCreators[$driver]($this->container, $config);
    }
}