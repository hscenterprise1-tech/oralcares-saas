<?php

/*
|--------------------------------------------------------------------------
| Security infrastructure
|--------------------------------------------------------------------------
|
| One place for the settings behind malware scanning, backups, the security
| audit trail and API rate limits. `php artisan security:production-check`
| reads these and refuses a production deploy that leaves any of them unsafe.
| See docs/security/ for what each one protects.
|
*/

return [

    'malware' => [
        /*
         * `clamav`: a ClamAV daemon reached over TCP, the only real scanner
         * shipped. `fake`: tests and local development only. A production
         * process that resolves the scanner with `fake` configured throws
         * instead of scanning nothing (see AppServiceProvider).
         */
        'driver' => env('MALWARE_SCANNER', 'fake'),

        'clamav' => [
            'host' => env('CLAMAV_HOST', '127.0.0.1'),
            'port' => (int) env('CLAMAV_PORT', 3310),
            // Seconds to connect, and to wait for a verdict, before the scan is
            // treated as failed. A failed scan never admits the file.
            'connect_timeout' => (float) env('CLAMAV_CONNECT_TIMEOUT', 3),
            'read_timeout' => (float) env('CLAMAV_READ_TIMEOUT', 30),
            // Must not exceed clamd's own StreamMaxLength. Larger files are refused.
            'max_bytes' => (int) env('CLAMAV_MAX_BYTES', 25 * 1024 * 1024),
        ],
    ],

    'backup' => [
        'disk' => env('BACKUP_DISK', 'backups'),
        'path' => env('BACKUP_PATH', 'database'),

        /*
         * 32 random bytes, base64-encoded, e.g. `base64:` + 44 chars. Kept
         * outside the app database and outside the backup bucket, because a
         * key stored next to the backups protects nothing.
         */
        'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

        'retention' => [
            'daily' => (int) env('BACKUP_KEEP_DAILY', 35),
            'monthly' => (int) env('BACKUP_KEEP_MONTHLY', 12),
        ],

        /*
         * The connection a restore test imports into. It must be a separate,
         * disposable database. The verify command refuses to run against the
         * default connection.
         */
        'verify_connection' => env('BACKUP_VERIFY_CONNECTION'),

        // Health thresholds the production check enforces.
        'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
        'max_verification_age_days' => (int) env('BACKUP_MAX_VERIFICATION_AGE_DAYS', 35),

        'mysqldump_binary' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),
        'mysql_binary' => env('BACKUP_MYSQL_BINARY', 'mysql'),
    ],

    'audit' => [
        /*
         * Every security event is also written to this log channel. In
         * production the channel must ship off the app server (syslog to a
         * collector, or stderr captured by the platform), so the trail survives
         * a compromise of this server or its database.
         */
        'log_channel' => env('SECURITY_LOG_CHANNEL', 'security'),

        /*
         * HMAC key for the tamper-evident hash chain on `security_audit_logs`.
         * Falls back to APP_KEY. Setting a separate key keeps the chain
         * verifiable across an APP_KEY rotation, and out of reach of anyone
         * who has only the app key.
         */
        'chain_key' => env('SECURITY_AUDIT_CHAIN_KEY'),

        /*
         * How long entries are kept. 180 days is the working target from the
         * CERT-In directions. Confirm with counsel before quoting it as the
         * legal requirement. It applies to the database copy; the external
         * log store keeps its own retention.
         */
        'retention_days' => (int) env('SECURITY_AUDIT_RETENTION_DAYS', 180),
    ],

    /*
     * Requests per minute, per signed-in user (see docs/security/rate-limits.md).
     */
    'rate_limits' => [
        'patient_lookup' => (int) env('RATE_LIMIT_PATIENT_LOOKUP', 60),
        'global_search' => (int) env('RATE_LIMIT_GLOBAL_SEARCH', 60),
        'notifications' => (int) env('RATE_LIMIT_NOTIFICATIONS', 120),
        'geo' => (int) env('RATE_LIMIT_GEO', 120),
    ],

    /*
    | Demo data seeders (fictional HQ staff, expenses, payroll) create login
    | accounts, so they run only in a local or testing environment AND only
    | when this is switched on. Never set it on a server.
    */
    'demo_seeding' => (bool) env('SEED_DEMO_DATA', false),

    /*
    | Owner Mode: the clinic owner's privileged workspace, entered with a
    | password check and held in the session only. It ends after this many
    | minutes without a request, and then needs the password again.
    */
    'owner_mode' => [
        'idle_timeout_minutes' => (int) env('OWNER_MODE_IDLE_MINUTES', 15),
    ],

];
