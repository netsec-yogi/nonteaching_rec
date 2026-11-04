<?php

namespace App\Console\Commands;

use App\Models\DatabaseBackup;
use App\Services\Maintenance\BackupKeys;
use App\Services\Maintenance\BackupReader;
use App\Services\Maintenance\BackupService;
use App\Services\Maintenance\BackupWriter;
use App\Services\Maintenance\MySqlTools;
use App\Services\Maintenance\OperationInProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * An on-demand backup from the command line (deploy scripts run it before migrating). It goes
 * through the same path as the portal's backups: mysqldump → gzip → encryption → verification,
 * catalogued under System maintenance. Before the catalogue table exists (the deploy that
 * introduces it) the file is still written and verified; `backup:import` catalogues it later.
 * Documents on the private disk are backed up separately (see docs/OPERATIONS.md).
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--prune : Apply the backup retention policy afterwards} {--note= : A note stored with the backup}';

    protected $description = 'Take a verified, compressed and encrypted MySQL backup';

    public function handle(BackupService $backups, MySqlTools $mysql, BackupKeys $keys): int
    {
        if (! Schema::hasTable('database_backups')) {
            return $this->uncatalogued($backups, $mysql, $keys);
        }

        try {
            $backup = $backups->request('command', null, $this->option('note') ?: null);
        } catch (OperationInProgress $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $backup = $backups->run($backup);

        if ($backup->status !== DatabaseBackup::COMPLETED) {
            $this->error("Backup {$backup->identifier} failed: {$backup->error}");

            return self::FAILURE;
        }
        $this->info("Backup verified: {$backup->identifier}  {$backup->filename}  (".BackupService::bytes($backup->size_bytes).", SHA-256 {$backup->checksum_sha256})");
        if ($backup->copy_status === 'failed') {
            $this->warn('The off-site copy failed: '.$backup->copy_error);
        }

        if ($this->option('prune')) {
            foreach ($backups->prune() as $identifier) {
                $this->line("Pruned $identifier");
            }
        }

        return self::SUCCESS;
    }

    private function uncatalogued(BackupService $backups, MySqlTools $mysql, BackupKeys $keys): int
    {
        $this->warn('The backup catalogue table does not exist yet (run the migrations); writing the file only.');
        $writer = null;
        try {
            $encrypt = (bool) config('maintenance.backup.encrypt', true);
            [$key, $keyId] = $encrypt ? $keys->current() : [null, null];
            $identifier = 'BK-'.now()->format('Ymd-His').'-CLI0';
            $path = $backups->directory().'/'.preg_replace('/[^A-Za-z0-9_-]/', '_', $mysql->database()).'_'.$identifier.'.sql.gz'.($encrypt ? '.enc' : '');
            $writer = new BackupWriter($path, $key, $keyId);
            $manifest = ['identifier' => $identifier, 'database' => $mysql->database(), 'server_version' => $mysql->serverVersion(), 'created_at' => now()->toIso8601String(), 'operational_tables' => []];
            $result = $backups->dumpInto($writer, $manifest, fn () => null);
            BackupReader::verify($path, $result['sha256'], $keys);
        } catch (Throwable $e) {
            $writer?->abort();
            $this->error('Backup failed: '.$backups->clean($e->getMessage()));

            return self::FAILURE;
        }
        $this->info('Backup verified: '.basename($path).' ('.BackupService::bytes($result['size']).'). Run php artisan backup:import after migrating to list it in the portal.');

        return self::SUCCESS;
    }
}
