<script setup lang="ts">
/**
 * One log file, newest entries first, read from the server in pages (the file is never loaded
 * whole). Filters live in the URL query. Text arrives already masked by the server.
 */
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router';
import { api, toApiError } from '@/api/client';
import { useAuth } from '@/stores/auth';
import MaintenanceNav from './parts/MaintenanceNav.vue';
import { LEVEL_BADGE, type LogEntry } from './parts/types';
import { formatBytes, formatDateTime, humanize } from '@/utils/format';

const props = defineProps<{ file: string }>();
interface EntriesResponse { entries: LogEntry[]; next_cursor: number | null; scanned_bytes: number; limited: boolean; structured: boolean; file: { name: string; size: number; modified_at: string } }

const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
const SEVERE = ['error', 'critical', 'alert', 'emergency'];
const CATEGORIES: Record<string, string> = {
    database_connection: 'Database connection', sql: 'SQL / query errors', mail: 'Email / SMTP delivery', queue: 'Queue jobs',
    authentication: 'Authentication', authorization: 'Authorization', filesystem: 'Files and permissions', application: 'Other application errors',
};

const route = useRoute();
const router = useRouter();
const auth = useAuth();

const q = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '');
const VIEW = '/admin/maintenance/logs/view';
const qLevels = () => (typeof route.query.levels === 'string' && route.query.levels ? route.query.levels.split(',').filter((l) => LEVELS.includes(l)) : []);
const f = reactive({ levels: [] as string[], q: '', exception: '', category: '', from: '', to: '' });

const entries = ref<LogEntry[]>([]);
const meta = ref<EntriesResponse | null>(null);
const loading = ref(false);
const loadingMore = ref(false);
const error = ref<string | null>(null);
const open = reactive(new Set<number>());
let seq = 0;

function params(cursor: number | null) {
    const p: Record<string, unknown> = { file: props.file, limit: 100 };
    if (cursor !== null) p.cursor = cursor;
    for (const k of ['q', 'exception', 'category', 'from', 'to'] as const) if (q(k)) p[k] = q(k);
    const levels = qLevels();
    if (levels.length) p.levels = levels;   // axios sends levels[]=…&levels[]=…
    return p;
}

async function load(cursor: number | null = null) {
    const mine = ++seq;
    cursor === null ? (loading.value = true) : (loadingMore.value = true);
    error.value = null;
    try {
        const { data } = await api.get<EntriesResponse>('/admin/maintenance/logs/entries', { params: params(cursor) });
        if (mine !== seq) return;
        if (cursor === null) { entries.value = data.entries; open.clear(); } else entries.value.push(...data.entries);
        meta.value = data;
    } catch (e) {
        if (mine === seq) error.value = toApiError(e).message;
    } finally {
        if (mine === seq) { loading.value = false; loadingMore.value = false; }
    }
}

watch(() => [props.file, route.query], () => {
    if (route.path !== VIEW || !props.file) return;
    f.levels = qLevels();
    for (const k of ['q', 'exception', 'category', 'from', 'to'] as const) f[k] = q(k);
    load();
}, { immediate: true, deep: true });

