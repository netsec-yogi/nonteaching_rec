<?php

namespace App\Console\Commands;

use App\Models\BackupRestore;
use App\Models\DatabaseBackup;
use App\Services\Maintenance\BackupService;
use App\Services\Maintenance\OperationInProgress;
use App\Services\Maintenance\RestoreService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Restore a catalogued backup from the server's command line, with the same checks, safety backup,
 * maintenance mode, health checks and rollback as the portal. For the server administrator, e.g.
 * when a failed restore left the portal in maintenance mode. Without an ID it lists the backups.
 */
class RestoreDatabase extends Command
{
    protected $signature = 'backup:restore {backup? : Backup ID, e.g. BK-20261104-013000-AB12 (lists backups when omitted)}
        {--reason= : Why the database is being restored (recorded)} {--force : Do not ask for confirmation}';

    protected $description = 'Restore the database from a catalogued backup';

    public function handle(RestoreService $restores): int
    {
        $id = $this->argument('backup');
        if (! $id) {
            $this->table(['Backup ID', 'Type', 'Completed', 'Size', 'Note'], DatabaseBackup::where('status', DatabaseBackup::COMPLETED)->whereNull('deleted_at')
                ->latest('completed_at')->limit(25)->get()->map(fn ($b) => [$b->identifier, $b->type, $b->completed_at?->format('Y-m-d H:i'), BackupService::bytes($b->size_bytes), $b->note]));

            return self::SUCCESS;
        }

        $backup = DatabaseBackup::where('identifier', $id)->first();
        if (! $backup) {
            $this->error("No backup with ID $id.");

            return self::FAILURE;
        }
        $this->table(['Check', 'Result', 'Detail'], collect($restores->checks($backup))->map(fn ($c) => [$c['label'], strtoupper($c['status']), $c['detail']]));

        $reason = $this->option('reason') ?: ($this->option('force') ? null : $this->ask('Why is the database being restored?'));
        if (! $reason || mb_strlen($reason) < 10) {
            $this->error('A reason of at least 10 characters is required (--reason).');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('This replaces ALL data in the "'.config('database.connections.'.config('database.default').'.database')."\" database with backup {$backup->identifier} (".$backup->completed_at?->format('Y-m-d H:i').'). A safety backup is taken first. Continue?', false)) {
            return self::FAILURE;
        }

        try {
            $restore = $restores->request($backup, null, $reason, null, queue: false);
        } catch (OperationInProgress|ValidationException $e) {
            $this->error($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage());

            return self::FAILURE;
        }
        $this->info('Restoring… (this can take a while)');
        $restore = $restores->run($restore);

        foreach ($restore->health ?? [] as $h) {
            $this->line(sprintf('  %-5s %s %s', strtoupper($h['status']), $h['label'], $h['detail'] ? "— {$h['detail']}" : ''));
        }
        if ($restore->status !== BackupRestore::COMPLETED) {
            $this->error('Restore failed: '.$restore->error);

            return self::FAILURE;
        }
        $this->info("Restored from {$backup->identifier}. Safety backup: ".($restore->safetyBackup?->identifier ?? '—'));
        if (app()->isDownForMaintenance()) {
            $this->warn('The portal is still in maintenance mode (it was before the restore). Run php artisan up when ready.');
        }

        return self::SUCCESS;
    }
}
