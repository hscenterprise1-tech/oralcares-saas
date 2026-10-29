<?php

/*
 * Prepare the isolated MySQL concurrency database:
 *
 *   php tests/Concurrency/prepare.php
 *
 * Signed in as the restricted account (which can reach no other database), it
 * checks the server, creates `oralcares_concurrency_test` only if no database
 * of that name exists, then boots the application, passes the guard against
 * the live connection, and runs the project's migrations there. It never
 * drops, resets or repurposes anything; an existing database stops it, unless
 * it is already this project's migrated test database (`--continue`).
 */

use Illuminate\Contracts\Console\Kernel;
use Tests\Concurrency\Support\ConcurrencyDatabase;

require __DIR__.'/environment.php';
require dirname(__DIR__, 2).'/vendor/autoload.php';

$continue = in_array('--continue', $argv, true);
$database = ConcurrencyDatabase::DATABASE;

$server = new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$identity = $server->query('SELECT CURRENT_USER() AS account, @@server_uuid AS server, @@port AS port')->fetch(PDO::FETCH_ASSOC);
if ($identity['account'] !== ConcurrencyDatabase::ACCOUNT || $identity['server'] !== getenv('CONCURRENCY_DB_SERVER_UUID') || (int) $identity['port'] !== ConcurrencyDatabase::PORT) {
    fwrite(STDERR, "STOP: unexpected account or server.\n");
    exit(1);
}

$exists = (int) $server->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '{$database}'")->fetchColumn() > 0;
if ($exists && ! $continue) {
    fwrite(STDERR, "STOP: {$database} already exists. Nothing was changed.\n");
    exit(1);
}
if (! $exists) {
    $server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "created database {$database}\n";
}
$server = null;

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$console = $app->make(Kernel::class);
$console->bootstrap();

ConcurrencyDatabase::assertIsolated();
echo 'guard passed: '.ConcurrencyDatabase::DATABASE.' as '.ConcurrencyDatabase::ACCOUNT."\n";

$status = $console->call('migrate', ['--force' => true]);
echo $console->output();
exit($status);
