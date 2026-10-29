<?php

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\Support\ConcurrencyDatabase;
use Tests\Concurrency\Support\UnsafeDatabaseConnection;
use Tests\TestCase;

/**
 * The guard in front of the MySQL concurrency tests, proved against live
 * connections: it accepts only the isolated database as the restricted
 * account on the expected server, and nothing destructive runs when it
 * refuses. The restricted account cannot reach the development database at
 * all.
 */
uses(TestCase::class)->group('mysql-concurrency');

/** The restricted account's own connection settings, aimed at another database. */
function guardProbe(string $database): Connection
{
    config(['database.connections.guard_probe' => ['database' => $database] + config('database.connections.mysql')]);
    DB::purge('guard_probe');

    return DB::connection('guard_probe');
}

/** The message of whatever the callback throws, or null. */
function thrownMessage(callable $callback): ?string
{
    try {
        $callback();
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return null;
}

test('a connection to another database is refused, by what the server reports', function () {
    expect(fn () => ConcurrencyDatabase::assertIsolated(guardProbe('information_schema')))
        ->toThrow(UnsafeDatabaseConnection::class, "database is 'information_schema'");
});

test('a SQLite connection is refused', function () {
    config(['database.connections.guard_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    expect(fn () => ConcurrencyDatabase::assertIsolated(DB::connection('guard_sqlite')))
        ->toThrow(UnsafeDatabaseConnection::class, 'Refusing a sqlite connection');
});

test('an unexpected server, or no expected server at all, is refused', function () {
    $expected = getenv('CONCURRENCY_DB_SERVER_UUID');

    try {
        putenv('CONCURRENCY_DB_SERVER_UUID=00000000-0000-0000-0000-000000000000');
        expect(fn () => ConcurrencyDatabase::assertIsolated())->toThrow(UnsafeDatabaseConnection::class, 'unexpected server');

        putenv('CONCURRENCY_DB_SERVER_UUID=');
        expect(fn () => ConcurrencyDatabase::assertIsolated())->toThrow(UnsafeDatabaseConnection::class, 'expected server identity');
    } finally {
        putenv("CONCURRENCY_DB_SERVER_UUID={$expected}");
    }
});

test('the restricted account cannot reach the development database', function () {
    expect(thrownMessage(fn () => guardProbe('oralcares_saas')->select('SELECT 1')))->toContain('Access denied')
        ->and(thrownMessage(fn () => guardProbe('information_schema')->select('SELECT COUNT(*) FROM oralcares_saas.clinics')))->toContain('denied');

    // The only application schema it can even see is its own.
    $visible = collect(guardProbe('information_schema')->select('SELECT schema_name AS name FROM information_schema.schemata'))->pluck('name')->all();
    expect($visible)->not->toContain('oralcares_saas');
});

test('cleanup runs nothing when the guard refuses the connection', function () {
    $probe = guardProbe('information_schema');
    $statements = [];
    $probe->listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });

    expect(fn () => ConcurrencyDatabase::truncateAll($probe))->toThrow(UnsafeDatabaseConnection::class);

    // Only the guard's own identity check reached the server: no SET, no TRUNCATE.
    expect($statements)->toHaveCount(1)
        ->and($statements[0])->toStartWith('SELECT DATABASE() AS db');
});

test('the default connection is the isolated database, as the restricted account', function () {
    ConcurrencyDatabase::assertIsolated();

    $live = DB::selectOne('SELECT DATABASE() AS db, CURRENT_USER() AS account');
    expect($live->db)->toBe('oralcares_concurrency_test')
        ->and($live->account)->toBe('oralcares_ct@localhost');
});
