<script setup lang="ts">
/**
 * Reports: pick a report, filter, preview (first 200 rows) and export the
 * full result as Excel / CSV / PDF. Report and filters live in the URL.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router';
import { api, download, toApiError } from '@/api/client';
import { useToast } from '@/stores/toast';
import LoadingState from '@/components/LoadingState.vue';
import ApplicationFilters from '@/views/admin/applications/parts/ApplicationFilters.vue';
import {
    FILTER_KEYS, activeFilters, emptyFilters, loadAdOptions, loadCycleOptions, type AdOption, type CycleOption, type Filters,
} from '@/views/admin/applications/parts/options';
import { formatDateTime } from '@/utils/format';

interface ReportDef { key: string; title: string; kind: 'aggregate' | 'list' | string; sensitive: boolean }
interface ReportData { title: string; headings: string[]; rows: (string | number | null)[][]; truncated: boolean }

const route = useRoute();
const router = useRouter();
const toast = useToast();

const defs = ref<ReportDef[]>([]);
const defsLoading = ref(true);
const defsError = ref<string | null>(null);
const ads = ref<AdOption[]>([]);
const cycles = ref<CycleOption[]>([]);

const q = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '');
const key = computed(() => q('report'));
const current = computed(() => defs.value.find((d) => d.key === key.value) ?? null);
const filters = computed<Filters>(() => ({ ...emptyFilters(), ...Object.fromEntries(FILTER_KEYS.map((k) => [k, q(k)])) }));
const groups = computed(() => [
    { label: 'Summary reports', items: defs.value.filter((d) => d.kind === 'aggregate') },
    { label: 'Application lists', items: defs.value.filter((d) => d.kind !== 'aggregate' && !d.sensitive) },
    { label: 'Sensitive exports', items: defs.value.filter((d) => d.kind !== 'aggregate' && d.sensitive) },
].filter((g) => g.items.length));

const data = ref<ReportData | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const generatedAt = ref<string | null>(null);

function choose(k: string) {
    router.replace({ query: { ...activeFilters(filters.value), report: k } });
}
function applyFilters(f: Filters) {
    const next: LocationQueryRaw = { ...activeFilters(f) };
    if (key.value) next.report = key.value;
    router.replace({ query: next });
}

let seq = 0;
async function load() {
    data.value = null;
    error.value = null;
    if (!current.value) return;
    const mine = ++seq;
    loading.value = true;
    try {
        const res = await api.get<ReportData>(`/admin/reports/${current.value.key}`, { params: activeFilters(filters.value) });
        if (mine !== seq) return;
        data.value = res.data;
        generatedAt.value = new Date().toISOString();
    } catch (e) {
        if (mine === seq) error.value = toApiError(e).message;
    } finally {
        if (mine === seq) loading.value = false;
    }
}

const reportsPath = route.path;
watch(() => route.query, () => { if (route.path === reportsPath) load(); }, { deep: true });

onMounted(async () => {
    try { defs.value = (await api.get<ReportDef[]>('/admin/reports')).data; }
    catch (e) { defsError.value = toApiError(e).message; }
    finally { defsLoading.value = false; }
    load();
    try {
        ads.value = await loadAdOptions();
        cycles.value = await loadCycleOptions(ads.value);
    } catch { /* filters still work without option lists */ }
});

const exporting = ref<string | null>(null);
async function exportAs(format: 'xlsx' | 'csv' | 'pdf') {
    if (!current.value) return;
    if (current.value.sensitive && !window.confirm('This export contains personal or confidential data. It will be logged against your account. Continue?')) return;
    exporting.value = format;
    const qs = new URLSearchParams(activeFilters(filters.value)).toString();
    try {
        await download(`/admin/reports/${current.value.key}/export/${format}${qs ? `?${qs}` : ''}`, `${current.value.key}.${format}`);
    } catch (e) {
        toast.error(toApiError(e).message);
    } finally { exporting.value = null; }
}

const filterSummary = computed(() => {
    const f = activeFilters(filters.value);
    const parts: string[] = [];
    if (f.advertisement_id) parts.push(`Advertisement: ${ads.value.find((a) => String(a.id) === f.advertisement_id)?.number ?? f.advertisement_id}`);
    if (f.post_id) parts.push(`Post: ${ads.value.flatMap((a) => a.posts).find((p) => String(p.id) === f.post_id)?.name ?? f.post_id}`);
    for (const [k, v] of Object.entries(f)) if (!['advertisement_id', 'post_id'].includes(k)) parts.push(`${k.replace(/_/g, ' ')}: ${v}`);
    return parts.join(' · ');
});
const printPage = () => window.print();
const numeric = (v: unknown) => typeof v === 'number';
</script>

