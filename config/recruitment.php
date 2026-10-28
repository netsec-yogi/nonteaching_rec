<?php

return [
    // Vocabularies taken from the paper form (items 10, 11, 12).
    'categories' => ['UR' => 'UR (Unreserved)', 'SC' => 'SC', 'ST' => 'ST', 'OBC' => 'OBC (Non-Creamy Layer)', 'EWS' => 'EWS'],
    'genders' => ['male' => 'Male', 'female' => 'Female', 'transgender' => 'Transgender'],
    'marital_statuses' => ['married' => 'Married', 'unmarried' => 'Unmarried'],
    // Item 32 addresses the applicant as Dr/Mr/Mrs/Ms.
    'salutations' => ['Dr', 'Mr', 'Mrs', 'Ms'],

    // Disk for applicant uploads and generated PDFs. Must never be publicly served.
    'documents_disk' => env('RECRUITMENT_DOCUMENTS_DISK', 'private'),

    // Base URL used in emailed links (referee invitations, verification). Defaults to APP_URL.
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),

    'referee' => [
        // Bytes of randomness in each invitation token (hex-encoded in the link).
        'token_bytes' => 32,
        'verification_code_ttl_minutes' => 15,
        'verification_max_attempts' => 5,
    ],

    'clamav' => [
        'host' => env('CLAMAV_HOST'),
        'port' => env('CLAMAV_PORT', 3310),
        'fail_closed' => env('CLAMAV_FAIL_CLOSED', true),
    ],

    'backup' => [
        'path' => env('BACKUP_PATH', storage_path('app/backups')),
        'keep_days' => env('BACKUP_KEEP_DAYS', 30),
        // Extra mysqldump flags, e.g. --ssl-mode=REQUIRED (MySQL client) or
        // --skip-ssl-verify-server-cert (MariaDB client against a self-signed server).
        'dump_options' => env('BACKUP_DUMP_OPTIONS', ''),
    ],
];
