<?php

namespace Tests\Feature;

use App\Jobs\SendTemplatedEmail;
use App\Mail\Pool\MailDeliveryFailed;
use App\Mail\Pool\MailPool;
use App\Mail\Pool\MailSecrets;
use App\Mail\Pool\PooledDelivery;
use App\Mail\Pool\SmtpDeliveryException;
use App\Mail\Pool\SmtpFailure;
use App\Mail\TemplatedMail;
use App\Models\EmailAttempt;
use App\Models\EmailLog;
use App\Models\MailAccountHealth;
use App\Models\RefereeInvitation;
use App\Models\Role;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\TestCase;

/**
 * The outgoing account pool, over real SMTP conversations with scripted servers
 * (tests/Support/fake-smtp-server.php): round robin, failover, cooldowns, retries, logging,
 * concurrency across processes, and credentials that never leak.
 */
class MailPoolTest extends TestCase
{
    private const PASSWORDS = ['primary' => 'Primary-Secret-9xQ', 'secondary' => 'Second-Secret-7wP', 'third' => 'Third-Secret-5vR'];

    /** @var list<array{0: resource, 1: array}> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as [$proc, $pipes]) {
            proc_terminate($proc);
            array_map('fclose', $pipes);
            proc_close($proc);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    /** Starts a scripted SMTP server: [port, capture file]. */
    private function smtp(string $behaviour = 'ok'): array
    {
        $capture = tempnam(sys_get_temp_dir(), 'smtp-');
        $proc = proc_open([PHP_BINARY, base_path('tests/Support/fake-smtp-server.php'), $behaviour, $capture], [1 => ['pipe', 'w']], $pipes);
        $this->servers[] = [$proc, $pipes];
        $line = (string) fgets($pipes[1]);
        $this->assertMatchesRegularExpression('/^PORT \d+$/', trim($line), 'The fake SMTP server did not start.');

        return [(int) substr(trim($line), 5), $capture];
    }

