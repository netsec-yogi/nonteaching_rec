<script setup lang="ts">
/**
 * Database backups: create, list (searchable, sortable, filtered in the URL), download, delete,
 * restore, and the restore history. Operations run in the background; the page follows them.
 */
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import { useToast } from '@/stores/toast';
import BaseModal from '@/components/BaseModal.vue';
import LoadingState from '@/components/LoadingState.vue';
import Pager from '@/components/Pager.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import MaintenanceNav from './parts/MaintenanceNav.vue';
import BackupSettings from './parts/BackupSettings.vue';
import BackupDetailsModal from './parts/BackupDetailsModal.vue';
import RestoreDialog from './parts/RestoreDialog.vue';
import { BACKUP_TYPES, duration, type ActiveOperation, type Backup, type Paged, type Restore } from './parts/types';
import { formatBytes, formatDateTime, humanize } from '@/utils/format';

interface ListResponse extends Paged<Backup> {
    initiators: string[];
    active: ActiveOperation | null;
    can: { manage: boolean; download: boolean; restore: boolean };
}

const route = useRoute();
const router = useRouter();
const toast = useToast();

const FILTERS = ['type', 'status', 'initiated_by', 'date_from', 'date_to', 'q'] as const;
const q = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '');
const f = reactive<Record<string, string>>({});
const sort = computed(() => q('sort') || 'created_at');
const dir = computed(() => (q('dir') === 'asc' ? 'asc' : 'desc'));
const page = computed(() => Math.max(1, Number(q('page')) || 1));

const list = ref<ListResponse | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const restores = ref<Paged<Restore> | null>(null);
const details = ref<string | null>(null);
const restoring = ref<Backup | null>(null);
const deleting = ref<Backup | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
let seq = 0;

async function load(quiet = false) {
    const mine = ++seq;
    if (!quiet) loading.value = !list.value;
    const params: Record<string, string | number> = { page: page.value, sort: sort.value, dir: dir.value };
    for (const k of FILTERS) if (q(k)) params[k] = q(k);
    try {
        const [l, r] = await Promise.all([
            api.get<ListResponse>('/admin/maintenance/backups', { params }),
            api.get<Paged<Restore>>('/admin/maintenance/restores'),
        ]);
        if (mine !== seq) return;
        list.value = l.data;
        restores.value = r.data;
        error.value = null;
    } catch (e) {
        if (mine === seq && !quiet) error.value = toApiError(e).message;
    } finally {
        if (mine === seq) {
            loading.value = false;
            clearTimeout(timer);
            if (list.value?.active && !restoring.value) timer = setTimeout(() => load(true), 3000);
        }
    }
}

watch(() => route.query, () => {
    if (route.path !== '/admin/maintenance/backups') return;
    for (const k of FILTERS) f[k] = q(k);
    load();
}, { immediate: true, deep: true });
onBeforeUnmount(() => clearTimeout(timer));

const apply = () => {
    const next: LocationQueryRaw = { ...(q('sort') ? { sort: q('sort'), dir: q('dir') } : {}) };
    for (const k of FILTERS) if (f[k]) next[k] = f[k];
    router.replace({ query: next });
};
const clear = () => router.replace({ query: {} });
const goPage = (p: number) => router.replace({ query: { ...route.query, page: p > 1 ? String(p) : undefined } });
function sortBy(column: string) {
    const nextDir = sort.value === column && dir.value === 'desc' ? 'asc' : 'desc';
    router.replace({ query: { ...route.query, sort: column, dir: nextDir, page: undefined } });
}
const ariaSort = (column: string) => (sort.value === column ? (dir.value === 'asc' ? 'ascending' : 'descending') : 'none');
const COLUMNS: { key: string | null; label: string }[] = [
    { key: 'identifier', label: 'Backup ID' }, { key: null, label: 'File name' }, { key: 'type', label: 'Type' }, { key: 'created_at', label: 'Created' },
    { key: 'size_bytes', label: 'Size' }, { key: 'status', label: 'Status' }, { key: null, label: 'Created by' },
];

