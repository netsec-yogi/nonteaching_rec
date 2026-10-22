<script setup lang="ts">
/** All advertisements with their posts and application counts. */
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, toApiError } from '@/api/client';
import { useToast } from '@/stores/toast';
import LoadingState from '@/components/LoadingState.vue';
import Pager from '@/components/Pager.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { formatDateTime } from '@/utils/format';
import type { Cycle } from './parts/types';

interface Row {
    id: number; number: string; title: string; status: string; opens_at: string; closes_at: string;
    cycle: { id: number; name: string } | null; posts_count: number; submitted_count: number; draft_count: number;
}

const router = useRouter();
const route = useRoute();
const toast = useToast();
const loading = ref(true);
const error = ref<string | null>(null);
const rows = ref<Row[]>([]);
const cycles = ref<Cycle[]>([]);
const meta = reactive({ page: 1, lastPage: 1, total: 0 });
const filters = reactive({ status: '', cycle_id: (route.query.cycle_id as string | undefined) ?? '' as string | number });
const statuses = ['draft', 'published', 'suspended', 'closed', 'archived'];

async function load(page = 1) {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await api.get('/admin/advertisements', { params: { page, status: filters.status || undefined, cycle_id: filters.cycle_id || undefined } });
        rows.value = data.data;
        Object.assign(meta, { page: data.current_page, lastPage: data.last_page, total: data.total });
    } catch (e) { error.value = toApiError(e).message; } finally { loading.value = false; }
}

async function remove(r: Row) {
    if (!confirm(`Delete draft advertisement ${r.number}? This cannot be undone.`)) return;
    try {
        await api.delete(`/admin/advertisements/${r.id}`);
        toast.success('Advertisement deleted.');
        load(meta.page);
    } catch (e) { toast.error(toApiError(e).message); }
}

onMounted(async () => {
    load();
    try { cycles.value = (await api.get('/admin/cycles')).data; } catch { /* filter is optional */ }
});
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h1 class="page-title h4 mb-0">Advertisements</h1>
            <router-link to="/admin/advertisements/new" class="btn btn-primary btn-sm ms-auto"><i class="bi bi-plus-lg me-1"></i>New advertisement</router-link>
        </div>

        <div class="card mb-3">
            <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-end">
                <div>
                    <label class="form-label small mb-0" for="f-status">Status</label>
                    <select id="f-status" v-model="filters.status" class="form-select form-select-sm" @change="load()">
                        <option value="">All</option>
                        <option v-for="s in statuses" :key="s" :value="s">{{ s.charAt(0).toUpperCase() + s.slice(1) }}</option>
                    </select>
                </div>
                <div>
                    <label class="form-label small mb-0" for="f-cycle">Recruitment cycle</label>
                    <select id="f-cycle" v-model="filters.cycle_id" class="form-select form-select-sm" @change="load()">
                        <option value="">All cycles</option>
                        <option v-for="c in cycles" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                    </select>
                </div>
                <router-link to="/admin/cycles" class="btn btn-sm btn-link ms-auto">Manage cycles</router-link>
            </div>
        </div>

        <LoadingState :loading="loading" :error="error">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm table-cards table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>Advertisement</th><th>Cycle</th><th>Opens</th><th>Closes</th><th class="text-center">Posts</th><th class="text-center">Submitted</th><th class="text-center">Drafts</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in rows" :key="r.id" class="cursor-pointer" @click="router.push(`/admin/advertisements/${r.id}`)">
                                <td><div class="fw-semibold">{{ r.number }}</div><div class="small text-muted">{{ r.title }}</div></td>
                                <td class="small">{{ r.cycle?.name ?? '—' }}</td>
                                <td class="small text-nowrap">{{ formatDateTime(r.opens_at) }}</td>
                                <td class="small text-nowrap">{{ formatDateTime(r.closes_at) }}</td>
                                <td class="text-center">{{ r.posts_count }}</td>
                                <td class="text-center">{{ r.submitted_count }}</td>
                                <td class="text-center">{{ r.draft_count }}</td>
                                <td><StatusBadge :value="r.status" /></td>
                                <td class="text-end text-nowrap" @click.stop>
                                    <router-link :to="`/admin/advertisements/${r.id}`" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Edit</router-link>
                                    <button v-if="r.status === 'draft' && !r.submitted_count && !r.draft_count" type="button" class="btn btn-sm btn-outline-danger ms-1" @click="remove(r)">
                                        <i class="bi bi-trash"></i><span class="visually-hidden">Delete</span>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!rows.length"><td colspan="9" class="text-center text-muted py-4">No advertisements match.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <Pager :page="meta.page" :last-page="meta.lastPage" :total="meta.total" @change="load" />
        </LoadingState>
    </div>
</template>
