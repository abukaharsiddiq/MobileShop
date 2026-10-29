<?php

/*
| Database backup (PHP diye - mysqldump lage na). Gopon kichu (encryption key) ekhane na - shob .env e; .env
| repository te jay na. Nijer theke backup er shomoy/koyta rakhbe - Backups page theke (database e).
|
| - path: storage er bhitore (public na, web theke dekha jay na). Bhalo hoy onno drive/cloud e copy (offsite).
| - encryption_key: `php artisan backup:key` diye banan, .env e BACKUP_ENCRYPTION_KEY, ar alada nirapod jaygay
|   (password manager/kagoje) rakhun - key harale encrypted backup khola jabe na.
*/

return [
    'path' => env('BACKUP_PATH') ?: storage_path('app/private/backups'),

    'encryption_key' => env('BACKUP_ENCRYPTION_KEY') ?: null,

    // kon database connection er backup (khali = app er default)
    'connection' => env('BACKUP_DB_CONNECTION') ?: null,

    // backup e na: login session, cache, queue (cholti kaj), password reset token
    'exclude_tables' => ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'],

    // restore e chhoya hoy na: upore er gulo + backup er talika/niyom (restore er por-o backup gulo dekha jay)
    'keep_on_restore' => ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens', 'backups', 'backup_schedules'],

    // restore er por purono backup er gothon notun code er moto korte
    'migrate_after_restore' => true,

    // restore-check er jonno alada (faka) database; live database er naam hote parbe na
    'verify_database' => env('BACKUP_VERIFY_DATABASE') ?: null,

    'timeout_seconds' => (int) env('BACKUP_TIMEOUT', 1800),
];
