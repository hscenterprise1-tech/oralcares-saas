<?php

namespace App\Services\Platform;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The connection heavy reads go through (ADR-001 §N): reports, exports and
 * trend aggregation. It is the default connection until
 * `database.reporting_connection` names a replica, so callers never decide
 * where reporting load lands — configuration does.
 *
 * Reads only. A report that wrote here would write to a replica.
 */
class ReportingDatabase
{
    public function connection(): ConnectionInterface
    {
        return DB::connection($this->connectionName());
    }

    public function connectionName(): string
    {
        return (string) (config('database.reporting_connection') ?: config('database.default'));
    }
}
