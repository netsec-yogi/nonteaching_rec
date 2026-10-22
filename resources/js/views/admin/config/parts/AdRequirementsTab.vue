<script setup lang="ts">
/** Tab 5: which documents candidates must upload, and under which condition. */
import { computed, onMounted, reactive, ref } from 'vue';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import { useToast } from '@/stores/toast';
import BaseModal from '@/components/BaseModal.vue';
import FieldError from '@/components/FieldError.vue';
import LoadingState from '@/components/LoadingState.vue';
import { humanize } from '@/utils/format';
import type { AdvertisementDetail, DocumentType, Requirement } from './types';

const props = defineProps<{ ad: AdvertisementDetail }>();
const toast = useToast();
const form = useForm();
const loading = ref(true);
const error = ref<string | null>(null);
const requirements = ref<Requirement[]>([]);
const conditions = ref<Record<string, string>>({});
const types = ref<DocumentType[]>([]);
const editing = ref<Requirement | null | undefined>(undefined);
const f = reactive({ document_type_id: null as number | null, post_id: null as number | null, condition: 'always', is_mandatory: true, instructions: '' });
const readOnly = computed(() => props.ad.status === 'archived');
const base = computed(() => `/admin/advertisements/${props.ad.id}/requirements`);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const [r, t] = await Promise.all([api.get(base.value), api.get('/admin/document-types')]);
        requirements.value = r.data.requirements;
        conditions.value = r.data.conditions;
        types.value = t.data.types;
    } catch (e) { error.value = toApiError(e).message; } finally { loading.value = false; }
}
onMounted(load);

function open(r: Requirement | null) {
    form.clear();
    Object.assign(f, r
        ? { document_type_id: r.document_type_id, post_id: r.post_id, condition: r.condition, is_mandatory: r.is_mandatory, instructions: r.instructions ?? '' }
        : { document_type_id: null, post_id: null, condition: 'always', is_mandatory: true, instructions: '' });
    editing.value = r;
}

async function save() {
    const payload = { ...f, instructions: f.instructions || null };
    const r = editing.value;
    const res = await form.run(() => (r ? api.put(`${base.value}/${r.id}`, payload) : api.post(base.value, payload)), 'Document requirement saved.');
    if (res) { editing.value = undefined; load(); }
}

async function remove(r: Requirement) {
    if (!confirm(`Remove the requirement for “${r.document_type?.name}”?`)) return;
    try {
        await api.delete(`${base.value}/${r.id}`);
        toast.success('Requirement removed.');
        load();
    } catch (e) { toast.error(toApiError(e).message); }
}
</script>

<template>
    <LoadingState :loading="loading" :error="error">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <p class="text-muted small mb-0">Candidates see an upload slot for each requirement that applies to them. Conditions are evaluated from the candidate's own answers.</p>
            <button v-if="!readOnly" type="button" class="btn btn-sm btn-primary" @click="open(null)"><i class="bi bi-plus-lg me-1"></i>Add requirement</button>
        </div>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Document</th><th>Applies to</th><th>Condition</th><th>Mandatory</th><th>Instructions</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                        <tr v-for="r in requirements" :key="r.id">
                            <td>
                                <div class="fw-semibold small">{{ r.document_type?.name }}</div>
                                <div class="small text-muted">{{ humanize(r.document_type?.step) }} step</div>
                            </td>
                            <td class="small">{{ r.post ? `${r.post.code}${r.post.name ? ' — ' + r.post.name : ''}` : 'All posts' }}</td>
                            <td class="small">{{ conditions[r.condition] ?? humanize(r.condition) }}</td>
                            <td><span class="badge" :class="r.is_mandatory ? 'text-bg-primary' : 'text-bg-light border'">{{ r.is_mandatory ? 'Mandatory' : 'Optional' }}</span></td>
                            <td class="small text-muted">{{ r.instructions }}</td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary" :disabled="readOnly" @click="open(r)"><i class="bi bi-pencil"></i><span class="visually-hidden">Edit</span></button>
                                <button type="button" class="btn btn-sm btn-outline-danger ms-1" :disabled="readOnly" @click="remove(r)"><i class="bi bi-trash"></i><span class="visually-hidden">Delete</span></button>
                            </td>
                        </tr>
                        <tr v-if="!requirements.length"><td colspan="6" class="text-center text-muted py-4">No document requirements configured.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <BaseModal v-if="editing !== undefined" :title="editing ? 'Edit document requirement' : 'Add document requirement'" @close="editing = undefined">
            <div class="mb-3">
                <label class="form-label required" for="rq-type">Document type</label>
                <select id="rq-type" v-model="f.document_type_id" class="form-select" :class="{ 'is-invalid': form.errors.document_type_id }">
                    <option :value="null" disabled>Choose…</option>
                    <option v-for="t in types.filter((x) => x.is_active || x.id === f.document_type_id)" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
                <FieldError :errors="form.errors" name="document_type_id" />
            </div>
            <div class="mb-3">
                <label class="form-label" for="rq-post">Post</label>
                <select id="rq-post" v-model="f.post_id" class="form-select" :class="{ 'is-invalid': form.errors.post_id }">
                    <option :value="null">All posts</option>
                    <option v-for="p in ad.posts" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                </select>
                <FieldError :errors="form.errors" name="post_id" />
            </div>
            <div class="mb-3">
                <label class="form-label required" for="rq-cond">Required when</label>
                <select id="rq-cond" v-model="f.condition" class="form-select" :class="{ 'is-invalid': form.errors.condition }">
                    <option v-for="(label, key) in conditions" :key="key" :value="key">{{ label }}</option>
                </select>
                <FieldError :errors="form.errors" name="condition" />
            </div>
            <div class="form-check mb-3">
                <input id="rq-mand" v-model="f.is_mandatory" class="form-check-input" type="checkbox">
                <label class="form-check-label" for="rq-mand">Mandatory — the application cannot be submitted without it</label>
            </div>
            <div>
                <label class="form-label" for="rq-instr">Instructions to candidates</label>
                <input id="rq-instr" v-model="f.instructions" class="form-control" maxlength="500" :class="{ 'is-invalid': form.errors.instructions }">
                <FieldError :errors="form.errors" name="instructions" />
            </div>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="editing = undefined">Cancel</button>
                <button type="button" class="btn btn-primary" :disabled="form.busy.value" @click="save">Save</button>
            </template>
        </BaseModal>
    </LoadingState>
</template>
