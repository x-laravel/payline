<?php

namespace XLaravel\Payline\Contracts;

use XLaravel\Payline\DTOs\CardProfile;

interface BinLookupProvider
{
    public function lookup(string $bin): ?CardProfile;
}
