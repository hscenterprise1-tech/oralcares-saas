<?php

/*
 * The environment of the MySQL concurrency tests: the PHPUnit run
 * (`phpunit.mysql.xml`) and every worker process it starts.
 *
 * It points every database connection at the isolated database
 * `oralcares_concurrency_test`, signed in as its restricted account, whose
 * credentials live only in the git-ignored `.env.concurrency`. Values set here
 * win over `.env`: Laravel never overwrites an environment variable that is
 * already set. Nothing here proves the connection is the right one; that is
 * `ConcurrencyDatabase::assertIsolated()`, against the live connection.
 *
 * Fails closed: without the secrets file, or with a key missing, the process
 * stops before Laravel boots, so it can never fall back to `.env`'s database.
 */

$secretsFile = dirname(__DIR__, 2).'/.env.concurrency';

if (! is_file($secretsFile)) {
    fwrite(STDERR, "MySQL concurrency tests: {$secretsFile} is missing. Refusing to run.\n");
    exit(78);
}

$secrets = [];
foreach (file($secretsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $secrets[trim($key)] = trim($value);
}

foreach (['CONCURRENCY_DB_USERNAME', 'CONCURRENCY_DB_PASSWORD', 'CONCURRENCY_DB_SERVER_UUID'] as $required) {
    if (($secrets[$required] ?? '') === '') {
        fwrite(STDERR, "MySQL concurrency tests: {$required} is missing from .env.concurrency. Refusing to run.\n");
        exit(78);
    }
}

$database = 'oralcares_concurrency_test';
$values = [
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'METRICS_DRIVER' => 'null',
    'NIGHTWATCH_ENABLED' => 'false',

    'DB_CONNECTION' => 'mysql',
    'DB_URL' => '',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3307',
    'DB_DATABASE' => $database,
    'DB_USERNAME' => $secrets['CONCURRENCY_DB_USERNAME'],
    'DB_PASSWORD' => $secrets['CONCURRENCY_DB_PASSWORD'],
    'DB_READ_HOST' => '',
    // The reporting connection falls back to DB_* unless these are set: pin them too.
    'DB_REPORTING_URL' => '',
    'DB_REPORTING_HOST' => '127.0.0.1',
    'DB_REPORTING_PORT' => '3307',
    'DB_REPORTING_DATABASE' => $database,
    'DB_REPORTING_USERNAME' => $secrets['CONCURRENCY_DB_USERNAME'],
    'DB_REPORTING_PASSWORD' => $secrets['CONCURRENCY_DB_PASSWORD'],

    'CONCURRENCY_DB_SERVER_UUID' => $secrets['CONCURRENCY_DB_SERVER_UUID'],
];

foreach ($values as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
