<?php

namespace XLaravel\Payline\Concerns;

trait UsesPaylineConnection
{
    public function getConnectionName(): ?string
    {
        return config('payline.database.connection') ?? parent::getConnectionName();
    }
}
