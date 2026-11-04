<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One database backup: the catalogue entry and its execution record. Failed and deleted backups
 * stay as history (the file is removed; `deleted_at` marks it). Written only by BackupService.
 * The file path is never part of any response: files are streamed by BackupService.
 */
class DatabaseBackup extends BaseModel
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const TYPES = ['manual', 'scheduled', 'safety', 'command', 'imported'];

    protected $casts = [
        'encrypted' => 'boolean', 'manifest' => 'array', 'size_bytes' => 'integer', 'dump_bytes' => 'integer',
        'schedule_slot' => 'datetime', 'queued_at' => 'datetime', 'started_at' => 'datetime', 'heartbeat_at' => 'datetime',
        'completed_at' => 'datetime', 'verified_at' => 'datetime', 'copied_at' => 'datetime', 'deleted_at' => 'datetime',
    ];

    protected $hidden = ['manifest', 'key_id'];

    protected static function booted(): void
    {
        static::creating(function (self $backup) {
            $backup->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function restores(): HasMany
    {
        return $this->hasMany(BackupRestore::class, 'database_backup_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /** Completed, verified and still on disk: can be downloaded and restored. */
    public function isAvailable(): bool
    {
        return $this->status === self::COMPLETED && $this->deleted_at === null && $this->filename !== null;
    }

    /** The status shown in lists: a deleted backup reads "deleted" whatever it was. */
    public function displayStatus(): string
    {
        return $this->deleted_at ? 'deleted' : $this->status;
    }

    public function summary(): array
    {
        $end = $this->completed_at ?? ($this->isActive() ? now() : null);

        return [
            'uuid' => $this->uuid,
            'identifier' => $this->identifier,
            'filename' => $this->filename,
            'type' => $this->type,
            'status' => $this->displayStatus(),
            'run_status' => $this->status,
            'encrypted' => $this->encrypted,
            'size_bytes' => $this->size_bytes,
            'dump_bytes' => $this->dump_bytes,
            'note' => $this->note,
            'initiated_by_name' => $this->initiated_by_name,
            'schedule_slot' => $this->schedule_slot?->toIso8601String(),
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'duration_seconds' => $this->started_at && $end ? max(0, (int) $this->started_at->diffInSeconds($end)) : null,
            'copy_status' => $this->copy_status,
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'deleted_by_name' => $this->deleted_by_name,
            'deletion_reason' => $this->deletion_reason,
            'available' => $this->isAvailable(),
        ];
    }

    /** Everything in the summary plus integrity and provenance details (never the path or key). */
    public function details(): array
    {
        $m = $this->manifest ?? [];

        return $this->summary() + [
            'checksum_sha256' => $this->checksum_sha256,
            'copied_at' => $this->copied_at?->toIso8601String(),
            'copy_error' => $this->copy_error,
            'manifest' => $m ? [
                'database' => $m['database'] ?? null,
                'server_version' => $m['server_version'] ?? null,
                'client' => $m['client'] ?? null,
                'app_version' => $m['app_version'] ?? null,
                'table_count' => count($m['tables'] ?? []),
                'view_count' => count($m['views'] ?? []),
                'migration_count' => count($m['migrations'] ?? []),
                'latest_migration' => collect($m['migrations'] ?? [])->last(),
                'operational_tables' => $m['operational_tables'] ?? [],
            ] : null,
        ];
    }
}
