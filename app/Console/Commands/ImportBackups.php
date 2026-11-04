<?php

namespace App\Console\Commands;

use App\Models\DatabaseBackup;
use App\Services\AuditLogger;
use App\Services\Maintenance\BackupIntegrityException;
use App\Services\Maintenance\BackupKeys;
use App\Services\Maintenance\BackupReader;
use App\Services\Maintenance\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Catalogue backup files in BACKUP_PATH that the portal does not list yet: those made by the
 * earlier `db:backup` (plain .sql.gz) or before the catalogue table existed. Each is verified in
 * full first. Server-side only: the portal never accepts uploaded SQL.
 */
class ImportBackups extends Command
{
    protected $signature = 'backup:import';

    protected $description = 'Catalogue verified backup files found in BACKUP_PATH';

    public function handle(BackupService $backups, BackupKeys $keys, AuditLogger $audit): int
    {
        $dir = $backups->directory();
        $known = DatabaseBackup::whereNotNull('filename')->pluck('filename')->flip();
        $imported = 0;

        foreach (scandir($dir) ?: [] as $name) {
            $path = "$dir/$name";
            if (! preg_match('/^[A-Za-z0-9_.-]+\.sql\.gz(\.enc)?$/', $name) || isset($known[$name]) || ! is_file($path) || is_link($path)) {
                continue;
            }
            try {
                $result = BackupReader::verify($path, null, $keys);
                $reader = new BackupReader($path, $keys);
                $encrypted = $reader->encrypted();
                $keyId = $reader->keyId();
                $reader->close();
            } catch (BackupIntegrityException $e) {
                $this->warn("Skipped $name: ".$e->getMessage());

                continue;
            }
            $made = now()->setTimestamp((int) filemtime($path));
            $backup = DatabaseBackup::create([
                'identifier' => 'BK-'.$made->format('Ymd-His').'-'.Str::upper(Str::random(4)),
                'type' => 'imported', 'status' => DatabaseBackup::COMPLETED, 'filename' => $name,
                'encrypted' => $encrypted, 'key_id' => $keyId, 'size_bytes' => (int) filesize($path), 'dump_bytes' => $result['dump_bytes'],
                'checksum_sha256' => hash_file('sha256', $path), 'initiated_by_name' => 'Imported', 'note' => 'Imported from the backup directory',
                'queued_at' => $made, 'started_at' => $made, 'completed_at' => $made, 'verified_at' => now(),
            ]);
            $backup->forceFill(['created_at' => $made])->save();
            @chmod($path, 0600);
            $audit->record('backup.imported', $backup, [], ['identifier' => $backup->identifier, 'filename' => $name], actorType: 'system', actorLabel: 'Command line');
            $this->line("Imported $name as {$backup->identifier}");
            $imported++;
        }
        $this->info("$imported backup file(s) imported.");

        return self::SUCCESS;
    }
}
