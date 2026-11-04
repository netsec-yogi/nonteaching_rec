<script setup lang="ts">
/** System maintenance dashboard: backup and log figures from the server, each section shown with its permission. */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { api, toApiError } from '@/api/client';
import LoadingState from '@/components/LoadingState.vue';
import StatCard from '@/components/StatCard.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import MaintenanceNav from './parts/MaintenanceNav.vue';
import { BACKUP_TYPES, LEVEL_BADGE, type ActiveOperation, type Backup, type LogStorage, type LogToday, type Restore } from './parts/types';
import { formatBytes, formatDateTime, humanize } from '@/utils/format';

interface Overview {
    backups: {
        available: number; storage_bytes: number; latest: Backup | null; last_scheduled_success_at: string | null; failed_30_days: number; failed_total: number;
        last_failure: Backup | null; next_scheduled_at: string | null; schedule: string; encrypted: boolean; offsite_copy: boolean;
        disk_free_bytes: number | null; disk_total_bytes: number | null; recent_sizes: { identifier: string; completed_at: string | null; size_bytes: number | null }[];
    } | null;
    logs: { storage: LogStorage | null; today: LogToday | null } | null;
    active: ActiveOperation | null;
    recent_backups: Backup[];
    recent_restores: Restore[];
}

const CATEGORY_LABELS: Record<string, string> = {
    database_connection: 'Database connection', sql: 'SQL / query errors', mail: 'Email / SMTP delivery', queue: 'Queue jobs',
    authentication: 'Authentication', authorization: 'Authorization', filesystem: 'Files and permissions', application: 'Other application errors',
};

const data = ref<Overview | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

async function load() {
    try {
        data.value = (await api.get<Overview>('/admin/maintenance/overview')).data;
        error.value = null;
    } catch (e) {
        error.value = toApiError(e).message;
    } finally {
        loading.value = false;
        clearTimeout(timer);
        if (data.value?.active) timer = setTimeout(load, 4000);
    }
}
onMounted(load);
onBeforeUnmount(() => clearTimeout(timer));

