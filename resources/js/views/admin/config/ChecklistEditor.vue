<script setup lang="ts">
/** Create or edit a checklist template and its ordered items. Route id "new" creates one. */
import { computed, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import { useToast } from '@/stores/toast';
import FieldError from '@/components/FieldError.vue';
import LoadingState from '@/components/LoadingState.vue';
import { humanize } from '@/utils/format';
import { move, rowErrors, type ChecklistItem, type ChecklistTemplate, type DocumentType } from './parts/types';

const props = defineProps<{ id: string }>();
const router = useRouter();
const toast = useToast();
const form = useForm();
const loading = ref(true);
const error = ref<string | null>(null);
const template = ref<ChecklistTemplate | null>(null);
const sections = ref<string[]>([]);
const types = ref<DocumentType[]>([]);
const f = reactive({ name: '', description: '' });
const items = ref<ChecklistItem[]>([]);
const isNew = computed(() => props.id === 'new');
const archived = computed(() => !!template.value?.archived_at);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const [idx, dt, tpl] = await Promise.all([
            api.get('/admin/checklists'),
            api.get('/admin/document-types'),
            isNew.value ? Promise.resolve(null) : api.get(`/admin/checklists/${props.id}`),
        ]);
        sections.value = idx.data.sections;
        types.value = dt.data.types;
        template.value = tpl ? tpl.data : null;
        f.name = template.value?.name ?? '';
        f.description = template.value?.description ?? '';
        items.value = (template.value?.items ?? []).map((i) => ({
            id: i.id, code: i.code, label: i.label, verification_instructions: i.verification_instructions, section: i.section,
            document_type_id: i.document_type_id, is_mandatory: i.is_mandatory, requires_reason_if_not_verified: i.requires_reason_if_not_verified,
            applicant_visible: i.applicant_visible,
        }));
        if (isNew.value && !items.value.length) addItem();
    } catch (e) { error.value = toApiError(e).message; } finally { loading.value = false; }
}
watch(() => props.id, load, { immediate: true });

function addItem() {
    items.value.push({ code: '', label: '', verification_instructions: null, section: 'other', document_type_id: null,
        is_mandatory: true, requires_reason_if_not_verified: true, applicant_visible: true });
}

function slug(i: ChecklistItem) {
    if (!i.code && i.label) i.code = i.label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 40);
}

async function save() {
    const payload = { name: f.name, description: f.description || null, items: items.value.map((i) => ({ ...i, verification_instructions: i.verification_instructions || null })) };
    const res = await form.run(() => (isNew.value ? api.post('/admin/checklists', payload) : api.put(`/admin/checklists/${props.id}`, payload)), 'Checklist saved.');
    if (res && isNew.value) router.replace(`/admin/checklists/${res.data.id}`);
    else if (res) load();
}

async function copy() {
    const name = prompt('Name for the copy', `${f.name} (copy)`);
    if (!name) return;
    try {
        const { data } = await api.post(`/admin/checklists/${props.id}/copy`, { name });
        toast.success('Checklist copied.');
        router.push(`/admin/checklists/${data.id}`);
    } catch (e) { toast.error(toApiError(e).message); }
}

async function remove() {
    if (!confirm(`Delete “${f.name}”?${template.value?.in_use ? ' It has been used in scrutiny, so it will be archived instead.' : ''}`)) return;
    try {
        const { data } = await api.delete(`/admin/checklists/${props.id}`);
        if (data.archived) toast.show(data.message, 'info', 7000); else toast.success('Checklist deleted.');
        router.push('/admin/checklists');
    } catch (e) { toast.error(toApiError(e).message); }
}
</script>

