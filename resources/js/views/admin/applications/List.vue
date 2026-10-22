<script setup lang="ts">
/**
 * Staff application list: server-side filtering, sorting and pagination
 * (filters live in the URL query), with bulk scrutiny assignment and
 * forwarding to the Screening Committee.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router';
import { api, toApiError } from '@/api/client';
import { useAuth } from '@/stores/auth';
import { useToast } from '@/stores/toast';
import { useForm } from '@/composables/useForm';
import StatusBadge from '@/components/StatusBadge.vue';
import LoadingState from '@/components/LoadingState.vue';
import Pager from '@/components/Pager.vue';
import BaseModal from '@/components/BaseModal.vue';
import FieldError from '@/components/FieldError.vue';
import { formatDateTime, humanize } from '@/utils/format';
import ApplicationFilters from './parts/ApplicationFilters.vue';
import {
    FILTER_KEYS, activeFilters, emptyFilters, loadAdOptions, loadCycleOptions, loadUserOptions, loadWorkflow,
    type AdOption, type CommitteeOption, type CycleOption, type Filters, type StageOption, type UserOption,
} from './parts/options';

interface Row {
    uuid: string; reference_no: string | null; name: string | null; email: string | null; category: string | null; gender: string | null;
    advertisement: string; post: string; post_code: string; department: string | null; status: string; scrutiny_status: string | null;
    current_stage: string | null; outcome: string | null; committee_status: string | null; fee_status: string | null; referee_status: string | null;
    endorsement_status: string | null; document_status: string | null; submitted_at: string | null; updated_at: string | null;
}
interface Meta { current_page: number; last_page: number; per_page: number; total: number }

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const toast = useToast();

const ads = ref<AdOption[]>([]);
const cycles = ref<CycleOption[]>([]);
const rows = ref<Row[]>([]);
const meta = ref<Meta>({ current_page: 1, last_page: 1, per_page: 25, total: 0 });
const loading = ref(true);
const error = ref<string | null>(null);

const SORTS = ['submitted_at', 'reference_no', 'name', 'created_at'] as const;
const q = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '');
const filters = computed<Filters>(() => ({ ...emptyFilters(), ...Object.fromEntries(FILTER_KEYS.map((k) => [k, q(k)])) }));
const sort = computed(() => (SORTS as readonly string[]).includes(q('sort')) ? q('sort') : 'submitted_at');
const direction = computed(() => (q('direction') === 'asc' ? 'asc' : 'desc'));
const page = computed(() => Math.max(1, Number(q('page')) || 1));
const perPage = computed(() => [10, 25, 50, 100].includes(Number(q('per_page'))) ? Number(q('per_page')) : 25);
const activeCount = computed(() => Object.keys(activeFilters(filters.value)).length);

function go(patch: Record<string, string | number | null>) {
    const next: LocationQueryRaw = { ...activeFilters(filters.value), sort: sort.value, direction: direction.value, page: page.value, per_page: perPage.value, ...patch };
    for (const k of Object.keys(next)) if (next[k] === '' || next[k] === null) delete next[k];
    if (next.sort === 'submitted_at') delete next.sort;
    if (next.direction === 'desc') delete next.direction;
    if (Number(next.page) === 1) delete next.page;
    if (Number(next.per_page) === 25) delete next.per_page;
    router.replace({ query: next });
}

function applyFilters(f: Filters) {
    const base: Record<string, string | null> = Object.fromEntries(FILTER_KEYS.map((k) => [k, null]));
    selected.clear();
    go({ ...base, ...activeFilters(f), page: 1 });
}

function sortBy(col: string) {
    go({ sort: col, direction: sort.value === col && direction.value === 'desc' ? 'asc' : 'desc', page: 1 });
}
const sortIcon = (col: string) => (sort.value !== col ? 'bi-arrow-down-up text-muted' : direction.value === 'asc' ? 'bi-sort-up' : 'bi-sort-down');

let seq = 0;
async function load() {
    const mine = ++seq;
    loading.value = true;
    error.value = null;
    try {
        const { data } = await api.get<{ data: Row[]; meta: Meta }>('/admin/applications', {
            params: { ...activeFilters(filters.value), sort: sort.value, direction: direction.value, page: page.value, per_page: perPage.value },
        });
        if (mine !== seq) return;
        rows.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        if (mine === seq) error.value = toApiError(e).message;
    } finally {
        if (mine === seq) loading.value = false;
    }
}

const listPath = route.path;
watch(() => route.query, () => { if (route.path === listPath) load(); }, { deep: true });
onMounted(async () => {
    load();
    try {
        ads.value = await loadAdOptions();
        cycles.value = await loadCycleOptions(ads.value);
    } catch { /* filters still usable without option lists */ }
});

