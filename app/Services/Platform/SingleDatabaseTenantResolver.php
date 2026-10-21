<?php

namespace App\Services\Platform;

use App\Contracts\TenantConnectionResolver;
use InvalidArgumentException;

/**
 * Every clinic on the one primary database — the deliberate current topology
 * (ADR-001 §P: no physical sharding before measurements require it).
 */
class SingleDatabaseTenantResolver implements TenantConnectionResolver
{
    public function connectionFor(int $clinicId): string
    {
        if ($clinicId <= 0) {
            throw new InvalidArgumentException('A tenant connection needs a clinic.');
        }

        return $this->centralConnection();
    }

    public function centralConnection(): string
    {
        return (string) config('database.default');
    }
}
