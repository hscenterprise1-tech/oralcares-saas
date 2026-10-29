<?php

namespace Tests\Concurrency\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The hard guard in front of everything the MySQL concurrency tests do to a
 * database: migrating, truncating, seeding, racing.
 *
 * A connection passes only when the live server says, for that very
 * connection, that it is MySQL, the selected database is exactly
 * `oralcares_concurrency_test`, the signed-in account is exactly the
 * restricted `oralcares_ct@localhost`, and the server is the expected one (its
 * `@@server_uuid`, from `.env.concurrency`, and port). Configured names are
 * never trusted on their own, and nothing is matched by substring. Anything it
 * cannot verify fails closed.
 */
final class ConcurrencyDatabase
{
    public const DATABASE = 'oralcares_concurrency_test';

    public const ACCOUNT = 'oralcares_ct@localhost';

    public const PORT = 3307;

    /**
     * @throws UnsafeDatabaseConnection
     */
    public static function assertIsolated(?Connection $connection = null): void
    {
        $connection ??= DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            throw new UnsafeDatabaseConnection("Refusing a {$connection->getDriverName()} connection: the concurrency tests run on MySQL only.");
        }

        $expectedServer = (string) getenv('CONCURRENCY_DB_SERVER_UUID');
        if ($expectedServer === '') {
            throw new UnsafeDatabaseConnection('Refusing: the expected server identity (CONCURRENCY_DB_SERVER_UUID) is not set.');
        }

        try {
            $live = $connection->selectOne('SELECT DATABASE() AS db, CURRENT_USER() AS account, @@server_uuid AS server, @@port AS port');
        } catch (Throwable $e) {
            throw new UnsafeDatabaseConnection('Refusing: the connection could not be verified.', 0, $e);
        }

        $mismatch = match (true) {
            $live === null => 'no answer',
            $live->db !== self::DATABASE => 'database is '.var_export($live->db, true),
            $live->account !== self::ACCOUNT => 'account is '.var_export($live->account, true),
            $live->server !== $expectedServer => 'unexpected server',
            (int) $live->port !== self::PORT => 'unexpected port '.$live->port,
            default => null,
        };

        if ($mismatch !== null) {
            throw new UnsafeDatabaseConnection("Refusing: {$mismatch}; expected exactly ".self::DATABASE.' as '.self::ACCOUNT.'.');
        }
    }

    /**
     * Empty every table except `migrations`, after the guard. Table names are
     * qualified with the isolated database, so a statement can reach no other.
     *
     * @throws UnsafeDatabaseConnection
     */
    public static function truncateAll(?Connection $connection = null): void
    {
        $connection ??= DB::connection();
        self::assertIsolated($connection);

        $tables = $connection->select(
            "SELECT table_name AS name FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE' AND table_name <> 'migrations'",
            [self::DATABASE],
        );

        $connection->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tables as $table) {
                $connection->statement('TRUNCATE TABLE `'.self::DATABASE.'`.`'.str_replace('`', '', $table->name).'`');
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
