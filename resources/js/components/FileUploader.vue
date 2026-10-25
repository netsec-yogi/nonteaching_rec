<script setup lang="ts">
/**
 * Upload slot for one document type on an application, optionally attached to
 * a repeatable row (qualification, employment, …). Shows existing uploads,
 * progress, and validation errors returned by the server.
 */
import { computed, ref } from 'vue';
import { api, openInline, toApiError } from '@/api/client';
import { formatSize } from '@/utils/format';

export interface DocumentSummary {
    uuid: string; id: number; document_type: string | null; document_type_name: string | null; label: string | null;
    original_name: string; mime: string; size: number; documentable_type: string | null; documentable_id: number | null;
    verification_status: string; is_frozen: boolean; uploaded_at: string | null;
}
export interface DocumentTypeInfo { code: string; name: string; description?: string | null; allowed_mimes: string[]; max_kb: number; step: string | null }

const props = withDefaults(defineProps<{
    applicationUuid: string;
    type: DocumentTypeInfo;
    documents: DocumentSummary[];
    documentableType?: string | null;
    documentableId?: number | null;
    required?: boolean;
    single?: boolean;
    disabled?: boolean;
    label?: string;
    instructions?: string | null;
}>(), { documentableType: null, documentableId: null, required: false, single: false, disabled: false });

const emit = defineEmits<{ (e: 'changed', docs: DocumentSummary[]): void }>();

const mine = computed(() => props.documents.filter((d) => d.document_type === props.type.code
    && (props.documentableType ? d.documentable_type === props.documentableType && d.documentable_id === props.documentableId : !d.documentable_type)));
const progress = ref<number | null>(null);
const error = ref<string | null>(null);
const input = ref<HTMLInputElement | null>(null);
const accept = computed(() => props.type.allowed_mimes.join(','));
const kinds = computed(() => props.type.allowed_mimes.map((m) => ({ 'application/pdf': 'PDF', 'image/jpeg': 'JPEG', 'image/png': 'PNG' }[m] ?? m)).join(' / '));
const maxLabel = computed(() => props.type.max_kb >= 1024 ? `${(props.type.max_kb / 1024).toFixed(0)} MB` : `${props.type.max_kb} KB`);

async function upload(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    error.value = null;
    if (file.size > props.type.max_kb * 1024) {
        error.value = `The file is larger than ${maxLabel.value}.`;
        if (input.value) input.value.value = '';
        return;
    }
    const form = new FormData();
    form.append('file', file);
    form.append('document_type', props.type.code);
    if (props.documentableType && props.documentableId) {
        form.append('documentable_type', props.documentableType);
        form.append('documentable_id', String(props.documentableId));
    }
    if (props.label) form.append('label', props.label);
    const replaced = props.single ? mine.value.find((d) => !d.is_frozen) : undefined;
    if (replaced) form.append('replace_uuid', replaced.uuid);
    try {
        progress.value = 0;
        const { data } = await api.post(`/applications/${props.applicationUuid}/documents`, form, {
            onUploadProgress: (p) => { progress.value = p.total ? Math.round((p.loaded / p.total) * 100) : null; },
        });
        const others = props.documents.filter((d) => d.uuid !== replaced?.uuid);
        emit('changed', [...others, data]);
    } catch (err) {
        const ae = toApiError(err);
        error.value = ae.errors.file?.[0] ?? ae.errors.document_type?.[0] ?? ae.message;
    } finally {
        progress.value = null;
        if (input.value) input.value.value = '';
    }
}

async function remove(doc: DocumentSummary) {
    if (!confirm(`Remove ${doc.original_name}?`)) return;
    try {
        await api.delete(`/applications/${props.applicationUuid}/documents/${doc.uuid}`);
        emit('changed', props.documents.filter((d) => d.uuid !== doc.uuid));
    } catch (err) {
        error.value = toApiError(err).message;
    }
}

function view(doc: DocumentSummary) {
    openInline(`/applications/${props.applicationUuid}/documents/${doc.uuid}`).catch((e) => { error.value = toApiError(e).message; });
}
</script>

<template>
    <div class="upload-row py-2">
        <div class="d-flex flex-wrap align-items-start gap-2">
            <div class="flex-grow-1">
                <div class="fw-semibold small" :class="{ 'form-label required mb-0': required }">{{ label ?? type.name }}</div>
                <div class="text-muted small">{{ kinds }}, up to {{ maxLabel }}<span v-if="instructions"> · {{ instructions }}</span></div>
            </div>
            <div v-if="!disabled">
                <label class="btn btn-sm btn-outline-primary mb-0" :class="{ disabled: progress !== null }">
                    <i class="bi bi-upload me-1"></i>{{ single && mine.length ? 'Replace' : 'Upload' }}
                    <input ref="input" type="file" class="d-none" :accept="accept" @change="upload">
                </label>
            </div>
        </div>
        <div v-if="progress !== null" class="progress mt-2" style="height: 6px" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" aria-label="Upload progress">
            <div class="progress-bar" :style="{ width: `${progress}%` }"></div>
        </div>
        <div v-if="error" class="text-danger small mt-1"><i class="bi bi-exclamation-circle me-1"></i>{{ error }}</div>
        <ul v-if="mine.length" class="list-unstyled mb-0 mt-2">
            <li v-for="d in mine" :key="d.uuid" class="d-flex align-items-center gap-2 small py-1">
                <i class="bi" :class="d.mime === 'application/pdf' ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image text-info'"></i>
                <button type="button" class="btn btn-link btn-sm p-0 text-truncate" style="max-width: 18rem" @click="view(d)">{{ d.original_name }}</button>
                <span class="text-muted">{{ formatSize(d.size) }}</span>
                <span v-if="d.is_frozen" class="badge text-bg-light border" title="Part of a submitted version">submitted</span>
                <button v-else-if="!disabled" type="button" class="btn btn-link btn-sm text-danger p-0 ms-auto" @click="remove(d)"><i class="bi bi-trash"></i><span class="visually-hidden">Remove</span></button>
            </li>
        </ul>
    </div>
</template>
