<?php

namespace App\Services;

use App\Models\Advertisement;
use App\Models\Application;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffAlert;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Records retention. Two deliberately separate steps:
 *
 *  review()  — scheduled. Finds closed advertisements whose retention period (counted from the
 *              closing date; the advertisement's own value or Settings → retention.default_years)
 *              has ended, archives them and flags them as due. It never deletes anything.
 *
 *  dispose() — run by a person, from the console, with the advertisement number typed again as
 *              confirmation. Removes stored files and personal data of every application under the
 *              advertisement, keeping what proves the recruitment outcome: reference numbers,
 *              statuses, decisions, scrutiny and committee records, status history and the audit
 *              trail. Payment amounts and statuses are kept (financial records follow their own
 *              rules); category and gender are kept for statistics.
 *
 * Application versions, referee responses and amendments refuse model updates by design, so
 * disposal writes through the query builder. That is the only place this is legitimate; every
 * disposal is journalled to storage/logs/retention.log and written to the audit log.
 */
class RetentionService
{
    private const REDACTED = '[disposed]';

    public function __construct(private SettingsService $settings, private AuditLogger $audit, private TemplateMailer $mailer) {}

    public function retentionEnds(Advertisement $ad): Carbon
    {
        $years = $ad->retention_years ?: (int) $this->settings->get('retention.default_years');

        return $ad->closes_at->copy()->addYears($years);
    }

    /** @return Collection<int, Advertisement> advertisements newly flagged as due */
    public function review(): Collection
    {
        $due = Advertisement::query()
            ->whereIn('status', [Advertisement::CLOSED, Advertisement::ARCHIVED])
            ->whereNull('retention_due_at')
            ->whereNull('disposed_at')
            ->get()
            ->filter(fn (Advertisement $ad) => $this->retentionEnds($ad)->isPast())
            ->values();

        foreach ($due as $ad) {
            DB::transaction(function () use ($ad) {
                $from = $ad->status;
                $ad->forceFill([
                    'status' => Advertisement::ARCHIVED,
                    'archived_at' => $ad->archived_at ?? now(),
                    'retention_due_at' => now(),
                ])->save();
                $this->audit->record('retention.due', $ad, ['status' => $from], ['status' => Advertisement::ARCHIVED],
                    ['retention_ended' => $this->retentionEnds($ad)->toDateString()], actorType: 'system', actorLabel: 'scheduler');
            });
        }

        if ($due->isNotEmpty()) {
            $admins = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('slug', Role::SUPER_ADMIN))->get();
            $list = $due->map(fn (Advertisement $ad) => "- {$ad->number} — {$ad->title} (closed {$ad->closes_at->format('d M Y')}, retention ended {$this->retentionEnds($ad)->format('d M Y')})")->implode("\n");
            foreach ($admins as $admin) {
                $this->mailer->send('retention_due', $admin->email, $admin->name, [
                    'admin_name' => $admin->name, 'count' => $due->count(), 'advertisements' => $list,
                ]);
            }
            Notification::send($admins, new StaffAlert('Retention period ended', $due->count().' advertisement(s) archived and due for disposal review.', '/admin/advertisements'));
        }

        return $due;
    }

    /** @return array<string, int> counts of what was removed */
    public function dispose(Advertisement $ad, string $authorisedBy, ?User $operator = null): array
    {
        if ($ad->disposed_at) {
            throw new RuntimeException("Advertisement {$ad->number} was already disposed of on {$ad->disposed_at->format('d M Y')}.");
        }
        if ($ad->status !== Advertisement::ARCHIVED || ! $ad->retention_due_at) {
            throw new RuntimeException("Advertisement {$ad->number} is not archived and due for disposal. Run recruitment:retention-review first.");
        }

        $applications = Application::withTrashed()->where('advertisement_id', $ad->id)->get(['id', 'uuid']);
        $ids = $applications->pluck('id');
        $disk = Storage::disk(config('recruitment.documents_disk'));
        $counts = ['applications' => $ids->count()];

        // Files first: if this fails part-way, re-running finishes the job (deletes are idempotent).
        $counts['file_folders'] = 0;
        foreach ($applications as $a) {
            if ($disk->exists('applications/'.$a->uuid)) {
                $disk->deleteDirectory('applications/'.$a->uuid);
                $counts['file_folders']++;
            }
        }

        DB::transaction(function () use ($ad, $ids, $authorisedBy, $operator, &$counts) {
            $counts['documents'] = DB::table('application_documents')->whereIn('application_id', $ids)->delete();
            $counts['qualifications'] = DB::table('application_qualifications')->whereIn('application_id', $ids)->delete();
            $counts['employments'] = DB::table('application_employments')->whereIn('application_id', $ids)->delete();
            DB::table('application_merit_records')->whereIn('application_id', $ids)->delete();
            DB::table('application_trainings')->whereIn('application_id', $ids)->delete();
            DB::table('application_memberships')->whereIn('application_id', $ids)->delete();
            DB::table('application_addresses')->whereIn('application_id', $ids)->delete();
            DB::table('employer_endorsements')->whereIn('application_id', $ids)->delete();

            DB::table('application_personal_details')->whereIn('application_id', $ids)->update([
                'full_name' => self::REDACTED, 'relation_name' => null, 'date_of_birth' => null, 'place_of_birth' => null,
                'mobile' => null, 'email' => null, 'disability_type' => null, 'disability_percentage' => null,
                'nearest_railway_station' => null, 'ex_serviceman_category' => null, 'ex_serviceman_details' => null,
            ]);
            DB::table('applications')->whereIn('id', $ids)->update([
                'suitability_statement' => null, 'other_achievements' => null, 'declaration_place' => null,
                'declaration_ip' => null, 'withdrawal_reason' => null, 'referee_exception_note' => null,
            ]);
            DB::table('application_declarations')->whereIn('application_id', $ids)->update(['details' => null]);
            DB::table('payments')->whereIn('application_id', $ids)->update(['utr_number' => null, 'bank_name' => null]);
            DB::table('clarification_responses')
                ->whereIn('clarification_request_id', DB::table('clarification_requests')->whereIn('application_id', $ids)->select('id'))
                ->update(['message' => self::REDACTED]);

            $counts['referees'] = DB::table('referees')->whereIn('application_id', $ids)->update([
                'name' => self::REDACTED, 'designation' => self::REDACTED, 'organization' => self::REDACTED,
                'address' => self::REDACTED, 'phone' => self::REDACTED, 'email' => self::REDACTED, 'relationship' => null,
            ]);
            DB::table('referee_invitations')->whereIn('application_id', $ids)->update(['email' => self::REDACTED, 'token_encrypted' => null]);
            $counts['recommendations'] = DB::table('referee_responses')->whereIn('application_id', $ids)->update([
                'referee_name' => self::REDACTED, 'referee_designation' => self::REDACTED, 'referee_organization' => self::REDACTED,
                'referee_email' => self::REDACTED, 'referee_phone' => null, 'relationship' => self::REDACTED,
                'known_duration' => self::REDACTED, 'nature_of_association' => null, 'candidate_entered' => '{}', 'answers' => '[]',
                'comments' => null, 'pdf_disk' => null, 'pdf_path' => null, 'ip_address' => null, 'user_agent' => null,
            ]);
            DB::table('referee_response_amendments')
                ->whereIn('referee_response_id', DB::table('referee_responses')->whereIn('application_id', $ids)->select('id'))
                ->update(['original_value' => null, 'amended_value' => null]);

            foreach (DB::table('application_versions')->whereIn('application_id', $ids)->get(['id', 'version']) as $v) {
                DB::table('application_versions')->where('id', $v->id)->update([
                    'snapshot' => json_encode(['disposed' => true, 'version' => $v->version, 'disposed_on' => now()->toDateString()]),
                    'pdf_disk' => null, 'pdf_path' => null,
                ]);
            }
            DB::table('email_logs')->whereIn('application_id', $ids)->update(['to_email' => self::REDACTED, 'to_name' => null, 'subject' => self::REDACTED]);
            // The audit trail keeps who did what and when; the quoted values (names, emails, reasons) go.
            DB::table('audit_logs')->whereIn('application_id', $ids)->update(['old_values' => null, 'new_values' => null, 'meta' => json_encode(['disposed' => true]), 'ip_address' => null, 'user_agent' => null]);
            DB::table('document_access_logs')->whereIn('application_id', $ids)->update(['ip_address' => null]);

            $ad->forceFill(['disposed_at' => now(), 'disposed_by' => $operator?->id])->save();
            $this->audit->record('retention.disposed', $ad, [], $counts, ['authorised_by' => $authorisedBy],
                user: $operator, actorType: 'system', actorLabel: $operator?->email ?? 'console');
        });

        $line = sprintf("[%s] Disposed advertisement %s (%s): %s. Authorised by: %s. Operator: %s\n",
            now()->toIso8601String(), $ad->number, $ad->title, json_encode($counts), $authorisedBy, $operator?->email ?? 'console');
        file_put_contents(storage_path('logs/retention.log'), $line, FILE_APPEND | LOCK_EX);

        return $counts;
    }
}
