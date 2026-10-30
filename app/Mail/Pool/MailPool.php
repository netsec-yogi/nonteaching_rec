<?php

namespace App\Mail\Pool;

use App\Models\EmailAttempt;
use App\Models\MailAccountHealth;
use App\Support\AtomicCounter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The outgoing accounts: the default mailer (primary) plus the SMTP accounts under
 * mail.pool.accounts, each registered as a Laravel mailer ("pool_<name>") with its own From.
 * Decides which accounts may send now and in what order, and records how each one is faring.
 * PooledDelivery sends one email through it.
 */
class MailPool
{
    public const STRATEGIES = ['single', 'round_robin', 'failover'];

    public const COUNTER = 'mail_pool.round_robin';

    /** Reasons an account is out that come from throttling rather than a fault. */
    private const RATE_REASONS = [SmtpFailure::RATE_LIMITED, SmtpFailure::QUOTA, SmtpFailure::DOMAIN_RATE_LIMITED, 'per_minute_limit', 'daily_limit'];

    public function enabled(): bool
    {
        return (bool) config('mail.pool.enabled');
    }

    public function strategy(): string
    {
        $strategy = (string) config('mail.pool.strategy', 'round_robin');

        return ! $this->enabled() ? 'single' : (in_array($strategy, self::STRATEGIES, true) ? $strategy : 'round_robin');
    }

    /** @return list<MailAccount> every configured account, primary first, usable or not */
    public function accounts(): array
    {
        $primary = $this->primary();
        $accounts = [$primary];
        $defaultConfig = (array) config('mail.mailers.'.config('mail.default'), []);
        $logins = filled($defaultConfig['username'] ?? null) ? [mb_strtolower(trim($defaultConfig['username'])) => 'primary'] : [];

        foreach ((array) config('mail.pool.accounts', []) as $name => $c) {
            $name = (string) $name;
            $c = (array) $c;
            $enabled = (bool) ($c['enabled'] ?? false);
            $encryption = mb_strtolower(trim((string) ($c['encryption'] ?? 'tls')));
            [$problems, $warnings] = $enabled ? $this->check($name, $c, $encryption, $logins) : [[], []];
            $mailer = 'pool_'.$name;
            if ($enabled && ! $problems) {
                config(["mail.mailers.$mailer" => [
                    'transport' => 'pool_smtp',
                    'host' => trim((string) $c['host']),
                    'port' => (int) $c['port'],
                    'username' => $c['username'] ?? null,
                    'password' => $c['password'] ?? null,
                    'timeout' => (float) ($c['timeout'] ?? 15),
                    'local_domain' => $defaultConfig['local_domain'] ?? null,
                    'from' => ['address' => trim((string) $c['from']['address']), 'name' => $c['from']['name'] ?? null],
                ] + $this->tlsOptions($encryption)]);
            }
            $accounts[] = new MailAccount(
                name: $name,
                label: (string) ($c['label'] ?? ucfirst($name)),
                mailer: $mailer,
                primary: false,
                enabled: $enabled,
                fromAddress: filled($c['from']['address'] ?? null) ? trim((string) $c['from']['address']) : null,
                fromName: $c['from']['name'] ?? null,
                host: filled($c['host'] ?? null) ? trim((string) $c['host']) : null,
                port: filter_var($c['port'] ?? null, FILTER_VALIDATE_INT) ?: null,
                encryption: $encryption ?: null,
                perMinute: $this->positive($c['per_minute'] ?? null),
                dailyLimit: $this->positive($c['daily_limit'] ?? null),
                problems: $problems,
                warnings: $warnings,
            );
        }

        return $accounts;
    }

    /** @return list<MailAccount> the accounts the strategy may use, primary first */
    public function candidates(): array
    {
        $usable = array_values(array_filter($this->accounts(), fn (MailAccount $a) => $a->usable()));

        return $this->strategy() === 'single' ? array_slice($usable, 0, 1) : $usable;
    }

    /**
     * The accounts to try for one email, in order: round robin starts each email at the next
     * account (a shared counter, safe across workers and servers), failover always at the primary.
     *
     * @return array{accounts: list<MailAccount>, wait: int, sequence: int|null}
     */
    public function plan(): array
    {
        $candidates = $this->candidates();
        $health = $this->health($candidates);
        $available = [];
        $waits = [];
        foreach ($candidates as $account) {
            [$wait] = $this->waitFor($account, $health->get($account->name));
            $wait === 0 ? $available[] = $account : $waits[] = $wait;
        }

        $sequence = null;
        if ($this->strategy() === 'round_robin' && count($available) > 1) {
            $sequence = AtomicCounter::next(self::COUNTER);
            $shift = ($sequence - 1) % count($available);
            $available = array_merge(array_slice($available, $shift), array_slice($available, 0, $shift));
        }

        return ['accounts' => $available, 'wait' => $available ? 0 : ($waits ? min($waits) : 60), 'sequence' => $sequence];
    }

