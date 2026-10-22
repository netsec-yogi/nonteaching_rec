<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * System-wide settings stored as rows. Defaults live here so a fresh install
 * works before anyone visits the settings screen.
 */
class SettingsService
{
    public const DEFAULTS = [
        'institute.name' => 'ABV-Indian Institute of Information Technology and Management, Gwalior',
        'institute.short_name' => 'ABV-IIITM Gwalior',
        'institute.address' => 'Morena Link Road, Gwalior - 474015',
        'institute.recruitment_email' => 'recruitment@iiitm.ac.in',
        'institute.recruitment_phone' => '0751-2449801',
        'declaration.version' => 'NT-2025-09-12',
        'declaration.text' => 'I, hereby, declare that I have carefully read and understood the instructions and particulars supplied to me and that all the entries in this form are true to the best of my knowledge and belief. I also declare that I have not concealed any material information which may debar my candidature for the post applied for. In the event of suppression or distortion of any fact like category or educational qualification etc. made in my application form, I understand that I will be denied any employment in the Institute and if already employed on any of the posts in the Institute, my services will be terminated forthwith.',
        'privacy.notice' => 'The personal information you provide is collected by ABV-IIITM Gwalior solely for processing your application for recruitment. It is accessible only to authorised staff of the Recruitment Section, assigned scrutiny officers and members of the Screening Committee, and is retained in accordance with the Institute\'s approved records-retention schedule.',
        'referee.default_deadline_days' => 14,
        'referee.token_rate_limit_per_minute' => 30,
        'clarification.default_days' => 7,
        'retention.default_years' => 5,
        'uploads.max_kb_default' => 2048,
        'workflow.require_all_referees_before_committee' => false,
        'applicant.max_open_drafts' => 10,
    ];

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : ($default ?? self::DEFAULTS[$key] ?? null);
    }

    public function all(): array
    {
        $stored = Cache::remember('settings.all', 300, fn () => Setting::query()->pluck('value', 'key')->all());

        return array_merge(self::DEFAULTS, $stored);
    }

    public function set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'group' => explode('.', $key)[0]]);
        Cache::forget('settings.all');
    }
}
