<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BackupRestore;
use App\Models\DatabaseBackup;
use App\Services\AuditLogger;
use App\Services\Maintenance\BackupSchedule;
use App\Services\Maintenance\BackupService;
use App\Services\Maintenance\MaintenanceOperations;
use App\Services\Maintenance\MaintenanceSettings;
use App\Services\Maintenance\RestoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Database backups (backups.manage), downloads (backups.download) and restores (backups.restore).
 * Permissions are enforced by the route middleware; responses never include file paths or keys.
 */
class BackupController extends Controller
{
    public function __construct(private BackupService $backups, private RestoreService $restores, private MaintenanceOperations $ops) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'type' => ['nullable', Rule::in(DatabaseBackup::TYPES)],
            'status' => ['nullable', 'in:queued,running,completed,failed,deleted'],
            'initiated_by' => ['nullable', 'string', 'max:150'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:created_at,size_bytes,status,type,identifier'],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);

        $page = DatabaseBackup::query()
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($f['status'] ?? null, fn ($q, $s) => $s === 'deleted' ? $q->whereNotNull('deleted_at') : $q->where('status', $s)->whereNull('deleted_at'))
            ->when($f['initiated_by'] ?? null, fn ($q, $n) => $q->where('initiated_by_name', $n))
            ->when($f['date_from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($f['date_to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('identifier', 'like', '%'.addcslashes($t, '%_\\').'%')
                ->orWhere('filename', 'like', '%'.addcslashes($t, '%_\\').'%')->orWhere('note', 'like', '%'.addcslashes($t, '%_\\').'%')))
            ->orderBy($f['sort'] ?? 'created_at', $f['dir'] ?? 'desc')->orderByDesc('id')
            ->paginate(25);
        $active = $this->ops->active();
        $user = $request->user();

        return response()->json([
            'data' => collect($page->items())->map->summary(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
            'initiators' => DatabaseBackup::query()->whereNotNull('initiated_by_name')->distinct()->orderBy('initiated_by_name')->pluck('initiated_by_name'),
            'active' => $active ? ['kind' => $active instanceof BackupRestore ? 'restore' : 'backup'] + $active->summary() : null,
            'can' => ['manage' => $user->hasPermission('backups.manage'), 'download' => $user->hasPermission('backups.download'), 'restore' => $user->hasPermission('backups.restore')],
        ]);
    }

    public function show(DatabaseBackup $backup): JsonResponse
    {
        return response()->json([
            'backup' => $backup->details(),
            'restores' => $backup->restores()->with(['backup', 'safetyBackup'])->latest('id')->get()->map->summary(),
            'safety_for' => $backup->restore_id ? BackupRestore::with(['backup', 'safetyBackup'])->find($backup->restore_id)?->summary() : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $backup = $this->backups->request('manual', $request->user(), $data['note'] ?? null);
        $this->backups->dispatch($backup);

        return response()->json(['backup' => $backup->fresh()->summary()], 202);
    }

    public function verify(Request $request, DatabaseBackup $backup): JsonResponse
    {
        abort_unless($backup->isAvailable(), 409, 'Only a completed backup that is still on the server can be verified.');
        $result = $this->backups->verify($backup, $request->user());

        return response()->json($result + ['backup' => $backup->fresh()->details()]);
    }

    public function download(Request $request, DatabaseBackup $backup): BinaryFileResponse
    {
        return $this->backups->download($backup, $request->user());
    }

    public function destroy(Request $request, DatabaseBackup $backup): JsonResponse
    {
        $data = $request->validate(['confirm' => ['accepted'], 'reason' => ['nullable', 'string', 'max:255']]);
        $this->backups->delete($backup, $request->user(), $data['reason'] ?? null);

        return response()->json(['backup' => $backup->fresh()->summary()]);
    }

    /** What the confirmation dialog shows: the backup and the quick pre-restore checks. */
    public function restoreCheck(DatabaseBackup $backup): JsonResponse
    {
        $active = $this->ops->active();

        return response()->json([
            'backup' => $backup->summary(),
            'checks' => $this->restores->checks($backup),
            'busy' => $active ? ($active instanceof BackupRestore ? 'A restore is already in progress.' : "Backup {$active->identifier} is in progress.") : null,
        ]);
    }

    /**
     * Start a restore. Needs backups.restore, a reason, the backup's identifier typed back and the
     * administrator's own password (re-authentication for the most destructive action there is).
     */
    public function restore(Request $request, DatabaseBackup $backup): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirmation' => ['required', 'string'],
            'password' => ['required', 'string'],
            'acknowledge' => ['accepted'],
        ], [], ['acknowledge' => 'acknowledgement']);

        if (! hash_equals($backup->identifier, trim($data['confirmation']))) {
            throw ValidationException::withMessages(['confirmation' => "Type the backup ID {$backup->identifier} exactly to confirm."]);
        }
        if (! Hash::check($data['password'], (string) $request->user()->getAuthPassword())) {
            app(AuditLogger::class)->record('backup.restore_denied', $backup, [], ['identifier' => $backup->identifier], ['reason' => 'Incorrect password'], user: $request->user());
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }

        $restore = $this->restores->request($backup, $request->user(), $data['reason'], $request->ip());

        return response()->json(['restore' => $restore->fresh()->summary()], 202);
    }

    public function restores(): JsonResponse
    {
        $page = BackupRestore::with(['backup', 'safetyBackup'])->latest('id')->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map->summary(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
        ]);
    }

    /** Polled while a restore runs (also during maintenance mode). */
    public function restoreStatus(BackupRestore $restore): JsonResponse
    {
        return response()->json(['restore' => $restore->summary(), 'maintenance_mode' => app()->isDownForMaintenance()]);
    }

    public function settings(MaintenanceSettings $settings, BackupSchedule $schedule): JsonResponse
    {
        return response()->json($this->settingsPayload($settings, $schedule));
    }

    public function updateSettings(Request $request, MaintenanceSettings $settings, BackupSchedule $schedule, AuditLogger $audit): JsonResponse
    {
        [$old, $new] = $settings->updateBackup((array) $request->input('values', []));
        if ($new) {
            $audit->record('backup.settings_updated', null, $old, $new, user: $request->user());
            $this->backups->prune();
        }

        return response()->json($this->settingsPayload($settings, $schedule));
    }

    private function settingsPayload(MaintenanceSettings $settings, BackupSchedule $schedule): array
    {
        $values = $settings->backup();
        unset($values['since']);
        $last = DatabaseBackup::where('type', 'scheduled')->where('status', DatabaseBackup::COMPLETED)->latest('completed_at')->first();

        return [
            'values' => $values,
            'schedule' => $schedule->describe(),
            'next_run_at' => $schedule->next()?->toIso8601String(),
            'last_scheduled_success_at' => $last?->completed_at?->toIso8601String(),
            'encrypted' => (bool) config('maintenance.backup.encrypt', true),
            'dedicated_key' => (bool) config('maintenance.backup.key'),
            'offsite_copy' => (bool) config('maintenance.backup.copy_disk'),
            'timezone' => config('app.timezone'),
        ];
    }
}
