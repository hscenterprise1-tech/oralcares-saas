<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Patient documents, X-rays and anything else clinical. Never public:
         * files leave this disk only through an authorized, audited
         * controller. Production sets PRIVATE_FILESYSTEM_DRIVER=s3, with a
         * bucket that blocks public access, has server-side encryption and
         * versioning. Uploads were already written here, but the disk was
         * never defined, so a real (non-faked) upload failed with "Disk
         * [private] does not have a configured driver".
         */
        'private' => [
            'driver' => env('PRIVATE_FILESYSTEM_DRIVER', 'local'),
            'root' => storage_path('app/private-documents'),
            'key' => env('PRIVATE_AWS_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('PRIVATE_AWS_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('PRIVATE_AWS_DEFAULT_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('PRIVATE_AWS_BUCKET'),
            'endpoint' => env('PRIVATE_AWS_ENDPOINT'),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        /*
         * Where an upload waits while it is scanned for malware. It sits on the
         * app server, is never served, and is emptied as each scan finishes:
         * a clean file moves to `private`, anything else is deleted.
         */
        'quarantine' => [
            'driver' => 'local',
            'root' => storage_path('app/quarantine'),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        /*
         * Encrypted database backups. Production points this at a bucket in a
         * separate account (BACKUP_AWS_*) with versioning and object lock, so
         * a compromise of the app's own credentials cannot delete backups.
         */
        'backups' => [
            'driver' => env('BACKUP_FILESYSTEM_DRIVER', 'local'),
            'root' => storage_path('app/backups'),
            'key' => env('BACKUP_AWS_ACCESS_KEY_ID'),
            'secret' => env('BACKUP_AWS_SECRET_ACCESS_KEY'),
            'region' => env('BACKUP_AWS_DEFAULT_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('BACKUP_AWS_BUCKET'),
            'endpoint' => env('BACKUP_AWS_ENDPOINT'),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
