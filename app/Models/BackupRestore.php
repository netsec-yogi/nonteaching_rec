<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One database restore, from request to result: the pre-restore checks, the automatic safety
 * backup, the post-restore health checks and any rollback. Written only by RestoreService. Kept in
 * an operational table, so the record survives the restore it describes.
 */
class BackupRestore extends BaseModel
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /** Stages after which the database may already have been changed. */
    public const DESTRUCTIVE_STAGES = ['restoring', 'migrating', 'verifying', 'rolling_back'];

    protected $casts = [
        'checks' => 'array', 'health' => 'array', 'migrations_applied' => 'array', 'maintenance_left_on' => 'boolean',
        'queued_at' => 'datetime', 'started_at' => 'datetime', 'heartbeat_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $restore) {
            $restore->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(DatabaseBackup::class, 'database_backup_id');
    }

    public function safetyBackup(): BelongsTo
    {
        return $this->belongsTo(DatabaseBackup::class, 'safety_backup_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    public function summary(): array
    {
        $this->loadMissing(['backup', 'safetyBackup']);

        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'stage' => $this->stage,
            'reason' => $this->reason,
            'requested_by_name' => $this->requested_by_name,
            'requested_ip' => $this->requested_ip,
            'backup' => $this->backup ? ['uuid' => $this->backup->uuid, 'identifier' => $this->backup->identifier, 'filename' => $this->backup->filename, 'created_at' => $this->backup->created_at?->toIso8601String()] : null,
            'safety_backup' => $this->safetyBackup ? ['uuid' => $this->safetyBackup->uuid, 'identifier' => $this->safetyBackup->identifier, 'status' => $this->safetyBackup->displayStatus()] : null,
            'checks' => $this->checks ?? [],
            'health' => $this->health ?? [],
            'migrations_applied' => $this->migrations_applied ?? [],
            'rollback_status' => $this->rollback_status,
            'maintenance_left_on' => $this->maintenance_left_on,
            'error' => $this->error,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
