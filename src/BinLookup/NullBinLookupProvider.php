<?php

namespace XLaravel\Payline\BinLookup;

use XLaravel\Payline\Contracts\BinLookupProvider;
use XLaravel\Payline\DTOs\CardProfile;

class NullBinLookupProvider implements BinLookupProvider
{
    public function lookup(string $bin): ?CardProfile
    {
        return null;
    }
}
