<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BackupRestore;
use App\Models\DatabaseBackup;
use App\Services\Maintenance\BackupSchedule;
use App\Services\Maintenance\BackupService;
use App\Services\Maintenance\LogFiles;
use App\Services\Maintenance\MaintenanceOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** System maintenance dashboard: backup and log figures, each shown only with its permission. */
class MaintenanceController extends Controller
{
    public function overview(Request $request, BackupService $backups, BackupSchedule $schedule, LogFiles $logs, MaintenanceOperations $ops): JsonResponse
    {
        $user = $request->user();
        $canBackups = $user->hasPermission('backups.manage') || $user->hasPermission('backups.restore') || $user->hasPermission('backups.download');
        $canLogs = $user->hasPermission('logs.view');
        abort_unless($canBackups || $canLogs, 403, 'You do not have permission for this action.');

        $active = $canBackups ? $ops->active() : null;

        return response()->json([
            'backups' => $canBackups ? $backups->overview($schedule) : null,
            'logs' => $canLogs ? [
                'storage' => rescue(fn () => $logs->storage(), null, false),
                'today' => rescue(fn () => $logs->today(), null, false),
            ] : null,
            'active' => $active ? ['kind' => $active instanceof BackupRestore ? 'restore' : 'backup'] + $active->summary() : null,
            'recent_backups' => $canBackups ? DatabaseBackup::latest('id')->limit(5)->get()->map->summary() : [],
            'recent_restores' => $canBackups ? BackupRestore::with(['backup', 'safetyBackup'])->latest('id')->limit(5)->get()->map->summary() : [],
        ]);
    }
}
