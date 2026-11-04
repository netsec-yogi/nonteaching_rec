<?php

namespace App\Console\Commands;

use App\Models\DatabaseBackup;
use App\Services\Maintenance\BackupIntegrityException;
use App\Services\Maintenance\BackupKeys;
use App\Services\Maintenance\BackupReader;
use App\Services\Maintenance\BackupService;
use Illuminate\Console\Command;

/**
 * Write a backup out as a plain .sql.gz, e.g. to restore it on another server by hand
 * (`gunzip -c file.sql.gz | mysql …`). Needs the key the backup was encrypted with. The source is
 * a backup ID or a file (such as an off-site copy); the output is created with mode 0600.
 */
class DecryptBackup extends Command
{
    protected $signature = 'backup:decrypt {backup : Backup ID or path to a backup file} {output : Where to write the .sql.gz (must not exist)}';

    protected $description = 'Decrypt a database backup to a plain .sql.gz';

    public function handle(BackupService $backups, BackupKeys $keys): int
    {
        $source = (string) $this->argument('backup');
        $record = DatabaseBackup::where('identifier', $source)->first();
        $path = $record ? $backups->path($record) : (is_file($source) ? $source : null);
        if (! $path) {
            $this->error("No backup file for $source.");

            return self::FAILURE;
        }
        $output = (string) $this->argument('output');

        try {
            BackupReader::verify($path, $record?->checksum_sha256, $keys);
            $out = @fopen($output, 'xb');
            if (! $out) {
                $this->error("Cannot create $output (it must not exist already).");

                return self::FAILURE;
            }
            @chmod($output, 0600);
            $reader = new BackupReader($path, $keys);
            foreach ($reader->compressed() as $chunk) {
                fwrite($out, $chunk);
            }
            fclose($out);
        } catch (BackupIntegrityException $e) {
            isset($out) && is_resource($out) && fclose($out);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Written $output (".BackupService::bytes((int) filesize($output)).'). It is not encrypted: keep it safe and delete it when done.');

        return self::SUCCESS;
    }
}
