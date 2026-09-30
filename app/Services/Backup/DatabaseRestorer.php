<?php

namespace App\Services\Backup;

/**
 * Loads a plain SQL dump into a scratch database for a restore test, and
 * reports what ended up there.
 */
interface DatabaseRestorer
{
    /**
     * @return array<string, int> Row count per table after the restore.
     */
    public function restore(string $sqlPath, string $connection): array;
}
