<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * mysqldump → gzip, then a verification pass that the archive is complete
 * (decompresses cleanly and ends with mysqldump's completion marker).
 * Documents on the private disk are backed up separately (see docs/OPERATIONS.md).
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--prune : Delete backups older than BACKUP_KEEP_DAYS}';

    protected $description = 'Take a verified, compressed MySQL backup';

    public function handle(): int
    {
        $c = config('database.connections.'.config('database.default'));
        if (($c['driver'] ?? null) !== 'mysql') {
            $this->error('Backups are implemented for MySQL only.');

            return self::FAILURE;
        }

        $dir = config('recruitment.backup.path');
        File::ensureDirectoryExists($dir, 0700);
        $file = $dir.'/'.$c['database'].'-'.now()->format('Ymd-His').'.sql.gz';

        $cnf = tempnam(sys_get_temp_dir(), 'mycnf');
        file_put_contents($cnf, "[client]\nuser=\"{$c['username']}\"\npassword=\"{$c['password']}\"\nhost=\"{$c['host']}\"\nport={$c['port']}\n");
        chmod($cnf, 0600);

        // pipefail: a failing mysqldump must fail the command, not just produce an empty archive.
        $dump = Process::fromShellCommandline(sprintf(
            'bash -o pipefail -c %s',
            escapeshellarg(sprintf(
                'mysqldump --defaults-extra-file=%s --single-transaction --quick --routines --triggers --no-tablespaces %s %s | gzip -9 > %s',
                escapeshellarg($cnf), config('recruitment.backup.dump_options'), escapeshellarg($c['database']), escapeshellarg($file)
            ))
        ))->setTimeout(3600);
        $dump->run();
        @unlink($cnf);

        if (! $dump->isSuccessful()) {
            @unlink($file);
            $this->error('Backup failed: '.trim($dump->getErrorOutput()));

            return self::FAILURE;
        }

        $verify = Process::fromShellCommandline('gzip -t '.escapeshellarg($file).' && zcat '.escapeshellarg($file).' | tail -n 1');
        $verify->run();
        if (! $verify->isSuccessful() || ! str_contains($verify->getOutput(), 'Dump completed')) {
            $this->error('Backup written but failed verification: '.$file);

            return self::FAILURE;
        }
        chmod($file, 0600);
        $this->info('Backup verified: '.$file.' ('.round(filesize($file) / 1024).' KB)');

        if ($this->option('prune')) {
            $cutoff = now()->subDays((int) config('recruitment.backup.keep_days'))->getTimestamp();
            foreach (File::glob($dir.'/*.sql.gz') as $old) {
                if (filemtime($old) < $cutoff) {
                    File::delete($old);
                    $this->line('Pruned '.basename($old));
                }
            }
        }

        return self::SUCCESS;
    }
}
