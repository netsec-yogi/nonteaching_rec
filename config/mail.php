<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
            'retry_after' => 60,
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outgoing Account Pool
    |--------------------------------------------------------------------------
    |
    | The default mailer above is the primary account. With MAIL_POOL_ENABLED,
    | the SMTP accounts named in MAIL_POOL_ACCOUNTS (each configured through
    | MAIL_<NAME>_* variables) join it and App\Mail\Pool\MailPool spreads
    | outgoing email across the healthy ones. Adding an account needs only
    | environment variables. Passwords are read here and nowhere else: they
    | are never stored, logged or returned by the API.
    |
    | Strategies: "single" (primary only), "round_robin" (each email to the
    | next healthy account) and "failover" (primary first, the others only
    | when it is unavailable). Cooldowns are in seconds and double with each
    | consecutive failure of an account, up to the maximum.
    |
    */

    'pool' => [
        'enabled' => (bool) env('MAIL_POOL_ENABLED', false),
        'strategy' => env('MAIL_POOL_STRATEGY', 'round_robin'),

        'primary' => [
            'label' => env('MAIL_LABEL', 'Primary'),
            // tls = STARTTLS required, ssl = implicit TLS; unset keeps the mailer's own behaviour.
            'encryption' => env('MAIL_ENCRYPTION'),
            'per_minute' => env('MAIL_PER_MINUTE'),
            'daily_limit' => env('MAIL_DAILY_LIMIT'),
        ],

        'accounts' => (function () {
            $accounts = [];
            foreach (array_filter(array_map('trim', explode(',', (string) env('MAIL_POOL_ACCOUNTS', 'secondary')))) as $name) {
                $prefix = 'MAIL_'.strtoupper($name).'_';
                $accounts[strtolower($name)] = [
                    'enabled' => (bool) env($prefix.'ENABLED', false),
                    'label' => env($prefix.'LABEL', ucfirst(strtolower($name))),
                    'host' => env($prefix.'HOST'),
                    'port' => env($prefix.'PORT', 587),
                    'username' => env($prefix.'USERNAME'),
                    'password' => env($prefix.'PASSWORD'),
                    'encryption' => env($prefix.'ENCRYPTION', 'tls'),
                    'from' => [
                        'address' => env($prefix.'FROM_ADDRESS', env($prefix.'USERNAME')),
                        'name' => env($prefix.'FROM_NAME', env('MAIL_FROM_NAME')),
                    ],
                    'timeout' => env($prefix.'TIMEOUT', 15),
                    'per_minute' => env($prefix.'PER_MINUTE'),
                    'daily_limit' => env($prefix.'DAILY_LIMIT'),
                ];
            }

            return $accounts;
        })(),

        'cooldown' => [
            'connection' => (int) env('MAIL_POOL_COOLDOWN_CONNECTION', 60),
            'rate_limited' => (int) env('MAIL_POOL_COOLDOWN_RATE_LIMITED', 300),
            'quota' => (int) env('MAIL_POOL_COOLDOWN_QUOTA', 3600),
            'auth' => (int) env('MAIL_POOL_COOLDOWN_AUTH', 1800),
            'max' => (int) env('MAIL_POOL_COOLDOWN_MAX', 21600),
        ],

        // Sending attempts per email, and how long a queued email keeps waiting for an account.
        'max_attempts' => (int) env('MAIL_POOL_MAX_ATTEMPTS', 5),
        'retry_for_hours' => (int) env('MAIL_POOL_RETRY_FOR_HOURS', 48),
        // Seconds before retrying an email no account accepted (by attempt number).
        'retry_backoff' => [30, 120, 600, 1800, 3600],
        // The monitoring screen flags an account at this share of its daily limit.
        'warn_at_percent' => (int) env('MAIL_POOL_WARN_AT_PERCENT', 80),
    ],

];
