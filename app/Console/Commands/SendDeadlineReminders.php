<?php

namespace App\Console\Commands;

use App\Models\Advertisement;
use App\Models\Application;
use App\Models\EmailLog;
use App\Services\TemplateMailer;
use Illuminate\Console\Command;

/** Reminds candidates with unsubmitted drafts shortly before the advertisement closes. Sent once per draft. */
class SendDeadlineReminders extends Command
{
    protected $signature = 'applications:deadline-reminders {--days=3 : Remind when the advertisement closes within this many days}';

    protected $description = 'Email candidates whose drafts are not submitted as the closing date approaches';

    public function handle(TemplateMailer $mailer): int
    {
        $sent = 0;
        Application::with(['user', 'personal', 'post', 'advertisement'])
            ->where('status', Application::DRAFT)
            ->whereHas('advertisement', fn ($q) => $q->where('status', Advertisement::PUBLISHED)
                ->whereBetween('closes_at', [now(), now()->addDays((int) $this->option('days'))]))
            ->each(function (Application $a) use ($mailer, &$sent) {
                $already = EmailLog::where('template_key', 'deadline_reminder')->where('application_id', $a->id)->exists();
                if ($already) {
                    return;
                }
                $mailer->send('deadline_reminder', $a->user->email, $a->personal?->full_name ?? $a->user->name, [
                    'applicant_name' => $a->personal?->full_name ?? $a->user->name,
                    'post_name' => $a->post->name,
                    'advertisement_number' => $a->advertisement->number,
                    'closes_at' => $a->advertisement->closes_at->format('d M Y, h:i A'),
                    'url' => rtrim(config('recruitment.frontend_url'), '/').'/applications/'.$a->uuid.'/edit',
                ], $a, $a);
                $sent++;
            });
        $this->info("Queued {$sent} deadline reminder(s).");

        return self::SUCCESS;
    }
}
