<script setup lang="ts">
/** The scrutiny officer's own assignments: open (assigned / in progress) and submitted. */
import { onMounted, ref, watch } from 'vue';
import { api, toApiError } from '@/api/client';
import LoadingState from '@/components/LoadingState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import Pager from '@/components/Pager.vue';
import { formatDateTime } from '@/utils/format';

interface Row {
    assignment_id: number; status: string; stage: string; is_decision_stage: boolean; assigned_at: string | null;
    uuid: string; reference_no: string | null; name: string | null; category: string | null; post: string; advertisement: string;
    scrutiny_status: string | null; fee_status: string | null; referee_status: string | null;
}

const status = ref<'open' | 'submitted'>('open');
const rows = ref<Row[]>([]);
const meta = ref({ total: 0, current_page: 1, last_page: 1 });
const loading = ref(true);
const error = ref<string | null>(null);
const search = ref('');

async function load(page = 1) {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await api.get('/admin/scrutiny/queue', { params: { status: status.value, page } });
        rows.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        error.value = toApiError(e).message;
    } finally {
        loading.value = false;
    }
}
onMounted(() => load());
watch(status, () => load());

const matches = (r: Row) => {
    const q = search.value.trim().toLowerCase();
    return !q || [r.reference_no, r.name, r.post, r.advertisement].some((v) => (v ?? '').toLowerCase().includes(q));
};
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h1 class="page-title h4 mb-0 flex-grow-1">Scrutiny queue</h1>
            <input v-model="search" type="search" class="form-control form-control-sm w-auto" placeholder="Filter this page…" aria-label="Filter">
        </div>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item"><button type="button" class="nav-link" :class="{ active: status === 'open' }" @click="status = 'open'"><i class="bi bi-inbox me-1"></i>Open</button></li>
            <li class="nav-item"><button type="button" class="nav-link" :class="{ active: status === 'submitted' }" @click="status = 'submitted'"><i class="bi bi-check2-square me-1"></i>Submitted</button></li>
        </ul>

        <LoadingState :loading="loading" :error="error">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm table-cards table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>Reference</th><th>Candidate</th><th>Post / Advt.</th><th>Stage</th><th>Status</th><th>Fee / Referees</th><th>Assigned</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in rows.filter(matches)" :key="r.assignment_id">
                                <td class="text-nowrap small">{{ r.reference_no ?? '—' }}</td>
                                <td><div class="fw-semibold small">{{ r.name ?? '—' }}</div><div class="small text-muted">{{ r.category }}</div></td>
                                <td class="small">{{ r.post }}<div class="text-muted">{{ r.advertisement }}</div></td>
                                <td class="small">{{ r.stage }}<span v-if="r.is_decision_stage" class="badge text-bg-light border ms-1">decision</span></td>
                                <td><StatusBadge :value="r.status" /><div class="small"><StatusBadge :value="r.scrutiny_status" /></div></td>
                                <td><StatusBadge :value="r.fee_status" /> <StatusBadge :value="r.referee_status" /></td>
                                <td class="small text-nowrap">{{ formatDateTime(r.assigned_at) }}</td>
                                <td class="text-end">
                                    <router-link :to="`/scrutiny/${r.uuid}`" class="btn btn-sm" :class="status === 'open' ? 'btn-primary' : 'btn-outline-secondary'">
                                        {{ status === 'open' ? 'Scrutinise' : 'View' }}
                                    </router-link>
                                </td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td colspan="8" class="text-center text-muted py-4 small">
                                    {{ status === 'open' ? 'No applications are assigned to you for scrutiny.' : 'You have not submitted any stage reports yet.' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <Pager :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="load" />
        </LoadingState>
    </div>
</template>
