<?php

namespace App\Services;

use App\Jobs\SendTemplatedEmail;
use App\Models\Application;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Renders an administrator-editable email template, records it in
 * email_logs and queues delivery. Placeholders are written {{ name }};
 * values are escaped before the Markdown body is converted to HTML.
 */
class TemplateMailer
{
    public function __construct(private SettingsService $settings) {}

    /**
     * @param  array<string, scalar|null>  $vars
     * @param  list<array{disk: string, path: string, name: string, mime: string}>  $attachments
     */
    public function send(
        string $templateKey,
        string $toEmail,
        ?string $toName,
        array $vars,
        ?Model $related = null,
        ?Application $application = null,
        array $attachments = [],
    ): ?EmailLog {
        $template = EmailTemplate::where('key', $templateKey)->first();
        if (! $template || ! $template->is_active) {
            Log::warning("Email template [$templateKey] missing or inactive; nothing sent.", ['to' => $toEmail]);

            return null;
        }

        $vars = $this->commonVars() + $vars;
        $subject = $this->replace($template->subject, $vars, false);
        $html = $this->renderBody($template->body, $vars);

        $log = EmailLog::create([
            'template_key' => $templateKey,
            'to_email' => $toEmail,
            'to_name' => $toName,
            'subject' => Str::limit($subject, 295),
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'application_id' => $application?->id,
            'status' => 'queued',
            'queued_at' => now(),
        ]);

        SendTemplatedEmail::dispatch($log->id, $toEmail, $toName, $subject, $html, $attachments)->afterCommit();

        return $log;
    }

    public function preview(EmailTemplate $template, array $vars = []): array
    {
        $vars = $this->commonVars() + $vars;

        return ['subject' => $this->replace($template->subject, $vars, false), 'html' => $this->renderBody($template->body, $vars)];
    }

    public function renderBody(string $markdown, array $vars): string
    {
        $body = Str::markdown($this->replace($markdown, $vars, true), [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return view('emails.layout', [
            'body' => $body,
            'institute' => $this->settings->get('institute.name'),
            'address' => $this->settings->get('institute.address'),
        ])->render();
    }

    private function replace(string $text, array $vars, bool $forMarkdown): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($vars, $forMarkdown) {
            $value = (string) ($vars[$m[1]] ?? '');
            // Only placeholders that carry links by name (url, …_url, …_link) are inserted raw; a
            // user-supplied value such as a referee's name is always escaped, even if it looks like a URL.
            if (! $forMarkdown || preg_match('/^(url|.*_(url|link))$/i', $m[1])) {
                return $value;
            }

            // Neutralise Markdown and HTML in user-supplied values.
            return addcslashes(e($value), '\\`*_{}[]()#+-.!|>');
        }, $text);
    }

    private function commonVars(): array
    {
        return [
            'institute_name' => $this->settings->get('institute.name'),
            'institute_short_name' => $this->settings->get('institute.short_name'),
            'recruitment_email' => $this->settings->get('institute.recruitment_email'),
            'recruitment_phone' => $this->settings->get('institute.recruitment_phone'),
            'portal_url' => rtrim(config('recruitment.frontend_url'), '/'),
        ];
    }
}
