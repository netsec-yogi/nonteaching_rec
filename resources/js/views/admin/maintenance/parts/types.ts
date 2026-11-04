/** Shapes of the System maintenance API (app/Http/Controllers/Admin/{Maintenance,Backup,LogFile}Controller). */

export type BackupStatus = 'queued' | 'running' | 'completed' | 'failed' | 'deleted';
export type BackupType = 'manual' | 'scheduled' | 'safety' | 'command' | 'imported';

export interface Backup {
    uuid: string; identifier: string; filename: string | null; type: BackupType; status: BackupStatus; run_status: string;
    encrypted: boolean; size_bytes: number | null; dump_bytes: number | null; note: string | null; initiated_by_name: string | null;
    schedule_slot: string | null; error: string | null; created_at: string | null; started_at: string | null; completed_at: string | null;
    verified_at: string | null; duration_seconds: number | null; copy_status: 'copied' | 'failed' | null; deleted_at: string | null;
    deleted_by_name: string | null; deletion_reason: string | null; available: boolean;
}

export interface BackupDetails extends Backup {
    checksum_sha256: string | null; copied_at: string | null; copy_error: string | null;
    manifest: {
        database: string | null; server_version: string | null; client: string | null; app_version: string | null; table_count: number;
        view_count: number; migration_count: number; latest_migration: string | null; operational_tables: string[];
    } | null;
}

export interface Check { key?: string; label: string; status: 'pass' | 'warn' | 'fail'; detail: string | null }

export interface Restore {
    uuid: string; status: 'queued' | 'running' | 'completed' | 'failed'; stage: string | null; reason: string; requested_by_name: string | null;
    requested_ip: string | null; backup: { uuid: string; identifier: string; filename: string | null; created_at: string | null } | null;
    safety_backup: { uuid: string; identifier: string; status: string } | null; checks: Check[]; health: Check[]; migrations_applied: string[];
    rollback_status: 'succeeded' | 'failed' | null; maintenance_left_on: boolean; error: string | null;
    queued_at: string | null; started_at: string | null; completed_at: string | null;
}

export type ActiveOperation = ({ kind: 'backup' } & Backup) | ({ kind: 'restore' } & Restore);

export interface Paged<T> { data: T[]; meta: { current_page: number; last_page: number; total: number; per_page: number } }

export interface BackupSettingsValues {
    enabled: boolean; frequency: 'daily' | 'weekly' | 'monthly'; time: string; weekday: number; month_day: number; retention_days: number; max_backups: number;
}

export interface LogFile { name: string; kind: 'log' | 'archive'; size: number; modified_at: string; date: string; active: boolean; active_reason: string | null }

export interface LogStorage {
    total_bytes: number; log_bytes: number; archive_bytes: number; file_count: number; archive_count: number;
    largest: { name: string; kind: string; size: number }[]; latest_modified: { name: string; at: string } | null;
    disk_free_bytes: number | null; disk_total_bytes: number | null; warn_at_bytes: number; over_threshold: boolean;
}

export interface LogToday {
    levels: Record<string, number>; errors: number; critical: number; categories: Record<string, number>;
    latest_entry: { name: string; at: string } | null; complete: boolean;
}

export interface LogSettingsValues { auto_archive: boolean; archive_after_days: number; retention_days: number; warn_at_mb: number }

export interface LogEntry {
    offset: number; timestamp: string | null; environment: string | null; level: string | null; message: string; details: string;
    exception: string | null; category: string | null; truncated: boolean;
}

export const BACKUP_TYPES: Record<BackupType, string> = {
    manual: 'Manual', scheduled: 'Scheduled', safety: 'Safety (before restore)', command: 'Command line', imported: 'Imported',
};

export const RESTORE_STAGES: { key: string; label: string }[] = [
    { key: 'validating', label: 'Checking the backup' },
    { key: 'safety_backup', label: 'Safety backup of the current data' },
    { key: 'restoring', label: 'Replacing the data' },
    { key: 'migrating', label: 'Applying newer migrations' },
    { key: 'verifying', label: 'Health checks' },
];

export const LEVEL_BADGE: Record<string, string> = {
    debug: 'text-bg-secondary', info: 'text-bg-info', notice: 'text-bg-info', warning: 'text-bg-warning',
    error: 'text-bg-danger', critical: 'text-bg-danger', alert: 'text-bg-danger', emergency: 'text-bg-danger',
};

export const CHECK_ICON: Record<string, { icon: string; cls: string; label: string }> = {
    pass: { icon: 'bi-check-circle-fill', cls: 'text-success', label: 'Passed' },
    warn: { icon: 'bi-exclamation-triangle-fill', cls: 'text-warning', label: 'Warning' },
    fail: { icon: 'bi-x-octagon-fill', cls: 'text-danger', label: 'Failed' },
};

export function duration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined) return '—';
    if (seconds < 60) return `${seconds} s`;
    const m = Math.floor(seconds / 60);
    return m < 60 ? `${m} min ${seconds % 60} s` : `${Math.floor(m / 60)} h ${m % 60} min`;
}