<template>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 no-print">
        <h1 class="page-title h4 mb-0">Reports &amp; exports</h1>
    </div>

    <LoadingState :loading="defsLoading" :error="defsError">
        <div class="row g-3">
            <div class="col-lg-3 no-print">
                <div class="card">
                    <div class="list-group list-group-flush small">
                        <template v-for="g in groups" :key="g.label">
                            <div class="list-group-item bg-light text-uppercase text-muted fw-semibold" style="font-size: .7rem; letter-spacing: .05em">{{ g.label }}</div>
                            <button v-for="d in g.items" :key="d.key" type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-start gap-2" :class="{ active: d.key === key }" @click="choose(d.key)">
                                <span>{{ d.title }}</span>
                                <i v-if="d.sensitive" class="bi bi-shield-lock-fill" :class="d.key === key ? 'text-white' : 'text-danger'" title="Sensitive — exports are logged"></i>
                            </button>
                        </template>
                        <div v-if="!defs.length" class="list-group-item text-muted">No reports are available to you.</div>
                    </div>
                </div>
            </div>

            <div class="col-lg-9 report-col">
                <div v-if="!current" class="alert alert-info no-print">Choose a report on the left.</div>
                <template v-else>
                    <div class="no-print">
                        <ApplicationFilters :model-value="filters" :ads="ads" :cycles="cycles" id-prefix="rp" @apply="applyFilters" />
                    </div>

                    <div v-if="current.sensitive" class="confidential-banner rounded p-2 mb-3 small no-print">
                        <i class="bi bi-shield-lock-fill me-1"></i><strong>Sensitive report — exports are logged.</strong>
                        Each export records your name, the filters and the row count in the export log and audit trail. Handle the downloaded file under the institute's data-protection rules.
                    </div>

                    <div class="card report-print">
                        <div class="card-header d-flex flex-wrap align-items-center gap-2">
                            <div class="me-auto">
                                <div class="fw-semibold">{{ data?.title ?? current.title }}</div>
                                <div class="small text-muted">{{ filterSummary || 'All applications in your scope' }}<span class="print-only"> · Generated {{ formatDateTime(generatedAt) }}</span></div>
                            </div>
                            <div class="btn-group btn-group-sm no-print" role="group" aria-label="Export">
                                <button class="btn btn-outline-success" :disabled="!!exporting || !data" @click="exportAs('xlsx')"><span v-if="exporting === 'xlsx'" class="spinner-border spinner-border-sm me-1"></span><i v-else class="bi bi-file-earmark-excel me-1"></i>Excel</button>
                                <button class="btn btn-outline-secondary" :disabled="!!exporting || !data" @click="exportAs('csv')"><span v-if="exporting === 'csv'" class="spinner-border spinner-border-sm me-1"></span><i v-else class="bi bi-filetype-csv me-1"></i>CSV</button>
                                <button class="btn btn-outline-danger" :disabled="!!exporting || !data" @click="exportAs('pdf')"><span v-if="exporting === 'pdf'" class="spinner-border spinner-border-sm me-1"></span><i v-else class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
                                <button class="btn btn-outline-secondary" :disabled="!data" title="Print" @click="printPage"><i class="bi bi-printer"></i></button>
                            </div>
                        </div>
                        <LoadingState :loading="loading" :error="error">
                            <template v-if="data">
                                <div v-if="data.truncated" class="alert alert-warning rounded-0 border-0 border-bottom small mb-0 no-print">
                                    Preview shows the first {{ data.rows.length }} rows only. Export to get every row.
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped table-bordered mb-0 report-table">
                                        <thead class="table-light"><tr><th class="text-muted" style="width: 2.5rem">#</th><th v-for="(h, i) in data.headings" :key="i" class="text-nowrap">{{ h }}</th></tr></thead>
                                        <tbody>
                                            <tr v-for="(row, ri) in data.rows" :key="ri">
                                                <td class="text-muted small">{{ ri + 1 }}</td>
                                                <td v-for="(c, ci) in row" :key="ci" class="small" :class="{ 'text-end': numeric(c) }">{{ c ?? '' }}</td>
                                            </tr>
                                            <tr v-if="!data.rows.length"><td :colspan="data.headings.length + 1" class="text-center text-muted py-3">No rows for these filters.</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="card-footer small text-muted">{{ data.rows.length }}{{ data.truncated ? '+' : '' }} row{{ data.rows.length === 1 ? '' : 's' }}</div>
                            </template>
                        </LoadingState>
                    </div>
                </template>
            </div>
        </div>
    </LoadingState>
</template>

<style>
.print-only { display: none; }
@media print {
    .shell-sidebar, .shell-topbar, .no-print, .flash-stack { display: none !important; }
    .print-only { display: inline; }
    main { padding: 0 !important; }
    .report-col { width: 100% !important; flex: 0 0 100% !important; max-width: 100% !important; }
    .report-print { border: 0 !important; }
    .report-print .table-responsive { overflow: visible !important; }
    .report-table { font-size: 9pt; }
    .report-table th, .report-table td { padding: 2px 4px !important; }
    body { background: #fff !important; }
}
</style>
