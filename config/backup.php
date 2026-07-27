<?php

/*
|--------------------------------------------------------------------------
| SmartPRS backup configuration (spatie/laravel-backup)
|--------------------------------------------------------------------------
| Minimal, version-safe override. We ONLY define the `backup` section (which
| is plain values — no package class names) and deliberately OMIT the
| `notifications`, `monitor_backups` and `cleanup` sections so the installed
| package's OWN, version-correct defaults are used for those (Laravel merges
| any key we don't define from the package's bundled config). This avoids
| hard-coding class paths like DefaultCleanupStrategy that differ between
| package versions and caused an InvalidConfig error on boot.
|
| REQUIREMENT: the PHP `zip` extension must be enabled (backups are .zip
| archives). In Laragon: Menu > PHP > Extensions > php_zip  (or uncomment
| `extension=zip` in php.ini), then restart. Without it backups can't run.
*/

return [

    'backup' => [

        'name' => env('APP_NAME', 'SmartPRS'),

        'source' => [

            'files' => [
                'include' => [
                    base_path(),
                ],
                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    base_path('storage/app'),       // contains the backup zips themselves
                    base_path('storage/framework'),
                    base_path('storage/logs'),
                ],
                'follow_links' => false,
                'ignore_unreadable_directories' => false,
                'relative_path' => null,
            ],

            'databases' => [
                'mysql',
            ],
        ],

        // null = dump the SQL uncompressed (the whole backup is zipped anyway).
        // Avoids referencing a compressor class that may differ across versions.
        'database_dump_compressor' => null,

        'database_dump_file_timestamp_format' => null,

        'database_dump_filename_base' => 'database',

        'database_dump_file_extension' => '',

        'destination' => [

            // 0 == ZipArchive::CM_DEFAULT. Using the integer (not the constant)
            // keeps config-load working even before the zip extension is on.
            'compression_method' => 0,

            'compression_level' => 9,

            'filename_prefix' => 'smartprs-',

            // 'local' (storage/app) works out of the box. For real safety add a
            // remote 'backups' disk (S3/Spaces) in config/filesystems.php and
            // list it here so backups survive a server loss.
            'disks' => [
                'local',
            ],
        ],

        'temporary_directory' => storage_path('app/backup-temp'),

        'password' => env('BACKUP_ARCHIVE_PASSWORD'),

        'encryption' => 'default',

        'tries' => 1,

        'retry_delay' => 0,
    ],

    // notifications / monitor_backups / cleanup intentionally omitted —
    // the installed package supplies its own valid defaults for these.
    // To customise failure emails later: publish the package config with
    //   php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"
    // and set notifications.mail.to to your SMARTPRS_OPS_EMAIL.
];
