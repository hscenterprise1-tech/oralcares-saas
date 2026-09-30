<?php

namespace App\Services\Backup;

/**
 * Writes a logical dump of the application database and reports what it
 * contains. Swappable, so managed-database tooling can replace mysqldump
 * without touching the backup pipeline.
 */
interface DatabaseDumper
{
    /**
     * Dump the database to `$targetPath` (plain SQL).
     *
     * @return array<string, int> Row count per table, taken from the same
     *                            database the dump was taken from.
     */
    public function dump(string $targetPath): array;
}
