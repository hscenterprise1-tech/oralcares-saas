<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * `mysqldump --single-transaction`: a consistent logical snapshot of an InnoDB
 * database without locking it. The password is passed in MYSQL_PWD, never on
 * the command line, where other users on the host could read it from the
 * process list.
 *
 * Row counts for the manifest are read just before the dump starts. On a busy
 * database they can drift slightly from the dump's own snapshot, and the
 * restore test then reports the difference per table (docs/security/backups.md).
 */
class MysqlDumper implements DatabaseDumper
{
    public function __construct(
        private readonly string $connection,
        private readonly string $binary,
    ) {}

    public function dump(string $targetPath): array
    {
        $config = config("database.connections.{$this->connection}");

        if (($config['driver'] ?? null) !== 'mysql' && ($config['driver'] ?? null) !== 'mariadb') {
            throw new RuntimeException("Connection [{$this->connection}] is not MySQL/MariaDB; mysqldump cannot back it up.");
        }

        $counts = $this->rowCounts();

        $process = new Process([
            $this->binary,
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--hex-blob',
            '--no-tablespaces',
            '--result-file='.$targetPath,
            $config['database'],
        ], null, ['MYSQL_PWD' => (string) $config['password']]);
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysqldump failed: '.trim($process->getErrorOutput()));
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $db = DB::connection($this->connection);
        $tables = collect($db->select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = ?', ['BASE TABLE']))
            ->pluck('name');

        return $tables->mapWithKeys(fn ($table) => [$table => (int) $db->table($table)->count()])->sortKeys()->all();
    }
}
