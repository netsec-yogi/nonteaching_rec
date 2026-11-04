<?php

namespace Tests\Support;

use App\Services\Maintenance\MySqlTools;
use Closure;
use RuntimeException;

/**
 * Stands in for mysqldump / the mysql client. A real restore drops tables, which cannot happen
 * inside the test suite's transaction, so loads are captured instead. Schema queries (tables,
 * SHOW CREATE TABLE) still run against the test database.
 */
class FakeMySqlTools extends MySqlTools
{
    public string $dumpSql = "--\n-- Table structure for table `widgets`\n--\nDROP TABLE IF EXISTS `widgets`;\nCREATE TABLE `widgets` (`id` int);\nINSERT INTO `widgets` VALUES (1),(2);\n-- Dump completed on 2026-11-04 10:00:00\n";

    public ?string $dumpError = null;

    /** @var list<string> */
    public array $skipped = [];

    /** @var list<string> SQL of each load, in order */
    public array $loaded = [];

    /** @var list<bool> whether maintenance mode was on during each load */
    public array $downDuringLoad = [];

    /** Called with (sql, call number); throw to make a load fail. */
    public ?Closure $onLoad = null;

    public function serverVersion(): string
    {
        return '8.4.11';
    }

    public function clientVersion(string $tool): ?string
    {
        return $tool === 'dump' ? 'mysqldump  Ver 8.4.11 (fake)' : 'mysql  Ver 8.4.11 (fake)';
    }

    public function hasEvents(): bool
    {
        return false;
    }

    public function dump(array $skipTables, callable $onData, callable $tick): void
    {
        $this->skipped = $skipTables;
        if ($this->dumpError !== null) {
            throw new RuntimeException($this->dumpError);
        }
        foreach (str_split($this->dumpSql, 64) as $chunk) {
            $onData($chunk);
            $tick();
        }
    }

    public function load(iterable $sql, callable $tick): void
    {
        $all = '';
        foreach ($sql as $chunk) {
            $all .= $chunk;
            $tick();
        }
        $this->loaded[] = $all;
        $this->downDuringLoad[] = app()->isDownForMaintenance();
        if ($this->onLoad) {
            ($this->onLoad)($all, count($this->loaded));
        }
    }
}
