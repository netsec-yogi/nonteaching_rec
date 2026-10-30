<?php

namespace App\Console\Commands;

use App\Mail\Pool\MailAccount;
use App\Mail\Pool\MailPool;
use App\Mail\Pool\MailSecrets;
use App\Mail\Pool\SmtpFailure;
use App\Mail\TemplatedMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The outgoing account pool from the server: configuration problems, availability, cooldown
 * reset, and a real test message through each account (the only proof an account works).
 * Never prints logins or passwords.
 */
class MailPoolStatus extends Command
{
    protected $signature = 'mail:pool
        {--send-test= : Send a test message to this address through every usable account}
        {--account= : With --send-test or --reset, only this account}
        {--reset : Clear cooldowns so accounts are tried again now}';

    protected $description = 'Show the outgoing mail accounts, reset their cooldowns or send a test message through each';

    public function handle(MailPool $pool): int
    {
        if ($this->option('reset')) {
            $cleared = $pool->reset($this->option('account') ?: null);
            $this->info("Cooldowns cleared ({$cleared} account(s)).");
        }

        $this->line('Pool: '.($pool->enabled() ? 'enabled' : 'disabled').' · strategy: '.$pool->strategy());
        $stats = $pool->stats();
        $this->table(['Account', 'From', 'Server', 'Encryption', 'In use', 'Availability', 'Sent 24h', 'Failed 24h'], array_map(fn ($a) => [
            $a['name'].($a['label'] !== ucfirst($a['name']) ? " ({$a['label']})" : ''),
            $a['from_address'] ?? '—',
            $a['host'] ? "{$a['host']}:{$a['port']}" : '—',
            $a['encryption'] ?? '—',
            $a['active'] ? 'yes' : 'no',
            $a['availability'].($a['available_at'] ? ' until '.$a['available_at'].' ('.$a['unavailable_reason'].')' : ''),
            $a['sent_24h'].($a['daily_limit'] ? " / {$a['daily_limit']}" : ''),
            $a['failed_24h'],
        ], $stats));
        foreach ($stats as $a) {
            foreach ($a['problems'] as $p) {
                $this->error("{$a['name']}: $p");
            }
            foreach ($a['warnings'] as $w) {
                $this->warn("{$a['name']}: $w");
            }
            if ($a['last_error'] && $a['availability'] !== 'available') {
                $this->line("{$a['name']} last error: {$a['last_error']}");
            }
        }

        $to = $this->option('send-test');
        if (! $to) {
            return collect($stats)->contains(fn ($a) => $a['enabled'] && $a['problems']) ? self::FAILURE : self::SUCCESS;
        }
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('Give a valid address for --send-test.');

            return self::INVALID;
        }

        $failed = false;
        $only = $this->option('account');
        // Every enabled, valid account — also before the pool is switched on, so an account can be proved first.
        foreach (array_filter($pool->accounts(), fn (MailAccount $a) => $a->usable() && (! $only || $a->name === $only)) as $account) {
            try {
                Mail::mailer($account->mailer)->to($to)->send(new TemplatedMail(
                    'Test message from '.config('app.name').' ('.$account->label.')',
                    '<p>This message was sent through the <strong>'.e($account->label).'</strong> account ('.e((string) $account->fromAddress).') at '.now()->format('d M Y, H:i:s').'.</p>'
                    .'<p>If it reached your inbox, this account can send mail for the portal.</p>',
                ));
                $this->info("{$account->name}: accepted by the mail server for {$to}. Check that it arrived (acceptance is not delivery).");
            } catch (Throwable $e) {
                $failed = true;
                $failure = SmtpFailure::classify($e, MailSecrets::fromConfig());
                $this->error("{$account->name}: {$failure->type} — {$failure->message}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
