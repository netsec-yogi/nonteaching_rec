<?php

/*
 * System maintenance: database backups and application log management (Administration → System
 * maintenance). The schedule and retention are edited in the portal; these are installation settings.
 */

return [
    'backup' => [
        // Directory for database backups. Never inside public/; created with mode 0700, files 0600.
        'path' => env('BACKUP_PATH', storage_path('app/backups')),

        // Encrypt backup files at rest (XChaCha20-Poly1305, authenticated in 1 MiB chunks).
        'encrypt' => (bool) env('BACKUP_ENCRYPT', true),
        // A dedicated 32-byte key ("base64:…"). Empty: a key derived from APP_KEY. Keep a copy
        // off the server: without it, encrypted backups cannot be restored.
        'key' => env('BACKUP_ENCRYPTION_KEY'),
        // Earlier keys (comma-separated), so backups made before a key change stay restorable.
        'previous_keys' => array_values(array_filter(explode(',', (string) env('BACKUP_PREVIOUS_KEYS', '')))),

        // Extra mysqldump flags, e.g. --ssl-mode=REQUIRED (MySQL client) or
        // --skip-ssl-verify-server-cert (MariaDB client against a self-signed server).
        'dump_options' => env('BACKUP_DUMP_OPTIONS', ''),
        // Extra mysql client flags used when restoring (defaults to the connection flags above).
        'restore_options' => env('BACKUP_RESTORE_OPTIONS', env('BACKUP_DUMP_OPTIONS', '')),
        'mysqldump_binary' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
        'mysql_binary' => env('BACKUP_MYSQL', 'mysql'),

        // Longest a single dump or load may run (seconds).
        'timeout' => (int) env('BACKUP_TIMEOUT', 3600),
        // A running operation that has made no progress for this long is treated as interrupted.
        'stale_after' => (int) env('BACKUP_STALE_AFTER', 600),
        // A queued operation no worker has started within this many seconds is failed.
        'queued_timeout' => (int) env('BACKUP_QUEUED_TIMEOUT', 3600),

        // Default for the retention setting (days) before an administrator changes it.
        'retention_days' => (int) env('BACKUP_KEEP_DAYS', 30),

        // Optional second copy for disaster recovery: the name of a filesystem disk in
        // config/filesystems.php (an S3-compatible bucket, SFTP, or a mounted network share).
        'copy_disk' => env('BACKUP_COPY_DISK'),
        'copy_prefix' => env('BACKUP_COPY_PREFIX', 'ntrec-backups'),
        // Delete the second copy when the local backup is deleted (otherwise the remote store's own
        // lifecycle rules decide, which also protects copies from an account that deletes backups).
        'copy_prune' => (bool) env('BACKUP_COPY_PRUNE', false),

        // Operational tables: their rows are not backed up and a restore leaves them as they are
        // (queued jobs, cache, signed-in sessions and this catalogue of backups and restores). Their
        // structure is in every backup, so a restore into an empty database still works.
        'operational_tables' => ['database_backups', 'backup_restores', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'],
    ],

    'logs' => [
        // Laravel's log directory (storage/logs). Only files directly inside it are managed.
        'path' => env('LOG_MANAGEMENT_PATH', storage_path('logs')),
        // Subdirectory for compressed archives.
        'archive_dir' => 'archive',
        // A file written to within this many minutes counts as active (cannot be archived or deleted).
        'active_minutes' => (int) env('LOG_ACTIVE_MINUTES', 60),
        // Most bytes one viewer request reads; the viewer then offers to continue.
        'scan_bytes' => (int) env('LOG_VIEWER_SCAN_BYTES', 16 * 1024 * 1024),
        // Most bytes read when counting today's errors for the dashboard.
        'stats_scan_bytes' => (int) env('LOG_STATS_SCAN_BYTES', 64 * 1024 * 1024),
        // Largest total size of files packed into one ZIP download.
        'zip_max_bytes' => (int) env('LOG_ZIP_MAX_BYTES', 512 * 1024 * 1024),
        // Longest entry text sent to the browser (characters); the rest is cut.
        'entry_max_chars' => 20000,
    ],
];