// ------------------------------------------------------------ selection and bulk actions
const canAssign = computed(() => auth.can('applications.assign'));
const selected = reactive(new Map<string, Row>());
const allOnPageSelected = computed(() => rows.value.length > 0 && rows.value.every((r) => selected.has(r.uuid)));
function toggle(r: Row) { if (selected.has(r.uuid)) selected.delete(r.uuid); else selected.set(r.uuid, r); }
function togglePage() {
    if (allOnPageSelected.value) rows.value.forEach((r) => selected.delete(r.uuid));
    else rows.value.forEach((r) => selected.set(r.uuid, r));
}
const selectedRows = computed(() => [...selected.values()]);
const selectedAdNumbers = computed(() => [...new Set(selectedRows.value.map((r) => r.advertisement))]);
const selectedAd = computed(() => (selectedAdNumbers.value.length === 1 ? ads.value.find((a) => a.number === selectedAdNumbers.value[0]) ?? null : null));
const unsubmittedSelected = computed(() => selectedRows.value.filter((r) => !r.submitted_at).length);

const bulk = ref<'assign' | 'forward' | null>(null);
const wfLoading = ref(false);
const stages = ref<StageOption[]>([]);
const committees = ref<CommitteeOption[]>([]);
const committeeError = ref<string | null>(null);
const users = ref<UserOption[]>([]);
const assignForm = reactive({ stage_id: '' as string, user_id: '' as string });
const forwardForm = reactive({ committee_id: '' as string, override: false, override_reason: '' });
const form = useForm();

async function openBulk(kind: 'assign' | 'forward') {
    bulk.value = kind;
    form.clear();
    stages.value = []; committees.value = []; committeeError.value = null; users.value = [];
    assignForm.stage_id = ''; assignForm.user_id = '';
    forwardForm.committee_id = ''; forwardForm.override = false; forwardForm.override_reason = '';
    if (!selectedAd.value) return;
    wfLoading.value = true;
    try {
        const wf = await loadWorkflow(selectedAd.value.id);
        stages.value = wf.stages;
        committees.value = wf.committees;
        committeeError.value = wf.committeeError;
        if (stages.value.length) assignForm.stage_id = String(stages.value[0].id);
        if (committees.value.length === 1) forwardForm.committee_id = String(committees.value[0].id);
    } finally { wfLoading.value = false; }
}

const currentStage = computed(() => stages.value.find((s) => String(s.id) === assignForm.stage_id) ?? null);
watch(currentStage, async (s) => {
    users.value = [];
    assignForm.user_id = '';
    if (!s) return;
    try { users.value = await loadUserOptions([s.role]); } catch (e) { form.message.value = toApiError(e).message; }
});

async function submitAssign() {
    const res = await form.run(() => api.post<{ assigned: number }>('/admin/applications/bulk-assign', {
        application_uuids: selectedRows.value.map((r) => r.uuid), user_id: Number(assignForm.user_id), stage_id: Number(assignForm.stage_id),
    }));
    if (res) {
        bulk.value = null;
        selected.clear();
        toast.success(`${res.data.assigned} application(s) assigned for scrutiny.`);
        load();
    }
}

async function submitForward() {
    const payload: Record<string, unknown> = { application_uuids: selectedRows.value.map((r) => r.uuid), committee_id: Number(forwardForm.committee_id) };
    if (forwardForm.override) { payload.override = true; payload.override_reason = forwardForm.override_reason; }
    const res = await form.run(() => api.post<{ forwarded: number }>('/admin/applications/forward', payload));
    if (res) {
        bulk.value = null;
        selected.clear();
        toast.success(`${res.data.forwarded} application(s) forwarded to the Screening Committee.`);
        load();
    }
}
</script>