function apply() {
    const next: LocationQueryRaw = { file: props.file };
    if (f.levels.length) next.levels = f.levels.join(',');
    for (const k of ['q', 'exception', 'category', 'from', 'to'] as const) if (f[k]) next[k] = f[k];
    router.replace({ query: next });
}
const clear = () => router.replace({ query: { file: props.file } });
function severeOnly() {
    f.levels = [...SEVERE];
    apply();
}
const toggle = (o: number) => (open.has(o) ? open.delete(o) : open.add(o));
const filtered = computed(() => Object.keys(route.query).some((k) => k !== 'file'));
const downloadUrl = computed(() => `/api/admin/maintenance/logs/download?${new URLSearchParams({ file: props.file })}`);
const timestamp = (e: LogEntry) => (e.timestamp ? formatDateTime(e.timestamp.replace(' ', 'T')) : '');
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <div class="min-w-0">
                <h1 class="page-title h4 mb-0 text-break">{{ file }}</h1>
                <p class="page-subtitle mb-0">
                    <template v-if="meta">{{ formatBytes(meta.file.size) }} · last written {{ formatDateTime(meta.file.modified_at) }} · </template>newest entries first; secrets are masked
                </p>
            </div>
            <div class="ms-auto d-flex gap-2">
                <router-link to="/admin/maintenance/logs" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>All logs</router-link>
                <a v-if="auth.can('logs.download')" :href="downloadUrl" download class="btn btn-sm btn-outline-secondary"><i class="bi bi-download me-1" aria-hidden="true"></i>Download</a>
                <button type="button" class="btn btn-sm btn-primary" :disabled="loading" @click="load()"><i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Refresh</button>
            </div>
        </div>
        <MaintenanceNav />

        <form class="card mb-3" @submit.prevent="apply">
            <div class="card-body">
                <fieldset class="mb-2">
                    <legend class="form-label small mb-1">Levels</legend>
                    <div class="d-flex flex-wrap gap-1 align-items-center">
                        <template v-for="l in LEVELS" :key="l">
                            <input :id="`lv-${l}`" v-model="f.levels" class="btn-check" type="checkbox" :value="l" autocomplete="off">
                            <label class="btn btn-sm btn-outline-secondary text-uppercase" :for="`lv-${l}`" style="font-size: .7rem">{{ l }}</label>
                        </template>
                        <button type="button" class="btn btn-sm btn-link" @click="severeOnly">Errors and above</button>
                    </div>
                </fieldset>
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="lv-q">Keyword</label>
                        <input id="lv-q" v-model.trim="f.q" type="search" class="form-control form-control-sm" maxlength="200" placeholder="Text in the entry">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1" for="lv-ex">Exception class</label>
                        <input id="lv-ex" v-model.trim="f.exception" class="form-control form-control-sm font-monospace" maxlength="200" placeholder="e.g. QueryException">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1" for="lv-cat">Kind of problem</label>
                        <select id="lv-cat" v-model="f.category" class="form-select form-select-sm">
                            <option value="">Any</option>
                            <option v-for="(label, k) in CATEGORIES" :key="k" :value="k">{{ label }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="lv-from">From</label>
                        <input id="lv-from" v-model="f.from" type="datetime-local" class="form-control form-control-sm">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="lv-to">To</label>
                        <input id="lv-to" v-model="f.to" type="datetime-local" class="form-control form-control-sm" :min="f.from || undefined">
                    </div>
                    <div class="col-auto d-flex gap-2">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Apply</button>
                        <button class="btn btn-outline-secondary btn-sm" type="button" @click="clear">Clear</button>
                    </div>
                </div>
            </div>
        </form>

        <div v-if="error" class="alert alert-danger">{{ error }}</div>
        <div v-else-if="loading && !entries.length" class="card"><div class="empty-state text-muted" role="status"><span class="spinner-border spinner-border-sm mb-2" aria-hidden="true"></span><span class="small">Reading the log…</span></div></div>
        <template v-else>
            <div v-if="meta && !meta.structured" class="alert alert-info small"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>This file is not in Laravel's log format, so it is shown line by line and the level filters do not apply.</div>
            <div class="card" :class="{ 'opacity-50': loading }">
                <ul class="list-group list-group-flush" aria-label="Log entries">
                    <li v-for="e in entries" :key="e.offset" class="list-group-item py-2">
                        <div class="d-flex flex-wrap align-items-start gap-2">
                            <span v-if="e.level" class="badge text-uppercase" :class="LEVEL_BADGE[e.level] ?? 'text-bg-secondary'">{{ e.level }}</span>
                            <span v-if="e.timestamp" class="small text-muted text-nowrap font-monospace">{{ timestamp(e) }}</span>
                            <span v-if="e.category" class="badge text-bg-light border">{{ CATEGORIES[e.category] ?? humanize(e.category) }}</span>
                            <span v-if="e.exception" class="small font-monospace text-break" style="color: var(--danger-fg)">{{ e.exception }}</span>
                            <button v-if="e.details" type="button" class="btn btn-sm btn-outline-secondary ms-auto py-0" :aria-expanded="open.has(e.offset)" @click="toggle(e.offset)">
                                {{ open.has(e.offset) ? 'Hide' : 'Details' }}<i class="bi ms-1" :class="open.has(e.offset) ? 'bi-chevron-up' : 'bi-chevron-down'" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="small mt-1 text-break log-text">{{ e.message }}</div>
                        <pre v-if="open.has(e.offset)" class="small mt-2 mb-0 p-2 rounded log-details">{{ e.details }}<template v-if="e.truncated">&#10;… (shortened; download the file for the complete entry)</template></pre>
                    </li>
                    <li v-if="!entries.length" class="list-group-item text-center text-muted py-4">{{ filtered ? 'No entries match these filters.' : 'This log is empty.' }}</li>
                </ul>
                <div v-if="meta" class="card-body py-2 d-flex flex-wrap align-items-center gap-2 small text-muted">
                    <span>{{ entries.length }} entr{{ entries.length === 1 ? 'y' : 'ies' }} shown</span>
                    <span v-if="meta.limited">· searched {{ formatBytes(meta.scanned_bytes) }} of the file in this step</span>
                    <button v-if="meta.next_cursor !== null" type="button" class="btn btn-sm btn-outline-secondary ms-auto" :disabled="loadingMore" @click="load(meta.next_cursor)">
                        <span v-if="loadingMore" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>{{ meta.limited ? 'Keep searching older entries' : 'Load older entries' }}
                    </button>
                    <span v-else class="ms-auto">Start of the file reached.</span>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
.log-text { white-space: pre-wrap; font-family: var(--bs-font-monospace); font-size: .78rem; color: var(--ink); }
.log-details { white-space: pre-wrap; word-break: break-word; max-height: 28rem; overflow: auto; background: var(--surface-sunken); color: var(--ink); border: 1px solid var(--line); }
</style>
