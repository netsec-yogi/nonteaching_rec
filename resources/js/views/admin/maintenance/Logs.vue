<script setup lang="ts">
/**
 * Application log files and archives: view (masked), download originals, ZIP several, archive,
 * delete (never the files being written to), and the retention settings. Each action follows the
 * user's permission; the server enforces it.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import { useToast } from '@/stores/toast';
import BaseModal from '@/components/BaseModal.vue';
import LoadingState from '@/components/LoadingState.vue';
import StatCard from '@/components/StatCard.vue';
import MaintenanceNav from './parts/MaintenanceNav.vue';
import LogSettings from './parts/LogSettings.vue';
import type { LogFile, LogSettingsValues, LogStorage } from './parts/types';
import { formatBytes, formatDate, formatDateTime } from '@/utils/format';

interface Payload {
    files: LogFile[]; archives: LogFile[]; storage: LogStorage; settings: LogSettingsValues;
    rotation: { channel: string; daily_days: number }; can: { download: boolean; manage: boolean };
}
interface BulkResult { done: { name: string; result: string }[]; failed: { name: string; error: string }[] }

const toast = useToast();
const data = ref<Payload | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const selected = reactive({ log: new Set<string>(), archive: new Set<string>() });

async function load() {
    try {
        data.value = (await api.get<Payload>('/admin/maintenance/logs')).data;
        error.value = null;
        for (const kind of ['log', 'archive'] as const) {
            const names = new Set((kind === 'log' ? data.value.files : data.value.archives).map((x) => x.name));
            for (const n of [...selected[kind]]) if (!names.has(n)) selected[kind].delete(n);
        }
    } catch (e) {
        error.value = toApiError(e).message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

const can = computed(() => data.value?.can ?? { download: false, manage: false });
const storage = computed(() => data.value?.storage ?? null);
const count = computed(() => selected.log.size + selected.archive.size);
const usedPct = computed(() => (storage.value ? Math.min(100, Math.round((storage.value.total_bytes / Math.max(1, storage.value.warn_at_bytes)) * 100)) : 0));

const toggle = (kind: 'log' | 'archive', name: string) => (selected[kind].has(name) ? selected[kind].delete(name) : selected[kind].add(name));
const all = (kind: 'log' | 'archive') => (kind === 'log' ? data.value?.files ?? [] : data.value?.archives ?? []);
function toggleAll(kind: 'log' | 'archive', on: boolean) {
    selected[kind].clear();
    if (on) for (const x of all(kind)) selected[kind].add(x.name);
}
const allChecked = (kind: 'log' | 'archive') => all(kind).length > 0 && all(kind).every((x) => selected[kind].has(x.name));

const viewLink = (x: LogFile) => ({ path: '/admin/maintenance/logs/view', query: { file: x.name } });
const downloadUrl = (x: LogFile) => `/api/admin/maintenance/logs/download?${new URLSearchParams({ file: x.name, kind: x.kind })}`;
const zipUrl = computed(() => {
    const p = new URLSearchParams();
    for (const n of selected.log) p.append('files[]', n);
    for (const n of selected.archive) p.append('archives[]', n);
    return `/api/admin/maintenance/logs/download-zip?${p}`;
});

function report(r: BulkResult, verb: string) {
    if (r.done.length) toast.success(`${r.done.length} file${r.done.length === 1 ? '' : 's'} ${verb}.`);
    for (const x of r.failed) toast.show(`${x.name}: ${x.error}`, 'warning', 8000);
}

const archiveForm = useForm();
async function archive(names: string[]) {
    const res = await archiveForm.run(() => api.post<BulkResult>('/admin/maintenance/logs/archive', { files: names }));
    if (res) {
        report(res.data, 'archived');
        selected.log.clear();
        load();
    }
}

const deleting = ref<{ kind: 'log' | 'archive'; name: string }[] | null>(null);
const deleteConfirmed = ref(false);
const deleteForm = useForm();
function askDelete(files: { kind: 'log' | 'archive'; name: string }[]) {
    deleting.value = files;
    deleteConfirmed.value = false;
}
const selectedFiles = () => [...[...selected.log].map((name) => ({ kind: 'log' as const, name })), ...[...selected.archive].map((name) => ({ kind: 'archive' as const, name }))];
async function confirmDelete() {
    if (!deleting.value) return;
    const res = await deleteForm.run(() => api.post<BulkResult>('/admin/maintenance/logs/delete', { files: deleting.value, confirm: deleteConfirmed.value }));
    if (res) {
        report(res.data, 'deleted');
        deleting.value = null;
        selected.log.clear();
        selected.archive.clear();
        load();
    }
}
const settingsSaved = (v: LogSettingsValues) => {
    if (data.value) data.value.settings = v;
    load();
};
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <div>
                <h1 class="page-title h4 mb-0">Application logs</h1>
                <p class="page-subtitle mb-0">Laravel log files in storage/logs. Passwords, keys, tokens and identity numbers are masked when viewed; downloads are the original files.</p>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" :disabled="loading" title="Refresh" aria-label="Refresh" @click="load"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button>
        </div>
        <MaintenanceNav />

        <LoadingState :loading="loading" :error="error">
            <template v-if="data && storage">
                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3"><StatCard label="Log storage" :value="formatBytes(storage.total_bytes)" icon="hdd" :tone="storage.over_threshold ? 'warning' : 'brand'" :hint="`${formatBytes(storage.log_bytes)} in logs · ${formatBytes(storage.archive_bytes)} archived`" /></div>
                    <div class="col-6 col-lg-3"><StatCard label="Files" :value="storage.file_count" icon="file-earmark-text" :hint="`${storage.archive_count} archive${storage.archive_count === 1 ? '' : 's'}`" /></div>
                    <div class="col-6 col-lg-3"><StatCard label="Last written" :value="storage.latest_modified ? formatDateTime(storage.latest_modified.at) : '—'" icon="clock" :hint="storage.latest_modified?.name" /></div>
                    <div class="col-6 col-lg-3"><StatCard label="Free disk space" :value="formatBytes(storage.disk_free_bytes)" icon="device-hdd" :hint="storage.disk_total_bytes ? `of ${formatBytes(storage.disk_total_bytes)}` : null" /></div>
                </div>
                <div class="card mb-3">
                    <div class="card-body py-2">
                        <div class="d-flex justify-content-between small text-muted mb-1"><span>Log storage against the warning threshold</span><span>{{ usedPct }}% of {{ formatBytes(storage.warn_at_bytes) }}</span></div>
                        <div class="progress" style="height: .5rem" role="progressbar" :aria-valuenow="usedPct" aria-valuemin="0" aria-valuemax="100" aria-label="Log storage against the warning threshold">
                            <div class="progress-bar" :class="storage.over_threshold ? 'bg-danger' : usedPct > 75 ? 'bg-warning' : 'bg-success'" :style="{ width: usedPct + '%' }"></div>
                        </div>
                        <p v-if="storage.over_threshold" class="small mb-0 mt-2" style="color: var(--danger-fg)"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Log storage is above the threshold. Archive or delete old files.</p>
                    </div>
                </div>

                <div v-if="count" class="card mb-3 border-primary">
                    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
                        <span class="small fw-semibold">{{ count }} selected</span>
                        <a v-if="can.download" :href="zipUrl" class="btn btn-sm btn-outline-secondary" download><i class="bi bi-file-zip me-1" aria-hidden="true"></i>Download as ZIP</a>
                        <button v-if="can.manage && selected.log.size" type="button" class="btn btn-sm btn-outline-secondary" :disabled="archiveForm.busy.value" @click="archive([...selected.log])"><i class="bi bi-archive me-1" aria-hidden="true"></i>Archive</button>
                        <button v-if="can.manage" type="button" class="btn btn-sm btn-outline-danger" @click="askDelete(selectedFiles())"><i class="bi bi-trash me-1" aria-hidden="true"></i>Delete</button>
                        <button type="button" class="btn btn-sm btn-link ms-auto" @click="selected.log.clear(); selected.archive.clear()">Clear selection</button>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Log files</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-cards align-middle mb-0">
                            <caption class="visually-hidden">Log files</caption>
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 2rem"><input id="lf-all" class="form-check-input" type="checkbox" :checked="allChecked('log')" aria-label="Select all log files" @change="toggleAll('log', ($event.target as HTMLInputElement).checked)"></th>
                                    <th>File</th><th>Date</th><th>Size</th><th>Last modified</th><th>Status</th><th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(x, i) in data.files" :key="x.name">
                                    <td><input :id="`lf-${i}`" class="form-check-input" type="checkbox" :checked="selected.log.has(x.name)" :aria-label="`Select ${x.name}`" @change="toggle('log', x.name)"></td>
                                    <td class="small font-monospace text-break"><router-link :to="viewLink(x)">{{ x.name }}</router-link></td>
                                    <td class="small text-nowrap">{{ formatDate(x.date) }}</td>
                                    <td class="small text-nowrap">{{ formatBytes(x.size) }}</td>
                                    <td class="small text-nowrap">{{ formatDateTime(x.modified_at) }}</td>
                                    <td><span v-if="x.active" class="badge text-bg-success" :title="x.active_reason ?? undefined"><i class="bi bi-pencil-fill me-1" aria-hidden="true"></i>Active</span><span v-else class="badge text-bg-light border">Closed</span>
                                        <div v-if="x.active && x.active_reason" class="small text-muted">{{ x.active_reason }}</div></td>
                                    <td class="text-end text-nowrap">
                                        <router-link :to="viewLink(x)" class="btn btn-sm btn-outline-secondary" :aria-label="`View ${x.name}`" title="View"><i class="bi bi-eye" aria-hidden="true"></i></router-link>
                                        <a v-if="can.download" :href="downloadUrl(x)" download class="btn btn-sm btn-outline-secondary ms-1" :aria-label="`Download ${x.name}`" title="Download original"><i class="bi bi-download" aria-hidden="true"></i></a>
                                        <button v-if="can.manage && !x.active" type="button" class="btn btn-sm btn-outline-secondary ms-1" :disabled="archiveForm.busy.value" :aria-label="`Archive ${x.name}`" title="Archive (compress)" @click="archive([x.name])"><i class="bi bi-archive" aria-hidden="true"></i></button>
                                        <button v-if="can.manage && !x.active" type="button" class="btn btn-sm btn-outline-danger ms-1" :aria-label="`Delete ${x.name}`" title="Delete" @click="askDelete([{ kind: 'log', name: x.name }])"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                    </td>
                                </tr>
                                <tr v-if="!data.files.length"><td colspan="7" class="text-center text-muted py-3">No log files.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-archive me-1" aria-hidden="true"></i>Archived logs</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-cards align-middle mb-0">
                            <caption class="visually-hidden">Archived log files</caption>
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 2rem"><input id="la-all" class="form-check-input" type="checkbox" :checked="allChecked('archive')" aria-label="Select all archives" @change="toggleAll('archive', ($event.target as HTMLInputElement).checked)"></th>
                                    <th>Archive</th><th>Date</th><th>Size</th><th>Archived file date</th><th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(x, i) in data.archives" :key="x.name">
                                    <td><input :id="`la-${i}`" class="form-check-input" type="checkbox" :checked="selected.archive.has(x.name)" :aria-label="`Select ${x.name}`" @change="toggle('archive', x.name)"></td>
                                    <td class="small font-monospace text-break">{{ x.name }}</td>
                                    <td class="small text-nowrap">{{ formatDate(x.date) }}</td>
                                    <td class="small text-nowrap">{{ formatBytes(x.size) }}</td>
                                    <td class="small text-nowrap">{{ formatDateTime(x.modified_at) }}</td>
                                    <td class="text-end text-nowrap">
                                        <a v-if="can.download" :href="downloadUrl(x)" download class="btn btn-sm btn-outline-secondary" :aria-label="`Download ${x.name}`" title="Download"><i class="bi bi-download" aria-hidden="true"></i></a>
                                        <button v-if="can.manage" type="button" class="btn btn-sm btn-outline-danger ms-1" :aria-label="`Delete ${x.name}`" title="Delete" @click="askDelete([{ kind: 'archive', name: x.name }])"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                    </td>
                                </tr>
                                <tr v-if="!data.archives.length"><td colspan="6" class="text-center text-muted py-3">No archived logs yet.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <LogSettings v-if="can.manage" :values="data.settings" :daily-days="data.rotation.daily_days" :channel="data.rotation.channel" @saved="settingsSaved" />
            </template>
        </LoadingState>

        <BaseModal v-if="deleting" title="Delete log files?" @close="deleting = null">
            <form id="lf-delete" @submit.prevent="confirmDelete">
                <p class="small mb-2">These files are removed from the server permanently:</p>
                <ul class="small font-monospace mb-3">
                    <li v-for="d in deleting" :key="d.kind + d.name">{{ d.name }}<span v-if="d.kind === 'archive'" class="text-muted"> (archive)</span></li>
                </ul>
                <p class="small text-muted">Files that are being written to are skipped. Consider archiving instead: archives are compressed and can still be downloaded.</p>
                <div class="form-check">
                    <input id="lf-del-confirm" v-model="deleteConfirmed" class="form-check-input" type="checkbox">
                    <label class="form-check-label small" for="lf-del-confirm">Yes, delete {{ deleting.length === 1 ? 'this file' : `these ${deleting.length} files` }}</label>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="deleting = null">Cancel</button>
                <button type="submit" form="lf-delete" class="btn btn-danger" :disabled="!deleteConfirmed || deleteForm.busy.value">Delete</button>
            </template>
        </BaseModal>
    </div>
</template>
