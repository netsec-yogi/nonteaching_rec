<script setup lang="ts">
/** Committees the member sits on and the applications forwarded to them, with the member's own evaluation status. */
import { onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, toApiError } from '@/api/client';
import LoadingState from '@/components/LoadingState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import Pager from '@/components/Pager.vue';

interface CommitteeRow { id: number; name: string; advertisement: string; post: string | null; mode: string; count: number; is_chair: boolean }
interface AppRow {
    uuid: string; reference_no: string | null; name: string | null; category: string | null; post: string; advertisement: string;
    outcome: string | null; referee_status: string | null; committee_status: string | null; my_evaluation: string | null;
}

const route = useRoute();
const router = useRouter();
const committees = ref<CommitteeRow[]>([]);
const rows = ref<AppRow[]>([]);
const meta = ref({ total: 0, current_page: 1, last_page: 1 });
const committeeId = ref<string>(typeof route.query.committee_id === 'string' ? route.query.committee_id : '');
const loading = ref(true);
const error = ref<string | null>(null);

async function load(page = 1) {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await api.get('/admin/committee/queue', { params: { committee_id: committeeId.value || undefined, page } });
        committees.value = data.committees;
        rows.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        error.value = toApiError(e).message;
    } finally {
        loading.value = false;
    }
}
onMounted(() => load());
watch(committeeId, (v) => {
    router.replace({ query: v ? { committee_id: v } : {} });
    load();
});

const evalLabel = (s: string | null) => (s === 'submitted' ? 'Submitted' : s === 'draft' ? 'Draft saved' : 'Not started');
const evalColor = (s: string | null) => (s === 'submitted' ? 'completed' : s === 'draft' ? 'in_progress' : 'not_started');
</script>

<template>
    <div>
        <h1 class="page-title h4 mb-3">Screening Committee</h1>

        <LoadingState :loading="loading && !committees.length" :error="error">
            <div v-if="!committees.length" class="alert alert-info">You are not a member of any Screening Committee.</div>

            <template v-else>
                <div class="row g-3 mb-3">
                    <div v-for="c in committees" :key="c.id" class="col-md-6 col-xl-4">
                        <div class="card h-100" :class="{ 'border-primary': String(c.id) === committeeId }">
                            <div class="card-body">
                                <div class="d-flex align-items-start gap-2">
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold">{{ c.name }}</div>
                                        <div class="small text-muted">Advt. {{ c.advertisement }}<span v-if="c.post"> · {{ c.post }}</span></div>
                                    </div>
                                    <span v-if="c.is_chair" class="badge text-bg-primary">Chair</span>
                                </div>
                                <div class="small mt-2">
                                    <span class="badge text-bg-light border me-1">{{ c.mode === 'collective' ? 'Collective decision' : 'Individual evaluations' }}</span>
                                    {{ c.count }} application{{ c.count === 1 ? '' : 's' }}
                                </div>
                            </div>
                            <div class="card-footer bg-white d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-primary" @click="committeeId = String(c.id)">Applications</button>
                                <router-link :to="`/committee/comparison/${c.id}`" class="btn btn-sm btn-outline-secondary"><i class="bi bi-table me-1"></i>Comparison report</router-link>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2 mb-2">
                    <h2 class="h6 mb-0 flex-grow-1">Applications</h2>
                    <select v-model="committeeId" class="form-select form-select-sm w-auto" aria-label="Committee">
                        <option value="">All my committees</option>
                        <option v-for="c in committees" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                    </select>
                </div>
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-sm table-cards table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Reference</th><th>Candidate</th><th>Post / Advt.</th><th>Scrutiny</th><th>Referees</th><th>Committee</th><th>My evaluation</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="r in rows" :key="r.uuid">
                                    <td class="small text-nowrap">{{ r.reference_no }}</td>
                                    <td><div class="fw-semibold small">{{ r.name }}</div><div class="small text-muted">{{ r.category }}</div></td>
                                    <td class="small">{{ r.post }}<div class="text-muted">{{ r.advertisement }}</div></td>
                                    <td><StatusBadge :value="r.outcome ?? 'pending'" /></td>
                                    <td><StatusBadge :value="r.referee_status" /></td>
                                    <td><StatusBadge :value="r.committee_status ?? 'pending'" /></td>
                                    <td><StatusBadge :value="evalColor(r.my_evaluation)" :label="evalLabel(r.my_evaluation)" /></td>
                                    <td class="text-end"><router-link :to="`/committee/${r.uuid}`" class="btn btn-sm btn-primary">Open</router-link></td>
                                </tr>
                                <tr v-if="!rows.length"><td colspan="8" class="text-center text-muted small py-4">No applications have been forwarded to {{ committeeId ? 'this committee' : 'your committees' }} yet.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <Pager :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="load" />
            </template>
        </LoadingState>
    </div>
</template>
