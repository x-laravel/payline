<?php

namespace XLaravel\Payline\Tests\Fixtures\BinLookup;

use Closure;
use XLaravel\Payline\Contracts\BinLookupProvider;
use XLaravel\Payline\DTOs\CardProfile;

class StubBinLookup implements BinLookupProvider
{
    /** @var string[] */
    public array $asked = [];

    public function __construct(private readonly Closure $answer) {}

    public function lookup(string $bin): ?CardProfile
    {
        $this->asked[] = $bin;

        return ($this->answer)($bin);
    }
}
