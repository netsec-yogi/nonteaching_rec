<?php

namespace App\Services\Maintenance;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The database side of backup and restore: mysqldump out, the mysql client in. Both run without a
 * shell (argument arrays), with the credentials in a 0600 option file that is removed afterwards,
 * so they never appear in a process list, a log or an error message. Both report progress through
 * `$tick` at least every few seconds, which is how a stalled operation is told from a long one.
 *
 * Swapped for a fake in tests: a real restore cannot run inside the test suite's transaction.
 */
class MySqlTools
{
    private const WAIT_SECONDS = 5;

    /** @var array<string, string|null> */
    private array $versions = [];

    /** @return array<string, mixed> */
    public function connection(): array
    {
        $name = config('database.default');
        $config = (array) config("database.connections.$name");
        if (! in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Database backups need a MySQL or MariaDB connection.');
        }

        return $config;
    }

    public function database(): string
    {
        return (string) $this->connection()['database'];
    }

    public function serverVersion(): string
    {
        return (string) DB::selectOne('select version() as v')->v;
    }

    /** `mysqldump --version` / `mysql --version`, or null when the tool is not installed. */
    public function clientVersion(string $tool): ?string
    {
        if (! array_key_exists($tool, $this->versions)) {
            $output = '';
            try {
                $process = proc_open([$this->binary($tool), '--version'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (is_resource($process)) {
                    $output = trim((string) stream_get_contents($pipes[1]));
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    if (proc_close($process) !== 0) {
                        $output = '';
                    }
                }
            } catch (\Throwable) {
                $output = '';
            }
            $this->versions[$tool] = $output !== '' ? mb_substr($output, 0, 200) : null;
        }

        return $this->versions[$tool];
    }

    public function available(string $tool): bool
    {
        return $this->clientVersion($tool) !== null;
    }

    public function clientIsMariaDb(string $tool): bool
    {
        return str_contains((string) $this->clientVersion($tool), 'MariaDB');
    }

    /** @return list<array{name: string, type: string}> tables and views of the current database */
    public function tables(): array
    {
        return collect(DB::select('select TABLE_NAME as name, TABLE_TYPE as type from information_schema.TABLES where TABLE_SCHEMA = database() order by TABLE_NAME'))
            ->map(fn ($r) => ['name' => (string) $r->name, 'type' => $r->type === 'VIEW' ? 'view' : 'table'])->all();
    }

    /** CREATE TABLE IF NOT EXISTS for a table, without its AUTO_INCREMENT position. */
    public function createStatement(string $table): string
    {
        $row = (array) DB::selectOne('show create table '.$this->quote($table));
        $sql = (string) ($row['Create Table'] ?? array_values($row)[1] ?? '');

        return preg_replace(['/^CREATE TABLE /', '/ AUTO_INCREMENT=\d+/'], ['CREATE TABLE IF NOT EXISTS ', ''], $sql, 1);
    }

    public function hasEvents(): bool
    {
        return (int) DB::selectOne('select count(*) as n from information_schema.EVENTS where EVENT_SCHEMA = database()')->n > 0;
    }

    public function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /**
     * Dump the database (structure, data, routines, triggers, views; events when it has any) with
     * the given tables' rows left out, handing the SQL to `$onData` as it arrives.
     *
     * @param  list<string>  $skipTables
     */
    public function dump(array $skipTables, callable $onData, callable $tick): void
    {
        $db = $this->database();
        $command = [$this->binary('dump'), '--defaults-extra-file=%CNF%', '--single-transaction', '--quick', '--routines', '--triggers',
            '--hex-blob', '--no-tablespaces', '--default-character-set=utf8mb4'];
        if ($this->hasEvents()) {
            $command[] = '--events';
        }
        if (! $this->clientIsMariaDb('dump')) {
            $command[] = '--set-gtid-purged=OFF';  // MySQL's mysqldump only; a GTID line would fail on restore
        }
        foreach ($skipTables as $table) {
            $command[] = '--ignore-table='.$db.'.'.$table;
        }
        array_push($command, ...$this->options('dump_options'), ...[$db]);

        $this->run($command, null, $onData, $tick, 'mysqldump');
    }

    /** Feed SQL to the mysql client for the current database; fails if any statement fails. */
    public function load(iterable $sql, callable $tick): void
    {
        $command = [$this->binary('mysql'), '--defaults-extra-file=%CNF%', '--default-character-set=utf8mb4', ...$this->options('restore_options'), $this->database()];
        $this->run($command, $sql, null, $tick, 'mysql');
    }

    private function binary(string $tool): string
    {
        return (string) config($tool === 'dump' ? 'maintenance.backup.mysqldump_binary' : 'maintenance.backup.mysql_binary', $tool === 'dump' ? 'mysqldump' : 'mysql');
    }

    /** @return list<string> */
    private function options(string $key): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim((string) config("maintenance.backup.$key", ''))) ?: []));
    }

    /**
     * @param  list<string>  $command  "%CNF%" is replaced by the option file holding the credentials
     */
    private function run(array $command, ?iterable $input, ?callable $onData, callable $tick, string $label): void
    {
        $cnf = $this->optionFile();
        $stderr = tmpfile();
        $timeout = max(60, (int) config('maintenance.backup.timeout', 3600));
        $started = time();

        try {
            $command = array_map(fn ($a) => str_replace('%CNF%', $cnf, $a), $command);
            $spec = [
                0 => $input === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'],
                1 => $onData === null ? ['file', '/dev/null', 'w'] : ['pipe', 'w'],
                2 => $stderr,
            ];
            $process = proc_open($command, $spec, $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException("$label could not be started. Is it installed on the server?");
            }

            $exit = null;
            $overdue = fn () => time() - $started > $timeout;
            try {
                if ($input !== null) {
                    $exit = $this->feed($process, $pipes[0], $input, $tick, $overdue, $label);
                }
                if ($onData !== null) {
                    $this->drain($pipes[1], $onData, $tick, $overdue, $label);
                }
            } catch (\Throwable $e) {
                foreach ($pipes as $pipe) {
                    is_resource($pipe) && @fclose($pipe);
                }
                proc_terminate($process);
                proc_close($process);
                throw $e;
            }
            foreach ($pipes as $pipe) {
                is_resource($pipe) && @fclose($pipe);
            }
            $code = proc_close($process);
            if ($code === -1 && $exit !== null) {
                $code = $exit;
            }

            if ($code !== 0) {
                rewind($stderr);
                $error = trim((string) stream_get_contents($stderr, 4000));
                throw new RuntimeException("$label failed (exit $code)".($error !== '' ? ': '.$error : '.'));
            }
        } finally {
            @unlink($cnf);
            fclose($stderr);
        }
    }

    /** Write the SQL to the client's stdin; returns the exit code if the client ended early. */
    private function feed($process, $stdin, iterable $input, callable $tick, callable $overdue, string $label): ?int
    {
        stream_set_blocking($stdin, false);
        foreach ($input as $chunk) {
            $offset = 0;
            $length = strlen($chunk);
            while ($offset < $length) {
                $read = $except = null;
                $write = [$stdin];
                $ready = @stream_select($read, $write, $except, self::WAIT_SECONDS);
                $tick();
                if ($overdue()) {
                    throw new RuntimeException("$label did not finish within the time limit (BACKUP_TIMEOUT).");
                }
                $status = proc_get_status($process);
                if (! $status['running']) {
                    return $status['exitcode'];
                }
                if (! $ready) {
                    continue;
                }
                $written = @fwrite($stdin, substr($chunk, $offset, 65536));
                if ($written === false) {
                    $status = proc_get_status($process);

                    return $status['running'] ? 1 : $status['exitcode'];
                }
                $offset += $written;
            }
        }
        fclose($stdin);

        return null;
    }

    private function drain($stdout, callable $onData, callable $tick, callable $overdue, string $label): void
    {
        stream_set_blocking($stdout, false);
        while (true) {
            $read = [$stdout];
            $write = $except = null;
            $ready = @stream_select($read, $write, $except, self::WAIT_SECONDS);
            $tick();
            if ($overdue()) {
                throw new RuntimeException("$label did not finish within the time limit (BACKUP_TIMEOUT).");
            }
            if ($ready === false) {
                throw new RuntimeException("Reading from $label failed.");
            }
            if ($ready === 0) {
                continue;
            }
            $data = fread($stdout, 1048576);
            if ($data === false) {
                break;
            }
            if ($data !== '') {
                $onData($data);
            } elseif (feof($stdout)) {
                break;
            }
        }
    }

    private function optionFile(): string
    {
        $c = $this->connection();
        $value = fn ($v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v).'"';
        $lines = ['[client]', 'user='.$value($c['username'] ?? ''), 'password='.$value($c['password'] ?? '')];
        if (! empty($c['unix_socket'])) {
            $lines[] = 'socket='.$value($c['unix_socket']);
        } else {
            $lines[] = 'host='.$value($c['host'] ?? '127.0.0.1');
            $lines[] = 'port='.(int) ($c['port'] ?? 3306);
        }

        $file = tempnam(sys_get_temp_dir(), 'ntrec-my');
        if ($file === false) {
            throw new RuntimeException('Could not create a temporary file for the database credentials.');
        }
        chmod($file, 0600);
        file_put_contents($file, implode("\n", $lines)."\n");

        return $file;
    }
}
