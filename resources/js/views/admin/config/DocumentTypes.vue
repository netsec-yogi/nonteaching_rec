<script setup lang="ts">
/** Kinds of document candidates upload: allowed formats, size limit and the wizard step they belong to. */
import { onMounted, reactive, ref } from 'vue';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import BaseModal from '@/components/BaseModal.vue';
import FieldError from '@/components/FieldError.vue';
import LoadingState from '@/components/LoadingState.vue';
import { humanize } from '@/utils/format';
import type { DocumentType } from './parts/types';

const MIMES: Record<string, string> = { 'application/pdf': 'PDF', 'image/jpeg': 'JPEG', 'image/png': 'PNG' };
const form = useForm();
const loading = ref(true);
const error = ref<string | null>(null);
const types = ref<DocumentType[]>([]);
const steps = ref<string[]>([]);
const q = ref('');
const editing = ref<DocumentType | null | undefined>(undefined);
const f = reactive({ code: '', name: '', description: '', allowed_mimes: ['application/pdf'] as string[], max_kb: 2048 as number | string, step: null as string | null, is_active: true });

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/admin/document-types');
        types.value = data.types; steps.value = data.steps;
    } catch (e) { error.value = toApiError(e).message; } finally { loading.value = false; }
}
const defaultKb = ref(2048);
onMounted(async () => {
    load();
    try { defaultKb.value = (await api.get<{ upload_max_kb: number }>('/admin/config-defaults')).data.upload_max_kb; } catch { /* keep built-in value */ }
});

function open(t: DocumentType | null) {
    form.clear();
    Object.assign(f, t
        ? { code: t.code, name: t.name, description: t.description ?? '', allowed_mimes: [...t.allowed_mimes], max_kb: t.max_kb, step: t.step, is_active: t.is_active }
        : { code: '', name: '', description: '', allowed_mimes: ['application/pdf'], max_kb: defaultKb.value, step: null, is_active: true });
    editing.value = t;
}

async function save() {
    const t = editing.value;
    const payload = { ...f, description: f.description || null, max_kb: Number(f.max_kb) };
    const res = await form.run(() => (t ? api.put(`/admin/document-types/${t.id}`, payload) : api.post('/admin/document-types', payload)), 'Document type saved.');
    if (res) { editing.value = undefined; load(); }
}

const size = (kb: number) => (kb >= 1024 ? `${(kb / 1024).toFixed(kb % 1024 ? 1 : 0)} MB` : `${kb} KB`);
const match = (t: DocumentType) => !q.value || `${t.code} ${t.name}`.toLowerCase().includes(q.value.toLowerCase());
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h1 class="page-title h4 mb-0">Document types</h1>
            <input v-model="q" type="search" class="form-control form-control-sm ms-auto" style="max-width: 16rem" placeholder="Search…" aria-label="Search document types">
            <button type="button" class="btn btn-primary btn-sm" @click="open(null)"><i class="bi bi-plus-lg me-1"></i>New document type</button>
        </div>
        <p class="small text-muted">Uploads are checked against these formats and sizes on the server. Document types are referenced by advertisements' document requirements and checklist items; deactivate a type instead of removing it.</p>
        <LoadingState :loading="loading" :error="error">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Name</th><th>Code</th><th>Formats</th><th>Max size</th><th>Wizard step</th><th>Active</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="t in types.filter(match)" :key="t.id" :class="{ 'text-muted': !t.is_active }">
                                <td><div class="fw-semibold small">{{ t.name }}</div><div v-if="t.description" class="small text-muted">{{ t.description }}</div></td>
                                <td class="small font-monospace">{{ t.code }}</td>
                                <td class="small">{{ t.allowed_mimes.map((m) => MIMES[m] ?? m).join(', ') }}</td>
                                <td class="small text-nowrap">{{ size(t.max_kb) }}</td>
                                <td class="small">{{ humanize(t.step) }}</td>
                                <td><span class="badge" :class="t.is_active ? 'text-bg-success' : 'text-bg-secondary'">{{ t.is_active ? 'Active' : 'Inactive' }}</span></td>
                                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary" @click="open(t)"><i class="bi bi-pencil"></i> Edit</button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </LoadingState>

        <BaseModal v-if="editing !== undefined" :title="editing ? 'Edit document type' : 'New document type'" @close="editing = undefined">
            <div class="mb-3">
                <label class="form-label required" for="dt-name">Name</label>
                <input id="dt-name" v-model="f.name" class="form-control" maxlength="150" :class="{ 'is-invalid': form.errors.name }">
                <FieldError :errors="form.errors" name="name" />
            </div>
            <div class="mb-3">
                <label class="form-label required" for="dt-code">Code</label>
                <input id="dt-code" v-model="f.code" class="form-control font-monospace" maxlength="60" placeholder="e.g. noc_certificate" :class="{ 'is-invalid': form.errors.code }">
                <FieldError :errors="form.errors" name="code" />
                <div class="form-text">Lower-case letters, digits and underscores.<span v-if="editing"> Changing the code of a type already in use is not recommended.</span></div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="dt-desc">Description</label>
                <input id="dt-desc" v-model="f.description" class="form-control" maxlength="255" :class="{ 'is-invalid': form.errors.description }">
                <FieldError :errors="form.errors" name="description" />
            </div>
            <div class="mb-3">
                <div class="form-label required">Allowed formats</div>
                <div v-for="(label, mime) in MIMES" :key="mime" class="form-check form-check-inline">
                    <input :id="`dt-m-${label}`" v-model="f.allowed_mimes" class="form-check-input" type="checkbox" :value="mime">
                    <label class="form-check-label" :for="`dt-m-${label}`">{{ label }}</label>
                </div>
                <FieldError :errors="form.errors" name="allowed_mimes" />
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label required" for="dt-kb">Maximum size (KB)</label>
                    <input id="dt-kb" v-model="f.max_kb" type="number" min="20" max="20480" class="form-control" :class="{ 'is-invalid': form.errors.max_kb }">
                    <FieldError :errors="form.errors" name="max_kb" />
                    <div class="form-text">20 KB – 20 MB (20480 KB).</div>
                </div>
                <div class="col-6">
                    <label class="form-label" for="dt-step">Wizard step</label>
                    <select id="dt-step" v-model="f.step" class="form-select" :class="{ 'is-invalid': form.errors.step }">
                        <option :value="null">— None —</option>
                        <option v-for="s in steps" :key="s" :value="s">{{ humanize(s) }}</option>
                    </select>
                    <FieldError :errors="form.errors" name="step" />
                </div>
            </div>
            <div class="form-check">
                <input id="dt-active" v-model="f.is_active" class="form-check-input" type="checkbox">
                <label class="form-check-label" for="dt-active">Active</label>
            </div>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="editing = undefined">Cancel</button>
                <button type="button" class="btn btn-primary" :disabled="form.busy.value" @click="save">Save</button>
            </template>
        </BaseModal>
    </div>
</template>
