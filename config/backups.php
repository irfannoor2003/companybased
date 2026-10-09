<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database Backup & Restore
    |--------------------------------------------------------------------------
    |
    | Settings > Backup & Restore shells out to the MySQL client binaries to
    | take and replay full-database dumps. Both paths require a MySQL
    | connection: the suite runs on MySQL too, so the controller refuses any
    | other driver rather than pointing mysqldump at a schema it cannot read.
    |
    | The dump is streamed to disk, so database size is bounded by disk space
    | and the process timeout, not by PHP's memory_limit.
    |
    */

    'mysqldump' => env('MYSQLDUMP_PATH'),

    'mysql' => env('MYSQL_CLIENT_PATH'),

    /*
    | Whether an unusable configured path may fall back to auto-detection.
    |
    | Normal deployments want the fallback: a Windows path left in .env on a
    | Linux host should not stop backups working. Turning it off makes the
    | configured path the only candidate, which is what a test needs in order to
    | exercise the "no binary" failure path.
    */

    'fallback' => (bool) env('MYSQL_BINARY_FALLBACK', true),

    /*
    | How many .sql dumps to keep in the backups disk root. After each
    | successful backup the oldest are deleted, so the directory cannot grow
    | without bound on a long-lived deployment.
    */

    'keep' => (int) env('BACKUP_KEEP', 10),

    /*
    | Ceilings, in seconds, for the client processes. A dump or restore of a
    | large database is slow but must not be allowed to hang a web request
    | forever.
    */

    'dump_timeout' => (int) env('BACKUP_DUMP_TIMEOUT', 1800),

    'restore_timeout' => (int) env('BACKUP_RESTORE_TIMEOUT', 1800),

    /*
    | Largest upload accepted for a restore, in kilobytes.
    */

    'max_upload_kb' => (int) env('BACKUP_MAX_UPLOAD_KB', 512000),

];
