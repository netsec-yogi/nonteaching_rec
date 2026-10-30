<?php

namespace App\Jobs;

use App\Mail\Pool\MailDeliveryFailed;
use App\Mail\Pool\MailSecrets;
use App\Mail\Pool\PooledDelivery;
use App\Mail\TemplatedMail;
use App\Models\EmailLog;
use App\Models\RefereeInvitation;
use App\Services\RefereeService;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Throwable;

/**
 * Delivers one logged email through the outgoing account pool (PooledDelivery), which picks
 * the account, fails over and records each attempt. Encrypted because invitation bodies carry
 * the raw referee token, which must not sit readable in jobs/failed_jobs.
 */
class SendTemplatedEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** SMTP timeouts keep one run, even across several accounts, well inside this. */
    public int $timeout = 120;

    public function __construct(
        public int $emailLogId,
        public string $toEmail,
        public ?string $toName,
        public string $subject,
        public string $html,
        public array $attachments = [],
    ) {
        $this->onQueue('mail');
    }

    /**
     * Waiting for an account to come back releases the job without a sending attempt, so the
     * job is bounded by time; sending attempts are capped by mail.pool.max_attempts.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(max(1, (int) config('mail.pool.retry_for_hours', 48)));
    }

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(PooledDelivery $delivery): void
    {
        $log = EmailLog::find($this->emailLogId);
        if (! $log || $log->status === 'sent') {
            return; // already delivered: a retry after a failed status update must not resend
        }

        $result = $delivery->deliver(
            $log,
            fn ($mailer) => $mailer->to($this->toEmail, $this->toName)->send(new TemplatedMail($this->subject, $this->html, $this->attachments)),
            $this->job?->uuid(),
        );

        match ($result->outcome) {
            'sent' => $this->delivered($log),
            'retry' => $this->release($result->delay),
            'failed' => $this->fail(new MailDeliveryFailed($result->reason)),
            default => null,
        };
    }

    public function failed(Throwable $e): void
    {
        $log = EmailLog::find($this->emailLogId);
        if (! $log || $log->status === 'sent') {
            return;
        }
        $reason = $e instanceof MaxAttemptsExceededException
            ? 'Not sent within '.(int) config('mail.pool.retry_for_hours', 48).' hours. Last error: '.($log->error ?? 'none')
            : (string) MailSecrets::fromConfig()->redact($e->getMessage(), 2000);
        $log->forceFill(['status' => 'failed', 'failed_at' => $log->failed_at ?? now(), 'error' => mb_substr($reason, 0, 2000),
            'processing_started_at' => null, 'next_attempt_at' => null])->save();

        if ($log->related_type === (new RefereeInvitation)->getMorphClass()) {
            app(RefereeService::class)->markDeliveryFailed((int) $log->related_id, $reason);
        }
    }

    private function delivered(EmailLog $log): void
    {
        if ($log->related_type === (new RefereeInvitation)->getMorphClass()) {
            app(RefereeService::class)->markDelivered((int) $log->related_id);
        }
    }
}
