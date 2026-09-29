<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Restores a dump into the scratch database named by BACKUP_VERIFY_CONNECTION
 * with the `mysql` client, after emptying it. It refuses a connection that
 * points at the same database as the application.
 */
class MysqlRestorer implements DatabaseRestorer
{
    public function __construct(private readonly string $binary) {}

    public function restore(string $sqlPath, string $connection): array
    {
        $config = config("database.connections.{$connection}");
        $appConfig = config('database.connections.'.config('database.default'));

        if (! $config) {
            throw new RuntimeException("Restore connection [{$connection}] is not configured.");
        }

        if (($config['host'] ?? null) === ($appConfig['host'] ?? null) && ($config['database'] ?? null) === ($appConfig['database'] ?? null)) {
            throw new RuntimeException('Refusing to restore into the application database. BACKUP_VERIFY_CONNECTION must point at a separate, disposable database.');
        }

        $scratch = DB::connection($connection);
        $scratch->statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ($scratch->select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()') as $table) {
            $scratch->statement("DROP TABLE IF EXISTS `{$table->name}`");
        }

        $scratch->statement('SET FOREIGN_KEY_CHECKS=1');

        $process = new Process([
            $this->binary,
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            $config['database'],
        ], null, ['MYSQL_PWD' => (string) $config['password']]);
        $process->setInput(fopen($sqlPath, 'rb'));
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Restore failed: '.trim($process->getErrorOutput()));
        }

        $tables = collect($scratch->select('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = ?', ['BASE TABLE']))->pluck('name');

        return $tables->mapWithKeys(fn ($table) => [$table => (int) $scratch->table($table)->count()])->sortKeys()->all();
    }
}