    public function isAvailable(MailAccount $account): bool
    {
        return $this->waitFor($account, MailAccountHealth::firstWhere('name', $account->name))[0] === 0;
    }

    /**
     * How long until some account can send, and why each one that cannot is out.
     *
     * @return array{0: int, 1: array<string, string>}
     */
    public function availability(): array
    {
        $candidates = $this->candidates();
        $health = $this->health($candidates);
        $waits = [];
        $reasons = [];
        foreach ($candidates as $account) {
            [$wait, $reason] = $this->waitFor($account, $health->get($account->name));
            if ($wait === 0) {
                return [0, []];
            }
            $waits[] = $wait;
            $reasons[$account->name] = $reason ?? 'unavailable';
        }

        return [$waits ? min($waits) : 60, $reasons];
    }

    public static function isRateReason(?string $reason): bool
    {
        return in_array($reason, self::RATE_REASONS, true);
    }

    /** Counts a message against the account's per-minute limit. */
    public function consume(MailAccount $account): void
    {
        if ($account->perMinute) {
            RateLimiter::hit($this->rateKey($account), 60);
        }
    }

    public function recordSuccess(MailAccount $account): void
    {
        $now = now();
        MailAccountHealth::query()->upsert(
            [['name' => $account->name, 'consecutive_failures' => 0, 'unavailable_until' => null, 'unavailable_reason' => null,
                'last_success_at' => $now, 'created_at' => $now, 'updated_at' => $now]],
            ['name'],
            ['consecutive_failures', 'unavailable_until', 'unavailable_reason', 'last_success_at', 'updated_at'],
        );
    }

