<?php

namespace App\Mail\Pool;

use App\Models\EmailAttempt;
use App\Models\EmailLog;
use Closure;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one logged email through the account pool: claims it (so two workers never send it at
 * once), tries the planned accounts in turn while a failure shows the message was not accepted,
 * records every attempt, and says whether to retry later or give up.
 */
class PooledDelivery
{
    /** A claim older than this is taken to belong to a worker that died. */
    private const STALE_CLAIM_MINUTES = 15;

    public function __construct(private MailPool $pool) {}

    /** @param  Closure(mixed $mailer): mixed  $send  sends the message with the given mailer */
    public function deliver(EmailLog $log, Closure $send, ?string $jobId = null): DeliveryResult
    {
        if (! $this->claim($log, $jobId)) {
            return $log->fresh()?->status === 'sent' ? DeliveryResult::done() : DeliveryResult::retry(60);
        }
        $log->refresh();

        $secrets = MailSecrets::fromConfig();
        $plan = $this->pool->plan();
        $maxAttempts = max(1, (int) config('mail.pool.max_attempts', 5));
        $failure = null;

        foreach ($plan['accounts'] as $i => $account) {
            if ($log->attempts >= $maxAttempts) {
                break;
            }
            // A domain-wide cooldown set by the previous failure may now cover this account too.
            if ($i > 0 && ! $this->pool->isAvailable($account)) {
                continue;
            }

            $started = now();
            $log->forceFill(['attempts' => $log->attempts + 1, 'last_attempt_at' => $started,
                'mail_account' => $account->name, 'sender_address' => $account->fromAddress])->save();
            $this->pool->consume($account);

            try {
                $mailer = Mail::mailer($account->mailer);
                $sent = $send($mailer);
            } catch (Throwable $e) {
                $failure = SmtpFailure::classify($e, $secrets);
                $this->attempt($log, $account, $jobId, $started, 'failed', $failure->type, $failure->code, $failure->message);
                if ($failure->affectsAccount()) {
                    $this->pool->recordFailure($account, $failure);
                }
                if (! $failure->allowsFailover()) {
                    break;
                }

                continue;
            }

            $response = $secrets->redact($this->response($mailer, $sent));
            $this->attempt($log, $account, $jobId, $started, 'sent', null, $response && preg_match('/^(\d{3})\b/', $response, $m) ? (int) $m[1] : null, $response);
            $this->pool->recordSuccess($account);
            $log->forceFill(['status' => 'sent', 'sent_at' => now(), 'error' => null, 'smtp_response' => $response,
                'processing_started_at' => null, 'next_attempt_at' => null])->save();

            return DeliveryResult::sent();
        }

        return $this->notSent($log, $failure, $plan['wait'], $maxAttempts);
    }

    private function claim(EmailLog $log, ?string $jobId): bool
    {
        return EmailLog::whereKey($log->id)
            ->where(fn ($q) => $q->whereIn('status', ['queued', 'retrying', 'rate_limited', 'failed'])
                ->orWhere(fn ($q) => $q->where('status', 'processing')->where(fn ($q) => $q->whereNull('processing_started_at')
                    ->orWhere('processing_started_at', '<=', now()->subMinutes(self::STALE_CLAIM_MINUTES)))))
            ->update(['status' => 'processing', 'processing_started_at' => now(), 'job_id' => $jobId]) === 1;
    }

    private function notSent(EmailLog $log, ?SmtpFailure $failure, int $planWait, int $maxAttempts): DeliveryResult
    {
        if ($failure?->isAmbiguous()) {
            return $this->fail($log, 'Delivery outcome unknown: the connection closed after the whole message was sent, so the server may have accepted it. '
                .'It was not resent automatically, to avoid a duplicate. ('.$failure->message.')');
        }
        if ($failure?->isPermanent()) {
            return $this->fail($log, $failure->message);
        }
        if ($log->attempts >= $maxAttempts) {
            return $this->fail($log, $failure?->message ?? $log->error ?? 'No sending account accepted the message.');
        }

        [$free, $reasons] = $this->pool->availability();
        if ($failure) {
            $backoff = (array) config('mail.pool.retry_backoff', [30, 120, 600, 1800, 3600]);
            $delay = max((int) ($backoff[min($log->attempts, count($backoff)) - 1] ?? 60), $free);
            $status = $failure->isRateLimit() ? 'rate_limited' : 'retrying';
            $error = $failure->message;
        } else {
            // Nothing was tried: every account is resting.
            $delay = $planWait;
            $status = collect($reasons)->contains(fn ($r) => MailPool::isRateReason($r)) ? 'rate_limited' : 'retrying';
            $error = 'No sending account is available until '.now()->addSeconds($delay)->format('d M Y, H:i').' ('
                .collect($reasons)->map(fn ($r, $name) => "$name: ".str_replace('_', ' ', $r))->implode('; ').').';
        }
        $delay = max(10, $delay);
        $log->forceFill(['status' => $status, 'error' => $error, 'next_attempt_at' => now()->addSeconds($delay), 'processing_started_at' => null])->save();

        return DeliveryResult::retry($delay);
    }

    private function fail(EmailLog $log, string $reason): DeliveryResult
    {
        $log->forceFill(['status' => 'failed', 'failed_at' => now(), 'error' => $reason, 'processing_started_at' => null, 'next_attempt_at' => null])->save();

        return DeliveryResult::failed($reason);
    }

    private function attempt(EmailLog $log, MailAccount $account, ?string $jobId, Carbon $started, string $outcome, ?string $type, ?int $code, ?string $response): void
    {
        EmailAttempt::create([
            'email_log_id' => $log->id, 'attempt' => $log->attempts, 'mail_account' => $account->name, 'sender_address' => $account->fromAddress,
            'outcome' => $outcome, 'failure_type' => $type, 'smtp_code' => $code, 'response' => $response, 'job_id' => $jobId,
            'started_at' => $started, 'finished_at' => now(),
        ]);
    }

    /** The server's reply to the end of the message when the transport reports it, else the message ID. */
    private function response(mixed $mailer, mixed $sent): ?string
    {
        if ($mailer instanceof Mailer && ($transport = $mailer->getSymfonyTransport()) instanceof TrackedSmtpTransport && $transport->lastResponse()) {
            return $transport->lastResponse();
        }
        $id = $sent instanceof SentMessage ? $sent->getMessageId() : null;

        return $id ? "Accepted; message ID $id" : null;
    }
}