    /** A port nothing listens on: connections are refused. */
    private function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /** @param  array<string, int>  $ports  account name => SMTP port (primary first) */
    private function pool(array $ports, string $strategy = 'round_robin', array $overrides = []): void
    {
        $accounts = [];
        foreach ($ports as $name => $port) {
            if ($name !== 'primary') {
                $accounts[$name] = array_replace_recursive([
                    'enabled' => true, 'label' => ucfirst($name), 'host' => '127.0.0.1', 'port' => $port,
                    'username' => "$name@iiitm.test", 'password' => self::PASSWORDS[$name], 'encryption' => 'none',
                    'from' => ['address' => "$name@iiitm.test", 'name' => 'ABV-IIITM Gwalior'], 'timeout' => 5,
                ], $overrides[$name] ?? []);
            }
        }
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => $ports['primary'],
                'username' => 'noreply@iiitm.test', 'password' => self::PASSWORDS['primary'], 'timeout' => 5],
            'mail.from' => ['address' => 'noreply@iiitm.test', 'name' => 'ABV-IIITM Gwalior'],
            'mail.pool.enabled' => true,
            'mail.pool.strategy' => $strategy,
            'mail.pool.primary' => array_merge(['label' => 'Primary'], $overrides['primary'] ?? []),
            'mail.pool.accounts' => $accounts,
        ]);
        Mail::forgetMailers();
    }

    private function resetCounter(): void
    {
        DB::table('atomic_counters')->where('name', MailPool::COUNTER)->update(['value' => 0]);
    }

    /** Logs one email and runs its queued job, as TemplateMailer does: [log, job]. */
    private function send(string $to = 'candidate@example.org'): array
    {
        $log = EmailLog::create(['template_key' => 'pool_test', 'to_email' => $to, 'to_name' => 'Candidate', 'subject' => 'Pool test', 'queued_at' => now()]);

        return [$this->runJob($log)->fresh(['sendAttempts']), $this->lastJob];
    }

    private ?SendTemplatedEmail $lastJob = null;

    /** Runs (or re-runs, as the queue would after a release) the delivery job of a logged email. */
    private function runJob(EmailLog $log): EmailLog
    {
        $this->lastJob = (new SendTemplatedEmail($log->id, $log->to_email, $log->to_name, $log->subject, '<p>Hello from the portal.</p>'))->withFakeQueueInteractions();
        $this->lastJob->handle(app(PooledDelivery::class));

        return $log->fresh(['sendAttempts']);
    }

    /** @return list<array{from: string, rcpt: list<string>, data: string}> */
    private function captured(string $file): array
    {
        return array_map(fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", (string) file_get_contents($file)))));
    }

    private function trail(EmailLog $log): array
    {
        return $log->sendAttempts->map(fn (EmailAttempt $a) => [$a->mail_account, $a->outcome, $a->failure_type])->all();
    }

    // ------------------------------------------------------------------ distribution

    public function test_with_the_pool_off_every_email_uses_the_existing_account(): void
    {
        Mail::fake();
        config(['mail.pool.enabled' => false, 'mail.pool.accounts' => ['secondary' => [
            'enabled' => true, 'host' => '127.0.0.1', 'port' => 2525, 'username' => 'secondary@iiitm.test', 'password' => 'x',
            'encryption' => 'none', 'from' => ['address' => 'secondary@iiitm.test', 'name' => 'X'],
        ]]]);

        foreach (range(1, 3) as $i) {
            [$log] = $this->send("c$i@example.org");
            $this->assertSame(['sent', 'primary', 1], [$log->status, $log->mail_account, $log->attempts]);
        }
        Mail::assertSent(TemplatedMail::class, 3);
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->mailer === config('mail.default'));
        $this->assertSame('single', app(MailPool::class)->strategy());
    }

    public function test_two_accounts_alternate_in_round_robin_each_with_its_own_sender(): void
    {
        [$p, $pCapture] = $this->smtp();
        [$s, $sCapture] = $this->smtp();
        $this->pool(['primary' => $p, 'secondary' => $s]);
        $this->resetCounter();

        $used = [];
        foreach (range(1, 6) as $i) {
            [$log] = $this->send("c$i@example.org");
            $this->assertSame('sent', $log->status);
            $used[] = $log->mail_account;
        }
        $this->assertSame(['primary', 'secondary', 'primary', 'secondary', 'primary', 'secondary'], $used);

        $primary = $this->captured($pCapture);
        $secondary = $this->captured($sCapture);
        $this->assertSame(['c1@example.org', 'c3@example.org', 'c5@example.org'], array_merge(...array_column($primary, 'rcpt')));
        $this->assertSame(['c2@example.org', 'c4@example.org', 'c6@example.org'], array_merge(...array_column($secondary, 'rcpt')));
        foreach ($primary as $m) {
            $this->assertSame('noreply@iiitm.test', $m['from']);
            $this->assertMatchesRegularExpression('/^From: .*<noreply@iiitm\.test>/m', $m['data']);
        }
        foreach ($secondary as $m) {
            $this->assertSame('secondary@iiitm.test', $m['from']);
            $this->assertMatchesRegularExpression('/^From: .*<secondary@iiitm\.test>/m', $m['data']);
        }

        $last = EmailLog::latest('id')->with('sendAttempts')->first();
        $this->assertSame(['secondary@iiitm.test', '250 2.0.0 OK queued as FAKE3'], [$last->sender_address, $last->smtp_response]);
        $this->assertSame([['secondary', 'sent', null]], $this->trail($last));
        $this->assertSame(250, $last->sendAttempts[0]->smtp_code);
    }

    public function test_three_accounts_all_take_part(): void
    {
        [$p] = $this->smtp();
        [$s] = $this->smtp();
        [$t] = $this->smtp();
        $this->pool(['primary' => $p, 'secondary' => $s, 'third' => $t]);
        $this->resetCounter();

        $used = array_map(fn ($i) => $this->send("c$i@example.org")[0]->mail_account, range(1, 6));
        $this->assertSame(['primary', 'secondary', 'third', 'primary', 'secondary', 'third'], $used);
    }

    public function test_the_failover_strategy_keeps_to_the_primary_while_it_works(): void
    {
        [$p] = $this->smtp();
        [$s] = $this->smtp();
        $this->pool(['primary' => $p, 'secondary' => $s], 'failover');
        $this->assertSame(['primary', 'primary', 'primary'], array_map(fn ($i) => $this->send()[0]->mail_account, range(1, 3)));

        $this->pool(['primary' => $this->closedPort(), 'secondary' => $s], 'failover');
        [$log] = $this->send();
        $this->assertSame([['primary', 'failed', 'connection'], ['secondary', 'sent', null]], $this->trail($log));
    }

    public function test_disabled_and_misconfigured_accounts_are_left_out_without_errors(): void
    {
        [$p] = $this->smtp();
        [$s] = $this->smtp();
        $this->pool(['primary' => $p, 'secondary' => $s, 'third' => $s], overrides: [
            'secondary' => ['enabled' => false],
            'third' => ['from' => ['address' => 'third@elsewhere.test']],
        ]);

        $this->assertSame(['primary', 'primary', 'primary'], array_map(fn ($i) => $this->send()[0]->mail_account, range(1, 3)));
        $stats = collect(app(MailPool::class)->stats())->keyBy('name');
        $this->assertSame('disabled', $stats['secondary']['availability']);
        $this->assertSame('misconfigured', $stats['third']['availability']);
        $this->assertStringContainsString('same domain as the login', $stats['third']['problems'][0]);

        $exit = Artisan::call('mail:pool');
        $output = Artisan::output();
        $this->assertSame(1, $exit, 'An enabled account with problems fails the check.');
        $this->assertStringContainsString('third: The From address must be on the same domain', $output);
        $this->assertStringNotContainsString(self::PASSWORDS['third'], $output);
    }

    public function test_production_refuses_unencrypted_or_unauthenticated_accounts_and_shared_logins(): void
    {
        $this->app['env'] = 'production';
        config(['mail.pool.enabled' => true, 'mail.pool.accounts' => [
            'secondary' => ['enabled' => true, 'host' => 'smtp.example.org', 'port' => 587, 'username' => null, 'password' => null,
                'encryption' => 'none', 'from' => ['address' => 'notifications@example.org']],
            'third' => ['enabled' => true, 'host' => 'smtp.example.org', 'port' => 587, 'username' => 'notifications@example.org', 'password' => 'x',
                'encryption' => 'tls', 'from' => ['address' => 'alias@example.org']],
            'fourth' => ['enabled' => true, 'host' => 'smtp.example.org', 'port' => 587, 'username' => 'notifications@example.org', 'password' => 'y',
                'encryption' => 'tls', 'from' => ['address' => 'notifications@example.org']],
        ]]);
        $accounts = collect(app(MailPool::class)->accounts())->keyBy('name');

        $this->assertContains('Unencrypted SMTP is allowed only for a relay on this server.', $accounts['secondary']->problems);
        $this->assertContains('Username and password are required.', $accounts['secondary']->problems);
        $this->assertSame([], $accounts['third']->problems);
        $this->assertStringContainsString('verified "send mail as" address', $accounts['third']->warnings[0]);
        $this->assertStringContainsString('same login as the third account', $accounts['fourth']->problems[0]);
        $this->assertSame(['primary', 'third'], array_map(fn ($a) => $a->name, app(MailPool::class)->candidates()));
    }

    // ------------------------------------------------------------------ failures

    public function test_rejected_credentials_take_an_account_out_and_the_email_goes_through_another(): void
    {
        [$p, $pCapture] = $this->smtp();
        [$s] = $this->smtp('auth_fail');
        $this->pool(['primary' => $p, 'secondary' => $s]);
        $this->resetCounter();

        $this->send('a@example.org');
        [$log, $job] = $this->send('b@example.org'); // the secondary's turn
        $job->assertNotReleased();
        $job->assertNotFailed();
        $this->assertSame(['sent', 'primary', 2], [$log->status, $log->mail_account, $log->attempts]);
        $this->assertSame([['secondary', 'failed', 'auth'], ['primary', 'sent', null]], $this->trail($log));
        $this->assertSame(535, $log->sendAttempts[0]->smtp_code);

        $health = MailAccountHealth::firstWhere('name', 'secondary');
        $this->assertSame('auth', $health->unavailable_reason);
        $this->assertEqualsWithDelta(now()->addSeconds(1800)->timestamp, $health->unavailable_until->timestamp, 5);

        // Out of rotation until the cooldown ends: no further logins are attempted.
        foreach (['c@example.org', 'd@example.org'] as $to) {
            [$next] = $this->send($to);
            $this->assertSame([['primary', 'sent', null]], $this->trail($next));
        }
        $this->assertCount(4, $this->captured($pCapture));
    }

    public function test_credentials_never_reach_storage_logs_or_the_api(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context, JSON_UNESCAPED_SLASHES);
        });
        [$p] = $this->smtp('auth_fail');
        [$s] = $this->smtp('auth_fail');
        $this->pool(['primary' => $p, 'secondary' => $s], overrides: ['primary' => []]);
        config(['mail.pool.max_attempts' => 2]);
        [$log, $job] = $this->send();
        $job->assertFailedWith(MailDeliveryFailed::class);

        $response = $log->sendAttempts[0]->response;
        $this->assertStringContainsString('[login]', $response);
        $this->assertStringContainsString('[redacted]', $response, 'The echoed credential line is removed.');

        $this->actingAs($this->staff(Role::SUPER_ADMIN, 'super@iiitm.test'));
        $api = json_encode([$this->getJson('/api/admin/logs/emails')->assertOk()->json(), $this->getJson('/api/admin/logs/email-accounts')->assertOk()->json()], JSON_UNESCAPED_SLASHES);
        $stored = json_encode([EmailLog::all()->toArray(), EmailAttempt::all()->toArray(), MailAccountHealth::all()->toArray()], JSON_UNESCAPED_SLASHES);

        foreach (['primary' => 'noreply@iiitm.test', 'secondary' => 'secondary@iiitm.test'] as $name => $login) {
            $secret = self::PASSWORDS[$name];
            foreach ([$secret, base64_encode($secret), base64_encode("$login\0$login\0$secret"), base64_encode("\0$login\0$secret")] as $form) {
                $this->assertStringNotContainsString($form, $api);
                $this->assertStringNotContainsString($form, $stored);
                $this->assertStringNotContainsString($form, implode("\n", $logged));
            }
            $this->assertStringNotContainsString("username \"$login\"", $stored.$api.implode("\n", $logged));
        }
        $this->assertStringNotContainsString('"password"', $api);
        $this->assertStringNotContainsString('"username"', $api);
        $this->assertNotEmpty($logged, 'Cooldowns are logged (without credentials).');
    }

    public function test_an_unreachable_primary_fails_over_and_rests_for_longer_each_time(): void
    {
        [$s, $sCapture] = $this->smtp();
        $this->pool(['primary' => $this->closedPort(), 'secondary' => $s]);
        $this->resetCounter();

        [$log] = $this->send();
        $this->assertSame([['primary', 'failed', 'connection'], ['secondary', 'sent', null]], $this->trail($log));
        $this->assertNull($log->sendAttempts[0]->smtp_code);
        $this->assertEqualsWithDelta(now()->addSeconds(60)->timestamp, MailAccountHealth::firstWhere('name', 'primary')->unavailable_until->timestamp, 5);

        [$next] = $this->send();
        $this->assertSame([['secondary', 'sent', null]], $this->trail($next));

        // Back in rotation after the cooldown (the counter only turns while two accounts are available).
        $this->travel(61)->seconds();
        $this->assertSame([['secondary', 'sent', null]], $this->trail($this->send()[0]));
        [$retried] = $this->send(); // the primary's turn: still down
        $this->assertSame([['primary', 'failed', 'connection'], ['secondary', 'sent', null]], $this->trail($retried));
        $health = MailAccountHealth::firstWhere('name', 'primary');
        $this->assertSame(2, $health->consecutive_failures);
        $this->assertEqualsWithDelta(now()->addSeconds(120)->timestamp, $health->unavailable_until->timestamp, 5);
        $this->assertCount(4, $this->captured($sCapture));
    }

    public function test_a_quota_error_rests_the_account_while_the_others_carry_on(): void
    {
        [$p] = $this->smtp();
        [$s] = $this->smtp('quota');
        $this->pool(['primary' => $p, 'secondary' => $s]);
        $this->resetCounter();

        $this->send();
        [$log] = $this->send();
        $this->assertSame([['secondary', 'failed', 'quota'], ['primary', 'sent', null]], $this->trail($log));
        $this->assertSame(550, $log->sendAttempts[0]->smtp_code);
        $this->assertSame('sent', $log->status);

        $stats = collect(app(MailPool::class)->stats())->keyBy('name');
        $this->assertSame(['rate_limited', 'quota'], [$stats['secondary']['availability'], $stats['secondary']['unavailable_reason']]);
        $this->assertSame('available', $stats['primary']['availability']);
        foreach (range(1, 3) as $i) {
            $this->assertSame('primary', $this->send()[0]->mail_account);
        }

        // After the cooldown (quota restored) the account is back in rotation.
        $this->travel(3601)->seconds();
        [$fresh] = $this->smtp();
        $this->pool(['primary' => $p, 'secondary' => $fresh]);
        $used = array_map(fn ($i) => $this->send()[0]->mail_account, range(1, 2));
        $this->assertContains('secondary', $used);
    }

    public function test_when_every_account_is_down_the_email_waits_in_the_queue_and_is_retried(): void
    {
        $deadSecondary = $this->closedPort();
        $this->pool(['primary' => $this->closedPort(), 'secondary' => $deadSecondary]);
        $this->resetCounter();

        [$log, $job] = $this->send();
        $this->assertSame(['retrying', 2], [$log->status, $log->attempts]);
        $job->assertReleased(delay: 120); // the backoff for a second attempt, beyond both 60 s cooldowns
        $this->assertEqualsWithDelta(now()->addSeconds(120)->timestamp, $log->next_attempt_at->timestamp, 5);

        // Released early (e.g. a manual retry): nothing is tried while every account rests.
        $log = $this->runJob($log);
        $this->assertSame(2, $log->attempts);
        $this->lastJob->assertReleased(delay: 60);
        $this->assertStringStartsWith('No sending account is available until', $log->error);

        // Later, with the primary reachable again, the retry goes through.
        $this->travel(121)->seconds();
        [$p, $pCapture] = $this->smtp();
        $this->pool(['primary' => $p, 'secondary' => $deadSecondary]);
        $log = $this->runJob($log);
        $this->assertSame('sent', $log->status);
        $this->assertSame('primary', $log->mail_account);
        $this->assertCount(1, $this->captured($pCapture));
        $this->lastJob->assertNotReleased();
    }

    public function test_an_email_fails_for_good_after_the_attempt_limit(): void
    {
        config(['mail.pool.max_attempts' => 3]);
        $this->pool(['primary' => $this->closedPort(), 'secondary' => $this->closedPort()]);

        [$log, $job] = $this->send();
        $job->assertReleased();
        $this->travel(301)->seconds();
        $log = $this->runJob($log);
        $this->assertSame(['failed', 3], [$log->status, $log->attempts]);
        $this->lastJob->assertFailedWith(MailDeliveryFailed::class);
    }

    public function test_an_ambiguous_failure_is_not_resent_through_another_account(): void
    {
        [$p] = $this->smtp('drop_after_data');
        [$s, $sCapture] = $this->smtp();
        $this->pool(['primary' => $p, 'secondary' => $s]);
        $this->resetCounter();

        [$log, $job] = $this->send();
        $job->assertFailedWith(MailDeliveryFailed::class);
        $job->assertNotReleased();
        $this->assertSame(['failed', 1], [$log->status, $log->attempts]);
        $this->assertSame([['primary', 'failed', 'ambiguous']], $this->trail($log));
        $this->assertStringStartsWith('Delivery outcome unknown', $log->error);
        $this->assertSame([], $this->captured($sCapture));
    }

    public function test_a_domain_rate_limit_rests_every_account_on_that_domain(): void
    {
        [$p] = $this->smtp('rate_4728');
        [$s, $sCapture] = $this->smtp();
        [$t, $tCapture] = $this->smtp();
        $this->pool(['primary' => $p, 'secondary' => $s, 'third' => $t], overrides: [
            'third' => ['username' => 'third@other.test', 'from' => ['address' => 'third@other.test']],
        ]);
        $this->resetCounter();

        [$log] = $this->send();
        $this->assertSame([['primary', 'failed', 'domain_rate_limited'], ['third', 'sent', null]], $this->trail($log));
        $this->assertSame(421, $log->sendAttempts[0]->smtp_code);
        $this->assertSame([], $this->captured($sCapture), 'The other account on the same domain is not used to get round the limit.');
        $this->assertSame('domain_rate_limited', MailAccountHealth::firstWhere('name', 'secondary')->unavailable_reason);
        $this->assertCount(1, $this->captured($tCapture));
    }

    public function test_a_refused_recipient_fails_without_trying_other_accounts(): void
    {
        [$p] = $this->smtp('rcpt_unknown');
        [$s, $sCapture] = $this->smtp();
        $this->pool(['primary' => $p, 'secondary' => $s]);
        $this->resetCounter();

        [$log, $job] = $this->send('nobody@example.org');
        $job->assertFailedWith(MailDeliveryFailed::class);
        $this->assertSame([['primary', 'failed', 'recipient']], $this->trail($log));
        $this->assertSame([], $this->captured($sCapture));
        $this->assertFalse((bool) MailAccountHealth::firstWhere('name', 'primary')?->isUnavailable(), 'A bad address is not the account\'s fault.');
    }

    public function test_a_refused_sender_address_takes_the_account_out(): void
    {
        [$p] = $this->smtp('relay_denied');
        [$s] = $this->smtp();
        $this->pool(['primary' => $p, 'secondary' => $s]);
        $this->resetCounter();

        [$log] = $this->send();
        $this->assertSame([['primary', 'failed', 'sender_rejected'], ['secondary', 'sent', null]], $this->trail($log));
        $this->assertSame('sender_rejected', MailAccountHealth::firstWhere('name', 'primary')->unavailable_reason);
    }

    public function test_per_minute_and_daily_limits_steer_email_to_other_accounts(): void
    {
        [$p] = $this->smtp();
        [$s] = $this->smtp();
        $this->pool(['primary' => $p, 'secondary' => $s], overrides: ['primary' => ['per_minute' => 1], 'secondary' => ['daily_limit' => 2]]);
        $this->resetCounter();

        $this->assertSame(['primary', 'secondary', 'secondary'], array_map(fn ($i) => $this->send()[0]->mail_account, range(1, 3)));
        $stats = collect(app(MailPool::class)->stats())->keyBy('name');
        $this->assertSame([2, 100, true, 'rate_limited'], [$stats['secondary']['sent_24h'], $stats['secondary']['usage_percent'], $stats['secondary']['near_limit'], $stats['secondary']['availability']]);
        $this->assertSame(['rate_limited', 'per_minute_limit'], [$stats['primary']['availability'], $stats['primary']['unavailable_reason']]);

        // Both limited: the email waits rather than exceeding either limit.
        [$log, $job] = $this->send();
        $this->assertSame(['rate_limited', 0], [$log->status, $log->attempts]);
        $job->assertReleased();

        $this->travel(61)->seconds();
        $this->assertSame('sent', $this->runJob($log)->status);
    }

    // ------------------------------------------------------------------ concurrency and workflows

    public function test_round_robin_holds_across_concurrent_workers(): void
    {
        $config = ['mail.default' => 'array', 'mail.pool.enabled' => true, 'mail.pool.strategy' => 'round_robin', 'mail.pool.accounts' => [
            'secondary' => ['enabled' => true, 'host' => '127.0.0.1', 'port' => 2525, 'username' => 'secondary@iiitm.test', 'password' => 'x',
                'encryption' => 'none', 'from' => ['address' => 'secondary@iiitm.test', 'name' => 'X']],
        ]];
        $workers = [];
        foreach (range(1, 4) as $i) {
            $proc = proc_open([PHP_BINARY, base_path('tests/Support/plan-mail-accounts.php'), '100', json_encode($config)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $workers[] = [$proc, $pipes];
        }
        $lines = [];
        foreach ($workers as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            array_map('fclose', $pipes);
            $this->assertSame(0, proc_close($proc), "Worker failed: $err");
            $lines = array_merge($lines, array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", $out))));
        }

        $this->assertCount(400, $lines);
        $sequences = array_column($lines, 'sequence');
        $this->assertCount(400, array_unique($sequences), 'Every email took its own slot.');
        $this->assertSame(399, max($sequences) - min($sequences), 'No slot was skipped.');
        foreach ($lines as $line) {
            $this->assertSame($line['sequence'] % 2 === 1 ? 'primary' : 'secondary', $line['account']);
        }
        $counts = array_count_values(array_column($lines, 'account'));
        ksort($counts); // the counter's starting value decides which account came first
        $this->assertSame(['primary' => 200, 'secondary' => 200], $counts);
    }

    public function test_account_and_referee_emails_go_through_the_pool(): void
    {
        [$p, $pCapture] = $this->smtp();
        [$s, $sCapture] = $this->smtp();
        $this->pool(['primary' => $p, 'secondary' => $s]);
        $this->recruitment();

        $this->applicant('reset.me@example.org');
        $this->postJson('/api/auth/forgot-password', ['email' => 'reset.me@example.org'])->assertOk();
        $this->assertSame('sent', EmailLog::where('template_key', 'password_reset')->value('status'));

        $application = $this->submitted();
        $invitations = RefereeInvitation::where('application_id', $application->id)->get();
        $this->assertCount(3, $invitations);
        $this->assertSame(['sent'], $invitations->pluck('delivery_status')->unique()->values()->all());

        $logs = EmailLog::all();
        $this->assertSame(['sent'], $logs->pluck('status')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['primary', 'secondary'], $logs->pluck('mail_account')->unique()->values()->all());
        $this->assertSame($logs->count(), count($this->captured($pCapture)) + count($this->captured($sCapture)));
        $invite = $this->captured($sCapture)[0]['data'] ?? '';
        $this->assertNotSame('', $invite);
    }

    public function test_monitoring_lists_accounts_and_filters_the_log_for_authorised_staff_only(): void
    {
        [$p] = $this->smtp();
        [$s] = $this->smtp('auth_fail');
        $this->pool(['primary' => $p, 'secondary' => $s]);
        $this->resetCounter();
        $this->send('a@example.org');
        $this->send('b@example.org');

        $this->actingAs($this->staff(Role::SUPER_ADMIN, 'super@iiitm.test'));
        $accounts = $this->getJson('/api/admin/logs/email-accounts')->assertOk()
            ->assertJsonPath('enabled', true)->assertJsonPath('strategy', 'round_robin')->json('accounts');
        $this->assertSame(['primary', 2, 0, 'available'], [$accounts[0]['name'], $accounts[0]['sent_24h'], $accounts[0]['failed_24h'], $accounts[0]['availability']]);
        $this->assertSame(['secondary', 0, 1, 'unavailable', 'auth'], [$accounts[1]['name'], $accounts[1]['sent_24h'], $accounts[1]['failed_24h'], $accounts[1]['availability'], $accounts[1]['unavailable_reason']]);

        $rows = fn (array $q) => $this->getJson('/api/admin/logs/emails?'.http_build_query($q))->assertOk()->json('data');
        $this->assertSame(['b@example.org'], array_column($rows(['mail_account' => 'secondary']), 'to_email'));
        $this->assertSame(['b@example.org'], array_column($rows(['failed_attempts' => 1]), 'to_email'));
        $this->assertCount(2, $rows(['status' => 'sent']));
        $this->assertCount(0, $rows(['date_from' => now()->addDay()->toDateString()]));
        $this->assertSame(['secondary', 'primary'], array_column($rows(['mail_account' => 'secondary'])[0]['send_attempts'], 'mail_account'));

        $this->actingAs($this->applicant())->getJson('/api/admin/logs/email-accounts')->assertForbidden();
        $this->actingAs($this->staff(Role::SCRUTINY_USER, 'scrutiny@iiitm.test'))->getJson('/api/admin/logs/email-accounts')->assertForbidden();
    }

    // ------------------------------------------------------------------ classification

    public static function failureCases(): array
    {
        $unexpected = fn (int $code, string $reply) => new UnexpectedResponseException("Expected response code \"250\" but got code \"$code\", with message \"$reply\".", $code);

        return [
            'refused connection' => [new TransportException('Connection could not be established with host "127.0.0.1:25"'), 'connect', 'connection'],
            'body write failure' => [new TransportException('Unable to write bytes on the wire.'), 'data', 'connection'],
            'no reply after end of data' => [new TransportException('Connection to "127.0.0.1:25" timed out.'), 'end_of_data', 'ambiguous'],
            'gmail daily quota' => [$unexpected(550, '550 5.4.5 Daily user sending limit exceeded.'), 'mail_from', 'quota'],
            'gmail rate limit 4.7.28' => [$unexpected(421, '421 4.7.28 Unusual rate of unsolicited mail'), 'end_of_data', 'domain_rate_limited'],
            'try again later' => [$unexpected(421, '421 4.7.0 Try again later, closing connection.'), 'mail_from', 'rate_limited'],
            'too many logins' => [new TransportException('Failed to authenticate on SMTP server with username "x": 454 4.7.0 Too many login attempts', 454), 'auth', 'rate_limited'],
            'bad credentials' => [new TransportException('Failed to authenticate on SMTP server with username "x": 535 5.7.8', 535), 'auth', 'auth'],
            'tls required' => [new TransportException('TLS required but neither TLS or STARTTLS are in use.'), 'greeting', 'config'],
            'sender not owned' => [$unexpected(553, '553 5.7.1 Sender address rejected: not owned by user'), 'mail_from', 'sender_rejected'],
            'relay denied at rcpt' => [$unexpected(550, '550 5.7.1 Relaying denied'), 'rcpt_to', 'sender_rejected'],
            'unknown recipient' => [$unexpected(550, '550 5.1.1 User unknown'), 'rcpt_to', 'recipient'],
            'recipient busy' => [$unexpected(450, '450 4.2.1 The user is receiving mail too quickly'), 'rcpt_to', 'deferred'],
            'too large' => [$unexpected(552, '552 5.3.4 Message size exceeds fixed limit'), 'end_of_data', 'message'],
            'server error at end of data' => [$unexpected(451, '451 4.3.0 Local error in processing'), 'end_of_data', 'connection'],
            'untracked temporary refusal' => [$unexpected(421, '421 Service not available'), null, 'connection'],
            'untracked permanent refusal' => [$unexpected(550, '550 Rejected'), null, 'other'],
            'not a transport error' => [new RuntimeException('Attachment missing'), null, 'other'],
        ];
    }

    #[DataProvider('failureCases')]
    public function test_failures_are_classified_by_reply_and_stage(\Throwable $e, ?string $stage, string $type): void
    {
        if ($stage !== null) {
            $e = new SmtpDeliveryException($e, $stage);
        }
        $this->assertSame($type, SmtpFailure::classify($e, MailSecrets::fromConfig())->type);
    }

    public function test_secrets_are_redacted_in_every_encoding(): void
    {
        config(['mail.pool.accounts' => ['secondary' => ['username' => 'bulk@iiitm.test', 'password' => 'p@ss w0rd/+=']]]);
        $secrets = MailSecrets::fromConfig();
        $text = 'Failed for username "bulk@iiitm.test": got p@ss w0rd/+= '.base64_encode('p@ss w0rd/+=').' '.rawurlencode('p@ss w0rd/+=')
            .' AUTH PLAIN '.base64_encode("bulk@iiitm.test\0bulk@iiitm.test\0p@ss w0rd/+=");

        $clean = $secrets->redact($text);
        $this->assertStringNotContainsString('p@ss', $clean);
        $this->assertStringNotContainsString('bulk@iiitm.test', $clean);
        $this->assertStringNotContainsString(base64_encode('p@ss w0rd/+='), $clean);
        $this->assertStringContainsString('[login]', $clean);
        $this->assertStringContainsString('AUTH PLAIN [redacted]', $clean);
    }
}
