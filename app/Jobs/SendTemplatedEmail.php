<?php

namespace App\Jobs;

use App\Mail\TemplatedMail;
use App\Models\EmailLog;
use App\Models\RefereeInvitation;
use App\Services\RefereeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers one logged email. Encrypted because invitation bodies carry the
 * raw referee token, which must not sit readable in jobs/failed_jobs.
 */
class SendTemplatedEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

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

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(): void
    {
        $log = EmailLog::find($this->emailLogId);
        if (! $log) {
            return;
        }

        $log->increment('attempts');

        try {
            Mail::to($this->toEmail, $this->toName)->send(new TemplatedMail($this->subject, $this->html, $this->attachments));
        } catch (Throwable $e) {
            $log->update(['error' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e; // retried with backoff; failed() runs after the last attempt
        }

        $log->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);

        if ($log->related_type === (new RefereeInvitation)->getMorphClass()) {
            app(RefereeService::class)->markDelivered((int) $log->related_id);
        }
    }

    public function failed(Throwable $e): void
    {
        $log = EmailLog::find($this->emailLogId);
        if (! $log) {
            return;
        }
        $log->update(['status' => 'failed', 'failed_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000)]);

        if ($log->related_type === (new RefereeInvitation)->getMorphClass()) {
            app(RefereeService::class)->markDeliveryFailed((int) $log->related_id, $e->getMessage());
        }
    }
}