const b = computed(() => data.value?.backups ?? null);
const storage = computed(() => data.value?.logs?.storage ?? null);
const today = computed(() => data.value?.logs?.today ?? null);
const maxSize = computed(() => Math.max(1, ...(b.value?.recent_sizes ?? []).map((s) => s.size_bytes ?? 0)));
const levelsToday = computed(() => Object.entries(today.value?.levels ?? {}).filter(([, n]) => n > 0));
const maxLevel = computed(() => Math.max(1, ...levelsToday.value.map(([, n]) => n)));
const categoriesToday = computed(() => Object.entries(today.value?.categories ?? {}));
const maxCategory = computed(() => Math.max(1, ...categoriesToday.value.map(([, n]) => n)));
/** "04 Nov, 01:12 pm" (the year only when it is not this year): fits a KPI tile. */
function when(value?: string | null): string {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    const opts: Intl.DateTimeFormatOptions = { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' };
    if (d.getFullYear() !== new Date().getFullYear()) opts.year = 'numeric';
    return d.toLocaleString('en-IN', opts);
}
const pct = (n: number, of: number) => Math.min(100, Math.round((n / Math.max(1, of)) * 100));
const diskUsedPct = computed(() => (b.value?.disk_total_bytes ? pct(b.value.disk_total_bytes - (b.value.disk_free_bytes ?? 0), b.value.disk_total_bytes) : null));
</script>

<template>
    <div>
        <div class="mb-2">
            <h1 class="page-title h4 mb-0">System maintenance</h1>
            <p class="page-subtitle mb-0">Database backups and application logs of the portal.</p>
        </div>
        <MaintenanceNav />

        <LoadingState :loading="loading" :error="error">
            <template v-if="data">
                <div v-if="data.active" class="alert alert-info d-flex align-items-center gap-2" role="status">
                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    <span v-if="data.active.kind === 'backup'">Backup <strong>{{ data.active.identifier }}</strong> is {{ data.active.status }}<template v-if="data.active.size_bytes"> · {{ formatBytes(data.active.size_bytes) }} written</template>.</span>
                    <span v-else>A database restore is in progress ({{ humanize(data.active.stage) }}). The portal is offline while the data is replaced.</span>
                    <router-link to="/admin/maintenance/backups" class="ms-auto">Open</router-link>
                </div>

                <!-- Backups -->
                <template v-if="b">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h2 class="h6 text-uppercase text-muted mb-0">Database backups</h2>
                        <span class="small text-muted">{{ b.schedule }} · {{ b.encrypted ? 'encrypted at rest' : 'not encrypted' }}{{ b.offsite_copy ? ' · copied off-site' : '' }}</span>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6 col-lg-4"><StatCard label="Available backups" :value="b.available" icon="database" tone="brand" to="/admin/maintenance/backups" /></div>
                        <div class="col-6 col-lg-4"><StatCard label="Latest successful backup" :value="b.latest ? when(b.latest.completed_at) : 'None yet'" icon="check2-circle" :tone="b.latest ? 'success' : 'warning'" :hint="b.latest?.identifier" /></div>
                        <div class="col-6 col-lg-4"><StatCard label="Last backup size" :value="formatBytes(b.latest?.size_bytes)" icon="file-earmark-zip" :hint="b.latest ? BACKUP_TYPES[b.latest.type] : null" /></div>
                        <div class="col-6 col-lg-4"><StatCard label="Failed (30 days)" :value="b.failed_30_days" icon="x-octagon" :tone="b.failed_30_days ? 'danger' : 'default'" :hint="b.last_failure ? `Last: ${formatDateTime(b.last_failure.created_at)}` : null" :to="{ path: '/admin/maintenance/backups', query: { status: 'failed' } }" /></div>
                        <div class="col-6 col-lg-4"><StatCard label="Next scheduled backup" :value="b.next_scheduled_at ? when(b.next_scheduled_at) : 'Off'" icon="calendar-check" :tone="b.next_scheduled_at ? 'default' : 'warning'" :hint="b.last_scheduled_success_at ? `Last: ${formatDateTime(b.last_scheduled_success_at)}` : 'No scheduled backup yet'" /></div>
                        <div class="col-6 col-lg-4"><StatCard label="Backup storage" :value="formatBytes(b.storage_bytes)" icon="hdd" :hint="b.disk_free_bytes !== null ? `${formatBytes(b.disk_free_bytes)} free on the disk` : null" /></div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-lg-6">
                            <div class="card h-100">
                                <div class="card-header"><i class="bi bi-bar-chart me-1" aria-hidden="true"></i>Size of recent backups</div>
                                <div class="card-body">
                                    <p v-if="!b.recent_sizes.length" class="text-muted small mb-0">No completed backups yet.</p>
                                    <table v-else class="table table-sm table-borderless align-middle mb-0">
                                        <caption class="visually-hidden">Size of the most recent completed backups</caption>
                                        <thead class="visually-hidden"><tr><th>Backup</th><th>Size</th><th>Bar</th></tr></thead>
                                        <tbody>
                                            <tr v-for="s in b.recent_sizes" :key="s.identifier">
                                                <td class="small text-nowrap text-muted" style="width: 9rem">{{ formatDateTime(s.completed_at) }}</td>
                                                <td class="small text-end text-nowrap" style="width: 5.5rem">{{ formatBytes(s.size_bytes) }}</td>
                                                <td><div class="progress" style="height: .5rem" role="progressbar" :aria-valuenow="pct(s.size_bytes ?? 0, maxSize)" aria-valuemin="0" aria-valuemax="100" :aria-label="`${s.identifier} size`"><div class="progress-bar" :style="{ width: pct(s.size_bytes ?? 0, maxSize) + '%' }"></div></div></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                    <div v-if="diskUsedPct !== null" class="mt-3">
                                        <div class="d-flex justify-content-between small text-muted mb-1"><span>Backup disk used</span><span>{{ diskUsedPct }}% of {{ formatBytes(b.disk_total_bytes) }}</span></div>
                                        <div class="progress" style="height: .5rem" role="progressbar" :aria-valuenow="diskUsedPct" aria-valuemin="0" aria-valuemax="100" aria-label="Backup disk used"><div class="progress-bar" :class="diskUsedPct > 90 ? 'bg-danger' : diskUsedPct > 75 ? 'bg-warning' : 'bg-success'" :style="{ width: diskUsedPct + '%' }"></div></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card h-100">
                                <div class="card-header d-flex justify-content-between"><span><i class="bi bi-clock-history me-1" aria-hidden="true"></i>Recent operations</span><router-link to="/admin/maintenance/backups" class="small">All backups</router-link></div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-cards align-middle mb-0">
                                        <thead class="table-light"><tr><th>Backup</th><th>Type</th><th>Status</th><th>When</th></tr></thead>
                                        <tbody>
                                            <tr v-for="r in data.recent_restores.slice(0, 2)" :key="r.uuid">
                                                <td class="small"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Restore of {{ r.backup?.identifier }}</td>
                                                <td class="small">Restore</td>
                                                <td><StatusBadge :value="r.status" /></td>
                                                <td class="small text-nowrap">{{ formatDateTime(r.completed_at ?? r.queued_at) }}</td>
                                            </tr>
                                            <tr v-for="x in data.recent_backups" :key="x.uuid">
                                                <td class="small font-monospace">{{ x.identifier }}</td>
                                                <td class="small">{{ BACKUP_TYPES[x.type] }}</td>
                                                <td><StatusBadge :value="x.status" /></td>
                                                <td class="small text-nowrap">{{ formatDateTime(x.created_at) }}</td>
                                            </tr>
                                            <tr v-if="!data.recent_backups.length && !data.recent_restores.length"><td colspan="4" class="text-center text-muted small py-3">No backups yet.</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Logs -->
                <template v-if="data.logs">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h2 class="h6 text-uppercase text-muted mb-0">Application logs</h2>
                        <router-link to="/admin/maintenance/logs" class="small">Open the logs</router-link>
                    </div>
                    <div v-if="storage?.over_threshold" class="alert alert-warning small" role="alert">
                        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Log storage ({{ formatBytes(storage.total_bytes) }}) is above the warning threshold of {{ formatBytes(storage.warn_at_bytes) }}. Archive or delete old log files.
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6 col-lg-4"><StatCard label="Log files" :value="storage?.file_count ?? null" icon="file-earmark-text" tone="brand" :hint="storage ? `${storage.archive_count} archived` : null" to="/admin/maintenance/logs" /></div>
                        <div class="col-6 col-lg-4"><StatCard label="Log storage" :value="formatBytes(storage?.total_bytes)" icon="hdd" :tone="storage?.over_threshold ? 'warning' : 'default'" :hint="storage ? `Warning at ${formatBytes(storage.warn_at_bytes)}` : null" /></div>
                        <div class="col-6 col-lg-4"><StatCard label="Latest log entry" :value="today?.latest_entry ? when(today.latest_entry.at.replace(' ', 'T')) : 'None today'" icon="clock" :hint="today?.latest_entry?.name" /></div>
                        <div class="col-6 col-lg-4"><StatCard label="Errors today" :value="today?.errors ?? null" icon="bug" :tone="today?.errors ? 'warning' : 'success'" :hint="today && !today.complete ? 'Counted from the most recent part of the file' : null" /></div>
                        <div class="col-6 col-lg-4"><StatCard label="Critical errors today" :value="today?.critical ?? null" icon="exclamation-octagon" :tone="today?.critical ? 'danger' : 'success'" hint="Critical, alert and emergency" /></div>
                        <div class="col-6 col-lg-4"><StatCard label="Largest log file" :value="formatBytes(storage?.largest[0]?.size)" icon="file-earmark-bar-graph" :hint="storage?.largest[0]?.name" /></div>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="card h-100">
                                <div class="card-header"><i class="bi bi-list-ol me-1" aria-hidden="true"></i>Today's entries by level</div>
                                <div class="card-body">
                                    <p v-if="!levelsToday.length" class="text-muted small mb-0">Nothing logged today.</p>
                                    <table v-else class="table table-sm table-borderless align-middle mb-0">
                                        <caption class="visually-hidden">Log entries recorded today, by level</caption>
                                        <thead class="visually-hidden"><tr><th>Level</th><th>Entries</th><th>Bar</th></tr></thead>
                                        <tbody>
                                            <tr v-for="[level, n] in levelsToday" :key="level">
                                                <td style="width: 7rem"><span class="badge" :class="LEVEL_BADGE[level]">{{ level.toUpperCase() }}</span></td>
                                                <td class="small text-end" style="width: 4rem">{{ n }}</td>
                                                <td><div class="progress" style="height: .5rem" role="progressbar" :aria-valuenow="pct(n, maxLevel)" aria-valuemin="0" aria-valuemax="100" :aria-label="`${level} entries`"><div class="progress-bar" :class="['error', 'critical', 'alert', 'emergency'].includes(level) ? 'bg-danger' : level === 'warning' ? 'bg-warning' : 'bg-info'" :style="{ width: pct(n, maxLevel) + '%' }"></div></div></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card h-100">
                                <div class="card-header"><i class="bi bi-tags me-1" aria-hidden="true"></i>Today's errors by kind</div>
                                <div class="card-body">
                                    <p v-if="!categoriesToday.length" class="text-muted small mb-0">No errors today.</p>
                                    <table v-else class="table table-sm table-borderless align-middle mb-0">
                                        <caption class="visually-hidden">Errors recorded today, by kind of problem</caption>
                                        <thead class="visually-hidden"><tr><th>Kind</th><th>Errors</th><th>Bar</th></tr></thead>
                                        <tbody>
                                            <tr v-for="[cat, n] in categoriesToday" :key="cat">
                                                <td class="small" style="width: 12rem">
                                                    <router-link v-if="today?.latest_entry" :to="{ path: '/admin/maintenance/logs/view', query: { file: today.latest_entry.name, category: cat } }">{{ CATEGORY_LABELS[cat] ?? humanize(cat) }}</router-link>
                                                    <template v-else>{{ CATEGORY_LABELS[cat] ?? humanize(cat) }}</template>
                                                </td>
                                                <td class="small text-end" style="width: 4rem">{{ n }}</td>
                                                <td><div class="progress" style="height: .5rem" role="progressbar" :aria-valuenow="pct(n, maxCategory)" aria-valuemin="0" aria-valuemax="100" :aria-label="`${cat} errors`"><div class="progress-bar bg-danger" :style="{ width: pct(n, maxCategory) + '%' }"></div></div></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
            </template>
        </LoadingState>
    </div>
</template>
