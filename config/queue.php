<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            /*
             * Above the longest job timeout in the app (ProcessLeadImportJob,
             * 600s) with margin — Laravel's own guidance is to set this to
             * the maximum time a job should reasonably take. At the previous
             * default (90s) a large import still mid-transaction was treated
             * as abandoned and handed to a second worker, which then
             * imported the same file again.
             */
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 900),
            /*
             * A job dispatched inside a transaction is pushed only once that
             * transaction commits, so it can never run against rows that are
             * not there yet — or that a rollback removed.
             */
            'after_commit' => true,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            /*
             * Redis is the production queue. 90s (the framework default) is
             * shorter than the import jobs' 600s timeout, so a long job would
             * be handed to a second worker while still running. Kept equal to
             * the database connection; worker `--timeout` must stay below it.
             */
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 900),
            'block_for' => (int) env('REDIS_QUEUE_BLOCK_FOR', 5),
            'after_commit' => true,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Named Queues (ADR-001 §J)
    |--------------------------------------------------------------------------
    |
    | Jobs pick their queue from this map, never from a literal, so a queue can
    | be re-pointed per environment. Workers list them in priority order:
    |
    |   php artisan queue:work --queue=critical,default,notifications,provisioning,search,imports,reports,maintenance
    |
    | Production runs dedicated worker pools for `imports`/`reports` (long jobs)
    | so they never hold up `critical` mail or interactive work.
    |
    */

    'queues' => [
        'critical' => env('QUEUE_CRITICAL', 'critical'),
        'default' => env('QUEUE_DEFAULT', 'default'),
        'notifications' => env('QUEUE_NOTIFICATIONS', 'notifications'),
        'provisioning' => env('QUEUE_PROVISIONING', 'provisioning'),
        'search' => env('QUEUE_SEARCH', 'search'),
        'imports' => env('QUEUE_IMPORTS', 'imports'),
        'reports' => env('QUEUE_REPORTS', 'reports'),
        'maintenance' => env('QUEUE_MAINTENANCE', 'maintenance'),
    ],

    /*
     * The longest `$timeout` any job declares. `retry_after` on every
     * connection must exceed it (checked by `security:production-check`).
     */
    'max_job_timeout' => 600,

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