<template>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h1 class="page-title h4 mb-0">Applications</h1>
        <div class="d-flex align-items-center gap-2 small">
            <label for="per-page" class="text-muted">Per page</label>
            <select id="per-page" class="form-select form-select-sm w-auto" :value="perPage" @change="go({ per_page: ($event.target as HTMLSelectElement).value, page: 1 })">
                <option v-for="n in [10, 25, 50, 100]" :key="n" :value="n">{{ n }}</option>
            </select>
        </div>
    </div>

    <ApplicationFilters :model-value="filters" :ads="ads" :cycles="cycles" id-prefix="al" @apply="applyFilters" />

    <div v-if="canAssign && selected.size" class="alert alert-secondary d-flex flex-wrap align-items-center gap-2 py-2">
        <strong>{{ selected.size }}</strong> selected
        <button class="btn btn-link btn-sm p-0" @click="selected.clear()">Clear selection</button>
        <div class="ms-auto d-flex gap-2">
            <button class="btn btn-sm btn-primary" @click="openBulk('assign')"><i class="bi bi-person-check me-1"></i>Assign for scrutiny</button>
            <button class="btn btn-sm btn-outline-primary" @click="openBulk('forward')"><i class="bi bi-people me-1"></i>Forward to Screening Committee</button>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center small">
            <span>{{ meta.total }} application{{ meta.total === 1 ? '' : 's' }}<template v-if="activeCount"> matching {{ activeCount }} filter{{ activeCount === 1 ? '' : 's' }}</template></span>
            <button class="btn btn-sm btn-outline-secondary" title="Refresh" :disabled="loading" @click="load"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
        <LoadingState :loading="loading && !rows.length" :error="error">
            <div class="table-responsive" :class="{ 'opacity-50': loading }">
                <table class="table table-sm table-cards table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th v-if="canAssign" style="width: 2rem"><input class="form-check-input" type="checkbox" aria-label="Select all on this page" :checked="allOnPageSelected" @change="togglePage"></th>
                            <th class="cursor-pointer text-nowrap" @click="sortBy('reference_no')">Reference <i class="bi" :class="sortIcon('reference_no')"></i></th>
                            <th class="cursor-pointer text-nowrap" @click="sortBy('name')">Candidate <i class="bi" :class="sortIcon('name')"></i></th>
                            <th>Post</th>
                            <th>Cat. / Gender</th>
                            <th class="cursor-pointer text-nowrap" @click="sortBy('submitted_at')">Submitted <i class="bi" :class="sortIcon('submitted_at')"></i></th>
                            <th>Status</th>
                            <th>Scrutiny</th>
                            <th>Fee</th>
                            <th>Referees</th>
                            <th>Docs</th>
                            <th>Outcome</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.uuid" :class="{ 'table-active': selected.has(r.uuid) }">
                            <td v-if="canAssign"><input class="form-check-input" type="checkbox" :aria-label="`Select ${r.reference_no ?? r.name}`" :checked="selected.has(r.uuid)" @change="toggle(r)"></td>
                            <td class="text-nowrap small">
                                <router-link :to="`/admin/applications/${r.uuid}`" class="fw-semibold">{{ r.reference_no ?? 'Draft' }}</router-link>
                            </td>
                            <td>
                                <router-link :to="`/admin/applications/${r.uuid}`" class="text-body">{{ r.name ?? '—' }}</router-link>
                                <div class="small text-muted text-truncate" style="max-width: 14rem">{{ r.email }}</div>
                            </td>
                            <td class="small">
                                <div><span class="fw-semibold">{{ r.post_code }}</span> — {{ r.post }}</div>
                                <div class="text-muted">{{ r.department ?? '' }}<template v-if="r.department"> · </template>{{ r.advertisement }}</div>
                            </td>
                            <td class="small text-nowrap">{{ r.category ?? '—' }} / {{ humanize(r.gender) }}</td>
                            <td class="small text-nowrap">{{ formatDateTime(r.submitted_at) }}</td>
                            <td><StatusBadge :value="r.status" /></td>
                            <td>
                                <StatusBadge v-if="r.scrutiny_status" :value="r.scrutiny_status" /><span v-else class="text-muted">—</span>
                                <div v-if="r.current_stage" class="small text-muted">{{ r.current_stage }}</div>
                            </td>
                            <td><StatusBadge :value="r.fee_status" /></td>
                            <td><StatusBadge :value="r.referee_status" /></td>
                            <td><StatusBadge :value="r.document_status" /></td>
                            <td>
                                <StatusBadge v-if="r.outcome" :value="r.outcome" /><span v-else class="text-muted">—</span>
                                <div v-if="r.committee_status" class="small">Committee: <StatusBadge :value="r.committee_status" /></div>
                            </td>
                        </tr>
                        <tr v-if="!rows.length && !loading"><td :colspan="canAssign ? 12 : 11" class="text-center text-muted py-4">No applications match these filters.</td></tr>
                    </tbody>
                </table>
            </div>
        </LoadingState>
        <div class="card-body py-2">
            <Pager :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="(p) => go({ page: p })" />
        </div>
    </div>

    <BaseModal v-if="bulk" :title="bulk === 'assign' ? 'Assign for scrutiny' : 'Forward to Screening Committee'" @close="bulk = null">
        <p class="small">{{ selected.size }} application(s) selected.</p>
        <div v-if="selectedAdNumbers.length > 1" class="alert alert-warning small">
            The selection spans {{ selectedAdNumbers.length }} advertisements ({{ selectedAdNumbers.join(', ') }}). Scrutiny stages and committees belong to one advertisement — filter by advertisement and select again.
        </div>
        <div v-else-if="!selectedAd" class="alert alert-warning small">The advertisement for this selection could not be identified.</div>
        <div v-if="unsubmittedSelected" class="alert alert-info small">{{ unsubmittedSelected }} selected application(s) have not been submitted and will be rejected by the server.</div>
        <div v-if="form.message.value && Object.keys(form.errors).length" class="alert alert-danger small">{{ form.message.value }}</div>

        <LoadingState :loading="wfLoading">
            <template v-if="bulk === 'assign' && selectedAd">
                <div class="mb-3">
                    <label class="form-label required" for="ba-stage">Scrutiny stage</label>
                    <select id="ba-stage" v-model="assignForm.stage_id" class="form-select" :class="{ 'is-invalid': form.errors.stage_id }">
                        <option value="" disabled>Choose a stage</option>
                        <option v-for="s in stages" :key="s.id" :value="String(s.id)">{{ s.sequence }}. {{ s.name }} ({{ humanize(s.role) }})</option>
                    </select>
                    <div v-if="!stages.length" class="form-text text-danger">No scrutiny stages are visible to you for {{ selectedAd.number }}.</div>
                    <FieldError :errors="form.errors" name="stage_id" />
                </div>
                <div class="mb-2">
                    <label class="form-label required" for="ba-user">Officer</label>
                    <select id="ba-user" v-model="assignForm.user_id" class="form-select" :class="{ 'is-invalid': form.errors.user_id }" :disabled="!currentStage">
                        <option value="" disabled>Choose an officer</option>
                        <option v-for="u in users" :key="u.id" :value="String(u.id)">{{ u.name }}{{ u.designation ? ` — ${u.designation}` : '' }} ({{ u.email }})</option>
                    </select>
                    <div v-if="currentStage" class="form-text">Officers holding the {{ humanize(currentStage.role) }} role.</div>
                    <FieldError :errors="form.errors" name="user_id" />
                    <FieldError :errors="form.errors" name="application_uuids" />
                </div>
            </template>

            <template v-if="bulk === 'forward' && selectedAd">
                <div class="mb-3">
                    <label class="form-label required" for="bf-committee">Screening Committee</label>
                    <select v-if="committees.length" id="bf-committee" v-model="forwardForm.committee_id" class="form-select" :class="{ 'is-invalid': form.errors.committee_id }">
                        <option value="" disabled>Choose a committee</option>
                        <option v-for="c in committees" :key="c.id" :value="String(c.id)">{{ c.name }}{{ c.members?.length ? ` (${c.members.length} members)` : '' }}</option>
                    </select>
                    <template v-else>
                        <input id="bf-committee" v-model.trim="forwardForm.committee_id" class="form-control" inputmode="numeric" placeholder="Committee ID" :class="{ 'is-invalid': form.errors.committee_id }">
                        <div v-if="committeeError" class="form-text text-danger">{{ committeeError }}</div>
                        <div v-else class="form-text">No committees are configured for {{ selectedAd.number }}.</div>
                    </template>
                    <FieldError :errors="form.errors" name="committee_id" />
                    <FieldError :errors="form.errors" name="application_uuids" />
                </div>
                <p class="small text-muted">Applications are forwarded only when scrutiny is complete; the server rejects any that are not ready.</p>
                <template v-if="auth.user?.is_super_admin">
                    <div class="form-check mb-2">
                        <input id="bf-override" v-model="forwardForm.override" class="form-check-input" type="checkbox">
                        <label class="form-check-label small" for="bf-override">Forward without the normal prerequisites (Super Administrator override)</label>
                    </div>
                    <div v-if="forwardForm.override">
                        <label class="form-label small required" for="bf-reason">Reason for override</label>
                        <textarea id="bf-reason" v-model="forwardForm.override_reason" class="form-control" rows="2" maxlength="500" :class="{ 'is-invalid': form.errors.override_reason }"></textarea>
                        <FieldError :errors="form.errors" name="override_reason" />
                    </div>
                </template>
            </template>
        </LoadingState>

        <template #footer>
            <button class="btn btn-secondary" @click="bulk = null">Cancel</button>
            <button v-if="bulk === 'assign'" class="btn btn-primary" :disabled="form.busy.value || !selectedAd || !assignForm.stage_id || !assignForm.user_id" @click="submitAssign">Assign {{ selected.size }}</button>
            <button v-else class="btn btn-primary" :disabled="form.busy.value || !selectedAd || !forwardForm.committee_id || (forwardForm.override && !forwardForm.override_reason.trim())" @click="submitForward">Forward {{ selected.size }}</button>
        </template>
    </BaseModal>
</template>
