<script setup lang="ts">
/** Side-by-side comparison of the applications before a committee. */
import { computed, onMounted, ref, watch } from 'vue';
import { api, toApiError } from '@/api/client';
import LoadingState from '@/components/LoadingState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { monthsToText } from '@/utils/format';

interface Row {
    uuid: string; reference_no: string | null; name: string | null; post: string; category: string | null; is_pwd: boolean;
    highest_qualification: string | null; experience_months: number; scrutiny_outcome: string | null; referee_status: string | null;
    referee_recommendations: string[] | null; evaluations: Record<string, number> | unknown[]; decision: string | null;
}

const props = defineProps<{ id: string | number }>();
const committee = ref<{ id: number; name: string } | null>(null);
const rows = ref<Row[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const sortKey = ref<'reference_no' | 'name' | 'experience_months' | 'decision'>('reference_no');
const sortDir = ref<1 | -1>(1);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await api.get(`/admin/committees/${props.id}/comparison`);
        committee.value = data.committee;
        rows.value = data.rows;
    } catch (e) {
        const ae = toApiError(e);
        error.value = ae.status === 403 ? 'You do not have access to this committee’s comparison report.' : ae.message;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
watch(() => props.id, load);

const evals = (r: Row) => (Array.isArray(r.evaluations) ? [] : Object.entries(r.evaluations as Record<string, number>));
const sorted = computed(() => [...rows.value].sort((a, b) => {
    const x = a[sortKey.value] ?? '';
    const y = b[sortKey.value] ?? '';
    return (x < y ? -1 : x > y ? 1 : 0) * sortDir.value;
}));
function sortBy(k: typeof sortKey.value) {
    if (sortKey.value === k) sortDir.value = sortDir.value === 1 ? -1 : 1;
    else { sortKey.value = k; sortDir.value = 1; }
}
const arrow = (k: string) => (sortKey.value === k ? (sortDir.value === 1 ? 'bi-caret-up-fill' : 'bi-caret-down-fill') : 'bi-chevron-expand text-muted');
const printPage = () => window.print();
const decisionCounts = computed(() => rows.value.reduce<Record<string, number>>((acc, r) => { const k = r.decision ?? 'pending'; acc[k] = (acc[k] ?? 0) + 1; return acc; }, {}));
</script>

<template>
    <div>
        <router-link to="/committee" class="small"><i class="bi bi-arrow-left me-1"></i>Screening Committee</router-link>
        <div class="d-flex flex-wrap align-items-center gap-2 mt-1 mb-3">
            <h1 class="page-title h4 mb-0 flex-grow-1">Comparison report<span v-if="committee"> — {{ committee.name }}</span></h1>
            <button type="button" class="btn btn-sm btn-outline-secondary d-print-none" @click="printPage"><i class="bi bi-printer me-1"></i>Print</button>
        </div>

        <LoadingState :loading="loading" :error="error">
            <div class="d-flex flex-wrap gap-2 small mb-2">
                <span class="text-muted">{{ rows.length }} application{{ rows.length === 1 ? '' : 's' }} ·</span>
                <span v-for="(n, k) in decisionCounts" :key="k"><StatusBadge :value="String(k)" /> {{ n }}</span>
            </div>
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th class="cursor-pointer text-nowrap" @click="sortBy('reference_no')">Reference <i class="bi" :class="arrow('reference_no')"></i></th>
                                <th class="cursor-pointer text-nowrap" @click="sortBy('name')">Candidate <i class="bi" :class="arrow('name')"></i></th>
                                <th>Category</th>
                                <th>Highest qualification</th>
                                <th class="cursor-pointer text-nowrap" @click="sortBy('experience_months')">Experience <i class="bi" :class="arrow('experience_months')"></i></th>
                                <th>Scrutiny</th>
                                <th>Referee recommendations</th>
                                <th>Member evaluations</th>
                                <th class="cursor-pointer text-nowrap" @click="sortBy('decision')">Decision <i class="bi" :class="arrow('decision')"></i></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in sorted" :key="r.uuid">
                                <td class="text-nowrap"><router-link :to="`/committee/${r.uuid}`">{{ r.reference_no }}</router-link></td>
                                <td><div class="fw-semibold">{{ r.name }}</div><div class="text-muted">{{ r.post }}</div></td>
                                <td>{{ r.category ?? '—' }}<span v-if="r.is_pwd" class="badge text-bg-light border ms-1">PwD</span></td>
                                <td>{{ r.highest_qualification ?? '—' }}</td>
                                <td class="text-nowrap">{{ monthsToText(r.experience_months) }}</td>
                                <td><StatusBadge :value="r.scrutiny_outcome ?? 'pending'" /></td>
                                <td>
                                    <StatusBadge :value="r.referee_status" />
                                    <ul v-if="r.referee_recommendations?.length" class="list-unstyled mb-0 mt-1"><li v-for="(x, i) in r.referee_recommendations" :key="i">{{ x }}</li></ul>
                                </td>
                                <td>
                                    <div v-for="[rec, n] in evals(r)" :key="rec">{{ rec }}: <strong>{{ n }}</strong></div>
                                    <span v-if="!evals(r).length" class="text-muted">—</span>
                                </td>
                                <td><StatusBadge :value="r.decision ?? 'pending'" /></td>
                            </tr>
                            <tr v-if="!rows.length"><td colspan="9" class="text-center text-muted py-4">No applications have been forwarded to this committee.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="small text-muted mt-2">Referee recommendations are confidential. This report is for the use of the Screening Committee only.</p>
        </LoadingState>
    </div>
</template>
