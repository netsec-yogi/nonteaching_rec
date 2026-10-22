<script setup lang="ts">
/** Checklist templates used by scrutiny officers (and partly shown to candidates at Step 17). */
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { api, toApiError } from '@/api/client';
import { useToast } from '@/stores/toast';
import LoadingState from '@/components/LoadingState.vue';
import { formatDate } from '@/utils/format';
import type { ChecklistTemplate } from './parts/types';

const router = useRouter();
const toast = useToast();
const loading = ref(true);
const error = ref<string | null>(null);
const templates = ref<ChecklistTemplate[]>([]);
const showArchived = ref(false);
const visible = computed(() => templates.value.filter((t) => showArchived.value || !t.archived_at));

async function load() {
    loading.value = true;
    try { templates.value = (await api.get('/admin/checklists', { params: { include_archived: 1 } })).data.templates; }
    catch (e) { error.value = toApiError(e).message; } finally { loading.value = false; }
}
onMounted(load);

async function copy(t: ChecklistTemplate) {
    const name = prompt('Name for the copy', `${t.name} (copy)`);
    if (!name) return;
    try {
        const { data } = await api.post(`/admin/checklists/${t.id}/copy`, { name });
        toast.success('Checklist copied.');
        router.push(`/admin/checklists/${data.id}`);
    } catch (e) { toast.error(toApiError(e).message); }
}

async function remove(t: ChecklistTemplate) {
    if (!confirm(`Delete “${t.name}”? If it has been used in scrutiny it will be archived instead, so past verdicts keep their meaning.`)) return;
    try {
        const { data } = await api.delete(`/admin/checklists/${t.id}`);
        if (data.archived) toast.show(data.message, 'info', 7000); else toast.success('Checklist deleted.');
        load();
    } catch (e) { toast.error(toApiError(e).message); }
}
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h1 class="page-title h4 mb-0">Checklist templates</h1>
            <div class="form-check form-switch ms-auto mb-0">
                <input id="ck-arch" v-model="showArchived" class="form-check-input" type="checkbox">
                <label class="form-check-label small" for="ck-arch">Show archived</label>
            </div>
            <router-link to="/admin/checklists/new" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New checklist</router-link>
        </div>
        <p class="text-muted small">Scrutiny officers record a verdict against each item. Assign templates to advertisements or posts from the advertisement editor's Checklists tab.</p>

        <LoadingState :loading="loading" :error="error">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Template</th><th class="text-center">Items</th><th>Assigned to</th><th>Updated</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            <tr v-for="t in visible" :key="t.id" :class="{ 'text-muted': t.archived_at }">
                                <td>
                                    <router-link :to="`/admin/checklists/${t.id}`" class="fw-semibold">{{ t.name }}</router-link>
                                    <span v-if="t.archived_at" class="badge text-bg-dark ms-2">Archived</span>
                                    <div class="small text-muted">{{ t.description }}</div>
                                </td>
                                <td class="text-center">{{ t.items_count }}</td>
                                <td class="small">
                                    <div v-for="a in t.assignments" :key="a.id">{{ a.advertisement?.number }}{{ a.post ? ` · ${a.post.code}` : '' }}</div>
                                    <span v-if="!t.assignments?.length" class="text-muted">Not assigned</span>
                                </td>
                                <td class="small text-nowrap">{{ formatDate(t.updated_at) }}</td>
                                <td class="text-end text-nowrap">
                                    <router-link :to="`/admin/checklists/${t.id}`" class="btn btn-sm btn-outline-primary"><i class="bi" :class="t.archived_at ? 'bi-eye' : 'bi-pencil'"></i> {{ t.archived_at ? 'View' : 'Edit' }}</router-link>
                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-1" title="Copy" @click="copy(t)"><i class="bi bi-copy"></i><span class="visually-hidden">Copy</span></button>
                                    <button v-if="!t.archived_at" type="button" class="btn btn-sm btn-outline-danger ms-1" title="Delete" @click="remove(t)"><i class="bi bi-trash"></i><span class="visually-hidden">Delete</span></button>
                                </td>
                            </tr>
                            <tr v-if="!visible.length"><td colspan="5" class="text-center text-muted py-4">No checklist templates.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </LoadingState>
    </div>
</template>
