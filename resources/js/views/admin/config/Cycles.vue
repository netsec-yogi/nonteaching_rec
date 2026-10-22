<script setup lang="ts">
/** Recruitment cycles group advertisements for reporting. */
import { onMounted, reactive, ref } from 'vue';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import { useToast } from '@/stores/toast';
import BaseModal from '@/components/BaseModal.vue';
import FieldError from '@/components/FieldError.vue';
import LoadingState from '@/components/LoadingState.vue';
import type { Cycle } from './parts/types';

const toast = useToast();
const form = useForm();
const loading = ref(true);
const error = ref<string | null>(null);
const cycles = ref<Cycle[]>([]);
const editing = ref<Cycle | null | undefined>(undefined);
const f = reactive({ name: '', year: '', description: '', is_active: true });

async function load() {
    loading.value = true;
    try { cycles.value = (await api.get('/admin/cycles')).data; } catch (e) { error.value = toApiError(e).message; } finally { loading.value = false; }
}
onMounted(load);

function open(c: Cycle | null) {
    form.clear();
    Object.assign(f, { name: c?.name ?? '', year: c?.year ?? '', description: c?.description ?? '', is_active: c?.is_active ?? true });
    editing.value = c;
}

async function save() {
    const c = editing.value;
    const payload = { ...f, description: f.description || null };
    const res = await form.run(() => (c ? api.put(`/admin/cycles/${c.id}`, payload) : api.post('/admin/cycles', payload)), 'Recruitment cycle saved.');
    if (res) { editing.value = undefined; load(); }
}

async function remove(c: Cycle) {
    if (!confirm(`Delete the cycle “${c.name}”?`)) return;
    try { await api.delete(`/admin/cycles/${c.id}`); toast.success('Cycle deleted.'); load(); } catch (e) { toast.error(toApiError(e).message); }
}
</script>

<template>
    <div>
        <div class="d-flex align-items-center mb-3">
            <h1 class="page-title h4 mb-0">Recruitment cycles</h1>
            <button type="button" class="btn btn-primary btn-sm ms-auto" @click="open(null)"><i class="bi bi-plus-lg me-1"></i>New cycle</button>
        </div>
        <LoadingState :loading="loading" :error="error">
            <div class="card">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Name</th><th>Year</th><th>Description</th><th class="text-center">Advertisements</th><th>Active</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                        <tr v-for="c in cycles" :key="c.id">
                            <td class="fw-semibold">{{ c.name }}</td>
                            <td>{{ c.year }}</td>
                            <td class="small text-muted">{{ c.description }}</td>
                            <td class="text-center"><router-link :to="{ path: '/admin/advertisements', query: { cycle_id: c.id } }">{{ c.advertisements_count ?? 0 }}</router-link></td>
                            <td><span class="badge" :class="c.is_active ? 'text-bg-success' : 'text-bg-secondary'">{{ c.is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary" @click="open(c)"><i class="bi bi-pencil"></i> Edit</button>
                                <button type="button" class="btn btn-sm btn-outline-danger ms-1" :disabled="!!c.advertisements_count" :title="c.advertisements_count ? 'Cycles with advertisements cannot be deleted' : 'Delete'" @click="remove(c)"><i class="bi bi-trash"></i><span class="visually-hidden">Delete</span></button>
                            </td>
                        </tr>
                        <tr v-if="!cycles.length"><td colspan="6" class="text-center text-muted py-4">No recruitment cycles yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </LoadingState>

        <BaseModal v-if="editing !== undefined" :title="editing ? 'Edit recruitment cycle' : 'New recruitment cycle'" @close="editing = undefined">
            <div class="mb-3">
                <label class="form-label required" for="cy-name">Name</label>
                <input id="cy-name" v-model="f.name" class="form-control" maxlength="150" placeholder="Non-Teaching Recruitment 2027-28" :class="{ 'is-invalid': form.errors.name }">
                <FieldError :errors="form.errors" name="name" />
            </div>
            <div class="mb-3">
                <label class="form-label required" for="cy-year">Year</label>
                <input id="cy-year" v-model="f.year" class="form-control" maxlength="20" placeholder="2027-28" :class="{ 'is-invalid': form.errors.year }">
                <FieldError :errors="form.errors" name="year" />
            </div>
            <div class="mb-3">
                <label class="form-label" for="cy-desc">Description</label>
                <textarea id="cy-desc" v-model="f.description" rows="3" class="form-control" maxlength="2000" :class="{ 'is-invalid': form.errors.description }"></textarea>
                <FieldError :errors="form.errors" name="description" />
            </div>
            <div class="form-check">
                <input id="cy-active" v-model="f.is_active" class="form-check-input" type="checkbox">
                <label class="form-check-label" for="cy-active">Active (offered when creating advertisements)</label>
            </div>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="editing = undefined">Cancel</button>
                <button type="button" class="btn btn-primary" :disabled="form.busy.value" @click="save">Save</button>
            </template>
        </BaseModal>
    </div>
</template>
