<?php

namespace XLaravel\Payline\Concerns;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use XLaravel\Payline\Facades\Payline;

trait InteractsWithPaylineStorage
{
    protected function paymentModel(): string
    {
        return Payline::paymentModel();
    }

    protected function transactionModel(): string
    {
        return Payline::transactionModel();
    }

    protected function connection(): ConnectionInterface
    {
        return DB::connection(config('payline.database.connection'));
    }
}