<template>
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-1">
                <li class="breadcrumb-item"><router-link to="/admin/checklists">Checklists</router-link></li>
                <li class="breadcrumb-item active" aria-current="page">{{ isNew ? 'New' : (template?.name ?? '…') }}</li>
            </ol>
        </nav>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h1 class="page-title h4 mb-0">{{ isNew ? 'New checklist template' : 'Checklist template' }}</h1>
            <span v-if="archived" class="badge text-bg-dark">Archived</span>
            <span v-else-if="template?.in_use" class="badge text-bg-info">In use</span>
            <div v-if="!isNew" class="ms-auto d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="copy"><i class="bi bi-copy me-1"></i>Copy</button>
                <button v-if="!archived" type="button" class="btn btn-sm btn-outline-danger" @click="remove"><i class="bi bi-trash me-1"></i>{{ template?.in_use ? 'Archive' : 'Delete' }}</button>
            </div>
        </div>

        <LoadingState :loading="loading" :error="error">
            <div v-if="archived" class="alert alert-secondary small">This template is archived and read-only. Copy it to make a new editable version.</div>
            <div v-else-if="template?.in_use" class="alert alert-info small">
                <i class="bi bi-info-circle me-1"></i>This checklist has already been used in scrutiny. Removed items are kept in the record for verdicts already given; edits apply to verdicts recorded from now on. Consider copying it for a new advertisement instead.
            </div>
            <div v-if="template?.assignments?.length" class="small text-muted mb-2">
                Assigned to: <span v-for="(a, i) in template.assignments" :key="a.id">{{ i ? '; ' : '' }}<router-link :to="`/admin/advertisements/${a.advertisement_id}?tab=checklists`">{{ a.advertisement?.number }}</router-link>{{ a.post ? ` (${a.post.code})` : '' }}</span>
            </div>

            <fieldset :disabled="archived">
                <div class="card section-card mb-3">
                    <div class="card-body row g-3">
                        <div class="col-md-6">
                            <label class="form-label required" for="ck-name">Name</label>
                            <input id="ck-name" v-model="f.name" class="form-control" maxlength="200" :class="{ 'is-invalid': form.errors.name }">
                            <FieldError :errors="form.errors" name="name" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="ck-desc">Description</label>
                            <input id="ck-desc" v-model="f.description" class="form-control" maxlength="2000" :class="{ 'is-invalid': form.errors.description }">
                            <FieldError :errors="form.errors" name="description" />
                        </div>
                    </div>
                </div>

                <h2 class="h6">Items <span class="text-muted fw-normal">({{ items.length }})</span></h2>
                <FieldError :errors="form.errors" name="items" />
                <div v-for="(it, i) in items" :key="it.id ?? `n${i}`" class="card mb-2">
                    <div class="card-body py-2">
                        <div class="row g-2 align-items-start">
                            <div class="col-auto pt-4 text-muted small fw-semibold">{{ i + 1 }}</div>
                            <div class="col-md-5">
                                <label class="form-label small mb-0 required">Label</label>
                                <input v-model="it.label" class="form-control form-control-sm" maxlength="500" :class="{ 'is-invalid': form.errors[`items.${i}.label`] }" @blur="slug(it)">
                                <FieldError :errors="rowErrors(form.errors, `items.${i}.`)" name="label" />
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small mb-0 required">Code</label>
                                <input v-model="it.code" class="form-control form-control-sm font-monospace" maxlength="60" :class="{ 'is-invalid': form.errors[`items.${i}.code`] }">
                                <FieldError :errors="rowErrors(form.errors, `items.${i}.`)" name="code" />
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small mb-0 required">Section</label>
                                <select v-model="it.section" class="form-select form-select-sm" :class="{ 'is-invalid': form.errors[`items.${i}.section`] }">
                                    <option v-for="s in sections" :key="s" :value="s">{{ humanize(s) }}</option>
                                </select>
                                <FieldError :errors="rowErrors(form.errors, `items.${i}.`)" name="section" />
                            </div>
                            <div class="col">
                                <label class="form-label small mb-0">Document type</label>
                                <select v-model="it.document_type_id" class="form-select form-select-sm" :class="{ 'is-invalid': form.errors[`items.${i}.document_type_id`] }">
                                    <option :value="null">— None —</option>
                                    <option v-for="t in types" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </select>
                            </div>
                            <div class="col-md-7 offset-md-0">
                                <input v-model="it.verification_instructions" class="form-control form-control-sm" maxlength="2000" placeholder="Verification instructions for scrutiny officers (optional)">
                                <FieldError :errors="rowErrors(form.errors, `items.${i}.`)" name="verification_instructions" />
                            </div>
                            <div class="col-md d-flex flex-wrap align-items-center gap-3 small">
                                <div class="form-check mb-0"><input :id="`ci-m-${i}`" v-model="it.is_mandatory" class="form-check-input" type="checkbox"><label class="form-check-label" :for="`ci-m-${i}`">Mandatory</label></div>
                                <div class="form-check mb-0"><input :id="`ci-r-${i}`" v-model="it.requires_reason_if_not_verified" class="form-check-input" type="checkbox"><label class="form-check-label" :for="`ci-r-${i}`">Reason if not verified</label></div>
                                <div class="form-check mb-0" title="Appears on the candidate's Step 17 checklist"><input :id="`ci-a-${i}`" v-model="it.applicant_visible" class="form-check-input" type="checkbox"><label class="form-check-label" :for="`ci-a-${i}`">Applicant visible (Step 17)</label></div>
                                <div v-if="!archived" class="ms-auto text-nowrap">
                                    <button type="button" class="btn btn-sm btn-link p-0 me-1" :disabled="i === 0" title="Move up" @click="move(items, i, -1)"><i class="bi bi-arrow-up"></i></button>
                                    <button type="button" class="btn btn-sm btn-link p-0 me-1" :disabled="i === items.length - 1" title="Move down" @click="move(items, i, 1)"><i class="bi bi-arrow-down"></i></button>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0" title="Remove" @click="items.splice(i, 1)"><i class="bi bi-trash"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <button v-if="!archived" type="button" class="btn btn-sm btn-outline-primary mb-3" @click="addItem"><i class="bi bi-plus"></i> Add item</button>
            </fieldset>

            <div v-if="!archived" class="sticky-actions d-flex align-items-center gap-2">
                <span v-if="form.message.value && Object.keys(form.errors).length" class="text-danger small">Please correct the highlighted fields.</span>
                <router-link to="/admin/checklists" class="btn btn-outline-secondary ms-auto">Cancel</router-link>
                <button type="button" class="btn btn-primary" :disabled="form.busy.value" @click="save">{{ isNew ? 'Create checklist' : 'Save checklist' }}</button>
            </div>
        </LoadingState>
    </div>
</template>