    /** Takes the account out for a cooldown that doubles with each consecutive failure. */
    public function recordFailure(MailAccount $account, SmtpFailure $failure): Carbon
    {
        $until = DB::transaction(function () use ($account, $failure) {
            MailAccountHealth::query()->insertOrIgnore(['name' => $account->name, 'consecutive_failures' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $health = MailAccountHealth::where('name', $account->name)->lockForUpdate()->firstOrFail();
            $health->consecutive_failures++;
            $until = now()->addSeconds($this->cooldownSeconds($failure, $health->consecutive_failures));
            if ($health->unavailable_until?->gt($until)) {
                $until = $health->unavailable_until;
            }
            $health->fill(['unavailable_until' => $until, 'unavailable_reason' => $failure->type,
                'last_error' => $failure->message, 'last_failure_at' => now()])->save();

            return $until;
        });

        // A domain or IP limit binds every account sending from that domain: switching among them would only evade it.
        if ($failure->type === SmtpFailure::DOMAIN_RATE_LIMITED && $account->domain()) {
            foreach ($this->candidates() as $other) {
                if ($other->name !== $account->name && $other->domain() === $account->domain()) {
                    $this->holdUntil($other, $until, $failure);
                }
            }
        }

        Log::warning('Mail account unavailable', ['account' => $account->name, 'reason' => $failure->type,
            'until' => $until->toIso8601String(), 'error' => $failure->message]);

        return $until;
    }

    /** Clears cooldowns (all accounts, or one). */
    public function reset(?string $name = null): int
    {
        return MailAccountHealth::query()->when($name, fn ($q, $n) => $q->where('name', $n))
            ->update(['consecutive_failures' => 0, 'unavailable_until' => null, 'unavailable_reason' => null]);
    }

    /** Per-account monitoring figures. Never includes logins or passwords. */
    public function stats(): array
    {
        $accounts = $this->accounts();
        $inUse = array_map(fn (MailAccount $a) => $a->name, $this->candidates());
        $health = $this->health($accounts);
        $since = now()->subDay();
        $counts = EmailAttempt::query()->whereIn('mail_account', array_map(fn (MailAccount $a) => $a->name, $accounts))
            ->select('mail_account', 'outcome', DB::raw('COUNT(*) as total'), DB::raw('SUM(CASE WHEN started_at >= ? THEN 1 ELSE 0 END) as recent'))
            ->addBinding($since, 'select')
            ->groupBy('mail_account', 'outcome')->get()
            ->groupBy('mail_account');
        $warnAt = (int) config('mail.pool.warn_at_percent', 80);

        return array_map(function (MailAccount $a) use ($inUse, $health, $counts, $warnAt) {
            $h = $health->get($a->name);
            $rows = $counts->get($a->name, collect())->keyBy('outcome');
            $active = in_array($a->name, $inUse, true);
            [$wait, $reason] = $active ? $this->waitFor($a, $h) : [0, null];
            $sent24 = (int) ($rows->get('sent')->recent ?? 0);
            $usage = $a->dailyLimit ? (int) round($sent24 * 100 / $a->dailyLimit) : null;

            return $a->toArray() + [
                'active' => $active,
                'availability' => match (true) {
                    ! $a->enabled => 'disabled',
                    $a->problems !== [] => 'misconfigured',
                    ! $active => 'not_in_use',
                    $wait > 0 => self::isRateReason($reason) ? 'rate_limited' : 'unavailable',
                    default => 'available',
                },
                'unavailable_reason' => $wait > 0 ? $reason : null,
                'available_at' => $wait > 0 ? now()->addSeconds($wait)->toIso8601String() : null,
                'sent_24h' => $sent24,
                'failed_24h' => (int) ($rows->get('failed')->recent ?? 0),
                'sent_total' => (int) ($rows->get('sent')->total ?? 0),
                'failed_total' => (int) ($rows->get('failed')->total ?? 0),
                'usage_percent' => $usage,
                'near_limit' => $usage !== null && $usage >= $warnAt,
                'consecutive_failures' => (int) ($h->consecutive_failures ?? 0),
                'last_success_at' => $h?->last_success_at?->toIso8601String(),
                'last_failure_at' => $h?->last_failure_at?->toIso8601String(),
                'last_error' => $h?->last_error,
            ];
        }, $accounts);
    }

    // ------------------------------------------------------------------ internals

    private function primary(): MailAccount
    {
        $default = (string) config('mail.default');
        $config = (array) config("mail.mailers.$default", []);
        $settings = (array) config('mail.pool.primary', []);
        $encryption = filled($settings['encryption'] ?? null) ? mb_strtolower(trim((string) $settings['encryption'])) : null;
        $smtp = ($config['transport'] ?? null) === 'smtp';

        $mailer = $default;
        if ($this->enabled() && $smtp && empty($config['url'])) {
            // The same account through the stage-tracking transport, so an ambiguous failure is never resent elsewhere.
            $mailer = 'pool_primary';
            config(["mail.mailers.$mailer" => array_merge($config, ['transport' => 'pool_smtp'], $this->tlsOptions($encryption))]);
        }

        $warnings = [];
        if ($smtp && ! in_array($encryption, ['tls', 'ssl'], true) && ($config['scheme'] ?? null) !== 'smtps' && (int) ($config['port'] ?? 0) !== 465) {
            $warnings[] = 'TLS is used only if the server offers STARTTLS; set MAIL_ENCRYPTION=tls to require it.';
        }

        return new MailAccount(
            name: 'primary',
            label: (string) ($settings['label'] ?? 'Primary'),
            mailer: $mailer,
            primary: true,
            enabled: true,
            fromAddress: config('mail.from.address'),
            fromName: config('mail.from.name'),
            host: $smtp ? ($config['host'] ?? null) : null,
            port: $smtp && isset($config['port']) ? (int) $config['port'] : null,
            encryption: $smtp ? ($encryption ?? (($config['scheme'] ?? null) === 'smtps' || (int) ($config['port'] ?? 0) === 465 ? 'ssl' : 'auto')) : $default,
            perMinute: $this->positive($settings['per_minute'] ?? null),
            dailyLimit: $this->positive($settings['daily_limit'] ?? null),
            warnings: $warnings,
        );
    }

    /**
     * Configuration checks for an additional account. An account with problems is left out.
     *
     * @param  array<string, string>  $logins  logins already taken, by account name
     * @return array{0: list<string>, 1: list<string>}
     */
    private function check(string $name, array $c, string $encryption, array &$logins): array
    {
        $problems = [];
        $warnings = [];
        $host = trim((string) ($c['host'] ?? ''));
        $local = in_array(mb_strtolower($host), ['localhost', '127.0.0.1', '::1'], true) || ! app()->isProduction();

        if (! preg_match('/^[a-z][a-z0-9_]{0,30}$/', $name) || $name === 'primary') {
            $problems[] = 'The account name must start with a letter and use only lower-case letters, digits and underscores (and cannot be "primary").';
        }
        if ($host === '') {
            $problems[] = 'Host is not set.';
        }
        if (! filter_var($c['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])) {
            $problems[] = 'Port must be a number from 1 to 65535.';
        }
        if (! in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $problems[] = 'Encryption must be tls, ssl or none.';
        } elseif ($encryption === 'none' && ! $local) {
            $problems[] = 'Unencrypted SMTP is allowed only for a relay on this server.';
        }

        $login = trim((string) ($c['username'] ?? ''));
        if (($login === '' || (string) ($c['password'] ?? '') === '') && ! $local) {
            $problems[] = 'Username and password are required.';
        }

        $from = trim((string) ($c['from']['address'] ?? ''));
        if (! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $problems[] = 'The From address is missing or invalid.';
        } elseif (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            if ($this->domainOf($from) !== $this->domainOf($login)) {
                $problems[] = 'The From address must be on the same domain as the login, and the login must be allowed to send as it.';
            } elseif (mb_strtolower($from) !== mb_strtolower($login)) {
                $warnings[] = 'The From address differs from the login: it must be a verified "send mail as" address of that mailbox.';
            }
        }

        if ($login !== '') {
            $key = mb_strtolower($login);
            if (isset($logins[$key])) {
                $problems[] = "Uses the same login as the {$logins[$key]} account; a second connection to one mailbox adds no sending capacity.";
            } else {
                $logins[$key] = $name;
            }
        }

        return [$problems, $warnings];
    }

    /** Mailer options for the configured encryption: tls requires STARTTLS, ssl is implicit TLS. */
    private function tlsOptions(?string $encryption): array
    {
        return match ($encryption) {
            'tls' => ['scheme' => 'smtp', 'auto_tls' => true, 'require_tls' => true],
            'ssl' => ['scheme' => 'smtps'],
            'none' => ['scheme' => 'smtp', 'auto_tls' => false, 'require_tls' => false],
            default => [],
        };
    }

    /** @return array{0: int, 1: string|null} seconds until the account may send (0 = now) and why */
    private function waitFor(MailAccount $account, ?MailAccountHealth $health): array
    {
        $wait = 0;
        $reason = null;
        if ($health?->isUnavailable()) {
            $wait = max(1, (int) ceil(now()->diffInSeconds($health->unavailable_until)));
            $reason = $health->unavailable_reason;
        }
        if ($account->perMinute && RateLimiter::tooManyAttempts($this->rateKey($account), $account->perMinute)) {
            $seconds = max(1, RateLimiter::availableIn($this->rateKey($account)));
            if ($seconds > $wait) {
                [$wait, $reason] = [$seconds, 'per_minute_limit'];
            }
        }
        if ($account->dailyLimit) {
            $window = EmailAttempt::where('mail_account', $account->name)->where('outcome', 'sent')->where('started_at', '>=', now()->subDay());
            if ((clone $window)->count() >= $account->dailyLimit) {
                // A slot frees when the oldest message in the rolling 24 hours drops out of it.
                $seconds = max(60, (int) ceil(now()->diffInSeconds(Carbon::parse($window->min('started_at'))->addDay())));
                if ($seconds > $wait) {
                    [$wait, $reason] = [$seconds, 'daily_limit'];
                }
            }
        }

        return [$wait, $reason];
    }

    private function holdUntil(MailAccount $account, Carbon $until, SmtpFailure $failure): void
    {
        DB::transaction(function () use ($account, $until, $failure) {
            MailAccountHealth::query()->insertOrIgnore(['name' => $account->name, 'consecutive_failures' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $health = MailAccountHealth::where('name', $account->name)->lockForUpdate()->firstOrFail();
            if (! $health->unavailable_until?->gte($until)) {
                $health->fill(['unavailable_until' => $until, 'unavailable_reason' => $failure->type, 'last_error' => $failure->message])->save();
            }
        });
    }

    private function cooldownSeconds(SmtpFailure $failure, int $consecutive): int
    {
        $base = max(1, (int) config('mail.pool.cooldown.'.$failure->cooldownKey(), 60));
        $max = max($base, (int) config('mail.pool.cooldown.max', 21600));

        return (int) min($max, $base * 2 ** (min($consecutive, 20) - 1));
    }

    /** @param  list<MailAccount>  $accounts */
    private function health(array $accounts): Collection
    {
        return MailAccountHealth::whereIn('name', array_map(fn (MailAccount $a) => $a->name, $accounts))->get()->keyBy('name');
    }

    private function rateKey(MailAccount $account): string
    {
        return 'mail-pool:'.$account->name;
    }

    private function positive(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    }

    private function domainOf(string $address): string
    {
        return mb_strtolower(substr(strrchr($address, '@') ?: '', 1));
    }
}