// ------------------------------------------------------------------ actions

const can = computed(() => list.value?.can ?? { manage: false, download: false, restore: false });
const active = computed(() => list.value?.active ?? null);

const creating = ref(false);
const createForm = useForm();
const note = ref('');
async function create() {
    const res = await createForm.run(() => api.post<{ backup: Backup }>('/admin/maintenance/backups', { note: note.value || null }));
    if (!res) return;
    creating.value = false;
    note.value = '';
    toast.success(res.data.backup.status === 'completed' ? `Backup ${res.data.backup.identifier} completed.` : `Backup ${res.data.backup.identifier} started. It runs in the background.`);
    load(true);
}

const deleteForm = useForm();
const deleteReason = ref('');
const deleteConfirmed = ref(false);
function askDelete(b: Backup) {
    deleting.value = b;
    deleteReason.value = '';
    deleteConfirmed.value = false;
    deleteForm.clear();
}
async function confirmDelete() {
    const b = deleting.value;
    if (!b) return;
    const res = await deleteForm.run(() => api.delete(`/admin/maintenance/backups/${b.uuid}`, { data: { confirm: deleteConfirmed.value, reason: deleteReason.value || null } }),
        b.run_status === 'queued' ? 'Backup cancelled.' : 'Backup deleted.');
    if (res) {
        deleting.value = null;
        load(true);
    }
}
const downloadUrl = (b: Backup) => `/api/admin/maintenance/backups/${b.uuid}/download`;
const canDelete = (b: Backup) => can.value.manage && !b.deleted_at && b.run_status !== 'running';
function restoreFinished() {
    load(true);
}
function closeRestore() {
    restoring.value = null;
    load(true);
}
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <div>
                <h1 class="page-title h4 mb-0">Database backups</h1>
                <p class="page-subtitle mb-0">Encrypted, verified MySQL backups, kept outside the web directory. Download, restore and deletion are recorded in the audit trail.</p>
            </div>
            <div class="ms-auto d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="loading" title="Refresh" aria-label="Refresh" @click="load()"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button>
                <button v-if="can.manage" type="button" class="btn btn-sm btn-primary" :disabled="!!active" @click="creating = true"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Create database backup</button>
            </div>
        </div>
        <MaintenanceNav />

        <div v-if="active" class="alert alert-info d-flex flex-wrap align-items-center gap-2" role="status">
            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
            <template v-if="active.kind === 'backup'">
                <span>Backup <strong class="font-monospace">{{ active.identifier }}</strong> is {{ active.status === 'queued' ? 'waiting for the background worker' : 'running' }}<template v-if="active.size_bytes"> · {{ formatBytes(active.size_bytes) }} written</template><template v-if="active.duration_seconds"> · {{ duration(active.duration_seconds) }}</template>.</span>
                <span class="small text-muted">You will be notified when it finishes.</span>
            </template>
            <span v-else>A restore of <strong>{{ active.backup?.identifier }}</strong> is in progress ({{ humanize(active.stage) }}). Other backups and restores wait until it finishes.</span>
        </div>

        <BackupSettings v-if="can.manage" />

        <form class="card mb-3" @submit.prevent="apply">
            <div class="card-body row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="bk-type">Type</label>
                    <select id="bk-type" v-model="f.type" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <option v-for="(label, k) in BACKUP_TYPES" :key="k" :value="k">{{ label }}</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="bk-status">Status</label>
                    <select id="bk-status" v-model="f.status" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <option v-for="s in ['queued', 'running', 'completed', 'failed', 'deleted']" :key="s" :value="s">{{ humanize(s) }}</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="bk-by">Created by</label>
                    <select id="bk-by" v-model="f.initiated_by" class="form-select form-select-sm">
                        <option value="">Anyone</option>
                        <option v-for="n in list?.initiators ?? []" :key="n" :value="n">{{ n }}</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="bk-from">Created from</label>
                    <input id="bk-from" v-model="f.date_from" type="date" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="bk-to">Created to</label>
                    <input id="bk-to" v-model="f.date_to" type="date" class="form-control form-control-sm" :min="f.date_from || undefined">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="bk-q">Search</label>
                    <input id="bk-q" v-model.trim="f.q" type="search" class="form-control form-control-sm" placeholder="ID, file or note">
                </div>
                <div class="col-auto d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Apply</button>
                    <button class="btn btn-outline-secondary btn-sm" type="button" @click="clear">Clear</button>
                </div>
            </div>
        </form>

        <LoadingState :loading="loading" :error="error">
            <div class="card mb-3">
                <div class="table-responsive">
                    <table class="table table-sm table-cards align-middle mb-0">
                        <caption class="visually-hidden">Database backups</caption>
                        <thead class="table-light">
                            <tr>
                                <th v-for="c in COLUMNS" :key="c.label" :aria-sort="c.key ? ariaSort(c.key) : undefined" class="text-nowrap">
                                    <button v-if="c.key" type="button" class="btn btn-link btn-sm p-0 text-reset text-decoration-none fw-semibold text-uppercase" style="font-size: inherit; letter-spacing: inherit" @click="sortBy(c.key)">
                                        {{ c.label }}<i class="bi ms-1" :class="sort === c.key ? (dir === 'asc' ? 'bi-caret-up-fill' : 'bi-caret-down-fill') : 'bi-chevron-expand text-muted'" aria-hidden="true"></i>
                                    </button>
                                    <template v-else>{{ c.label }}</template>
                                </th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="b in list?.data ?? []" :key="b.uuid" :class="{ 'text-muted': b.deleted_at }">
                                <td class="small font-monospace text-nowrap">{{ b.identifier }}</td>
                                <td class="small text-break">{{ b.filename ?? '—' }}<div v-if="b.note" class="text-muted">{{ b.note }}</div></td>
                                <td class="small">{{ BACKUP_TYPES[b.type] }}</td>
                                <td class="small text-nowrap">{{ formatDateTime(b.created_at) }}</td>
                                <td class="small text-nowrap">{{ formatBytes(b.size_bytes) }}</td>
                                <td>
                                    <StatusBadge :value="b.status" />
                                    <template v-if="b.available && b.encrypted"><i class="bi bi-lock-fill text-muted ms-1" title="Encrypted at rest" aria-hidden="true"></i><span class="visually-hidden">Encrypted at rest</span></template>
                                    <div v-if="b.error && b.status === 'failed'" class="small text-danger text-break" style="max-width: 22rem">{{ b.error }}</div>
                                </td>
                                <td class="small">{{ b.initiated_by_name ?? '—' }}</td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" :aria-label="`Details of ${b.identifier}`" title="Details" @click="details = b.uuid"><i class="bi bi-info-circle" aria-hidden="true"></i></button>
                                    <a v-if="can.download && b.available" class="btn btn-sm btn-outline-secondary ms-1" :href="downloadUrl(b)" download :aria-label="`Download ${b.identifier}`" title="Download (encrypted)"><i class="bi bi-download" aria-hidden="true"></i></a>
                                    <button v-if="can.restore && b.available" type="button" class="btn btn-sm btn-outline-danger ms-1" :disabled="!!active" :aria-label="`Restore ${b.identifier}`" title="Restore" @click="restoring = b"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                    <button v-if="canDelete(b)" type="button" class="btn btn-sm btn-outline-danger ms-1" :aria-label="`${b.run_status === 'queued' ? 'Cancel' : 'Delete'} ${b.identifier}`" :title="b.run_status === 'queued' ? 'Cancel' : 'Delete'" @click="askDelete(b)"><i class="bi" :class="b.run_status === 'queued' ? 'bi-x-lg' : 'bi-trash'" aria-hidden="true"></i></button>
                                </td>
                            </tr>
                            <tr v-if="!list?.data.length"><td colspan="8" class="text-center text-muted py-4">No backups match.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="list" class="card-body py-2">
                    <Pager :page="list.meta.current_page" :last-page="list.meta.last_page" :total="list.meta.total" @change="goPage" />
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>Restore history</div>
                <div class="table-responsive">
                    <table class="table table-sm table-cards align-middle mb-0">
                        <caption class="visually-hidden">Database restores</caption>
                        <thead class="table-light"><tr><th>Started</th><th>Backup restored</th><th>Requested by</th><th>Reason</th><th>Status</th><th>Safety backup</th><th>Finished</th></tr></thead>
                        <tbody>
                            <tr v-for="r in restores?.data ?? []" :key="r.uuid">
                                <td class="small text-nowrap">{{ formatDateTime(r.started_at ?? r.queued_at) }}</td>
                                <td class="small font-monospace">{{ r.backup?.identifier }}</td>
                                <td class="small">{{ r.requested_by_name }}<div v-if="r.requested_ip" class="text-muted">{{ r.requested_ip }}</div></td>
                                <td class="small text-break" style="max-width: 18rem">{{ r.reason }}</td>
                                <td>
                                    <StatusBadge :value="r.status" />
                                    <div v-if="r.rollback_status" class="small" :class="r.rollback_status === 'succeeded' ? 'text-muted' : 'text-danger'">Rollback {{ r.rollback_status }}</div>
                                    <div v-if="r.error" class="small text-danger text-break" style="max-width: 22rem">{{ r.error }}</div>
                                </td>
                                <td class="small font-monospace">{{ r.safety_backup?.identifier ?? '—' }}</td>
                                <td class="small text-nowrap">{{ formatDateTime(r.completed_at) }}</td>
                            </tr>
                            <tr v-if="!restores?.data.length"><td colspan="7" class="text-center text-muted py-3">The database has not been restored.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </LoadingState>

        <BaseModal v-if="creating" title="Create a database backup" @close="creating = false">
            <form id="bk-create" @submit.prevent="create">
                <p class="small">A complete backup of the database is taken in the background, compressed, encrypted and verified. Documents on disk are backed up separately by the server.</p>
                <label class="form-label" for="bk-note">Note (optional)</label>
                <input id="bk-note" v-model="note" class="form-control" maxlength="255" placeholder="e.g. Before closing advertisement 03/2026">
            </form>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="creating = false">Cancel</button>
                <button type="submit" form="bk-create" class="btn btn-primary" :disabled="createForm.busy.value"><i class="bi bi-database-add me-1" aria-hidden="true"></i>Create backup</button>
            </template>
        </BaseModal>

        <BaseModal v-if="deleting" :title="deleting.run_status === 'queued' ? 'Cancel this backup?' : 'Delete this backup?'" @close="deleting = null">
            <form id="bk-delete" @submit.prevent="confirmDelete">
                <p class="small mb-2">
                    <strong class="font-monospace">{{ deleting.identifier }}</strong> · {{ formatDateTime(deleting.created_at) }} · {{ formatBytes(deleting.size_bytes) }}
                </p>
                <p v-if="deleting.run_status !== 'queued'" class="small">The file is removed from the server and cannot be restored afterwards. The record stays in the history.</p>
                <label class="form-label" for="bk-del-reason">Reason (optional)</label>
                <input id="bk-del-reason" v-model="deleteReason" class="form-control mb-3" maxlength="255">
                <div class="form-check">
                    <input id="bk-del-confirm" v-model="deleteConfirmed" class="form-check-input" type="checkbox">
                    <label class="form-check-label small" for="bk-del-confirm">{{ deleting.run_status === 'queued' ? 'Cancel this backup before it starts' : 'Yes, delete this backup permanently' }}</label>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="deleting = null">Keep it</button>
                <button type="submit" form="bk-delete" class="btn btn-danger" :disabled="!deleteConfirmed || deleteForm.busy.value">{{ deleting.run_status === 'queued' ? 'Cancel backup' : 'Delete backup' }}</button>
            </template>
        </BaseModal>

        <BackupDetailsModal v-if="details" :uuid="details" :can-manage="can.manage" @close="details = null" @changed="load(true)" />
        <RestoreDialog v-if="restoring" :backup="restoring" @close="closeRestore" @finished="restoreFinished" />
    </div>
</template>
