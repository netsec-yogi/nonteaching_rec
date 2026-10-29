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
    upload_type?: 'original' | 'undertaking'; original_status?: string | null;
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
    /** The requirement accepts a signed undertaking in place of the document; shows the choice. */
    undertaking?: boolean;
    undertakingText?: string;
    originalDueStage?: string;
    /** Show a thumbnail of the uploaded image (photograph, signature). */
    preview?: boolean;
}>(), { documentableType: null, documentableId: null, required: false, single: false, disabled: false, undertaking: false, undertakingText: '', originalDueStage: '', preview: false });

const emit = defineEmits<{ (e: 'changed', docs: DocumentSummary[]): void }>();

const mine = computed(() => props.documents.filter((d) => d.document_type === props.type.code
    && (props.documentableType ? d.documentable_type === props.documentableType && d.documentable_id === props.documentableId : !d.documentable_type)));
const mode = ref<'original' | 'undertaking'>('original');
const undertakings = computed(() => mine.value.filter((d) => d.upload_type === 'undertaking'));
const originals = computed(() => mine.value.filter((d) => d.upload_type !== 'undertaking'));
const UNDERTAKING_MIMES = ['application/pdf', 'image/jpeg', 'image/png'];
const progress = ref<number | null>(null);
const error = ref<string | null>(null);
const input = ref<HTMLInputElement | null>(null);
const mimes = computed(() => (mode.value === 'undertaking' ? UNDERTAKING_MIMES : props.type.allowed_mimes));
const accept = computed(() => mimes.value.join(','));
const kinds = computed(() => mimes.value.map((m) => ({ 'application/pdf': 'PDF', 'image/jpeg': 'JPEG', 'image/png': 'PNG' }[m] ?? m)).join(' / '));
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
    form.append('upload_type', mode.value);
    const replaced = props.single && mode.value === 'original' ? originals.value.find((d) => !d.is_frozen) : undefined;
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
        error.value = ae.errors.file?.[0] ?? ae.errors.upload_type?.[0] ?? ae.errors.document_type?.[0] ?? ae.message;
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

const previewUrl = (doc: DocumentSummary) => `/api/applications/${props.applicationUuid}/documents/${doc.uuid}?inline=1`;
const radioName = computed(() => `upl-${props.type.code}-${props.documentableType ?? 'app'}-${props.documentableId ?? 0}`);

function view(doc: DocumentSummary) {
    openInline(`/applications/${props.applicationUuid}/documents/${doc.uuid}`).catch((e) => { error.value = toApiError(e).message; });
}
</script>

<template>
    <div class="upload-row py-2">
        <div class="d-flex flex-wrap align-items-start gap-2">
            <div class="flex-grow-1">
                <div class="fw-semibold small" :class="{ 'form-label required mb-0': required }">{{ label ?? type.name }}
                    <span class="badge ms-1" :class="required ? 'text-bg-primary' : 'text-bg-light border'">{{ required ? 'Mandatory' : 'Optional' }}</span>
                </div>
                <div class="text-muted small">{{ kinds }}, up to {{ maxLabel }}<span v-if="instructions && mode === 'original'"> · {{ instructions }}</span></div>
            </div>
            <div v-if="!disabled">
                <label class="btn btn-sm mb-0" :class="[mode === 'undertaking' ? 'btn-outline-warning' : 'btn-outline-primary', { disabled: progress !== null }]">
                    <i class="bi bi-upload me-1"></i>{{ mode === 'undertaking' ? 'Upload undertaking' : single && originals.length ? 'Replace' : 'Upload' }}
                    <input ref="input" type="file" class="d-none" :accept="accept" @change="upload">
                </label>
            </div>
        </div>
        <fieldset v-if="undertaking && !disabled" class="mt-2">
            <legend class="visually-hidden">How you are providing {{ label ?? type.name }}</legend>
            <div class="form-check form-check-inline">
                <input :id="`${radioName}-o`" v-model="mode" class="form-check-input" type="radio" :name="radioName" value="original">
                <label class="form-check-label small" :for="`${radioName}-o`">Upload Original Document</label>
            </div>
            <div class="form-check form-check-inline">
                <input :id="`${radioName}-u`" v-model="mode" class="form-check-input" type="radio" :name="radioName" value="undertaking">
                <label class="form-check-label small" :for="`${radioName}-u`">Upload Signed Undertaking Instead</label>
            </div>
        </fieldset>
        <div v-if="mode === 'undertaking'" class="alert alert-warning small py-2 mt-2 mb-0">
            Upload a document signed by you that states: <strong>"{{ undertakingText }}"</strong>
            The original remains outstanding and must be produced {{ originalDueStage.toLowerCase() }}; it is not treated as uploaded or verified.
        </div>
        <div v-if="progress !== null" class="progress mt-2" style="height: 6px" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" aria-label="Upload progress">
            <div class="progress-bar" :style="{ width: `${progress}%` }"></div>
        </div>
        <div v-if="error" class="text-danger small mt-1" role="alert"><i class="bi bi-exclamation-circle me-1"></i>{{ error }}</div>
        <div v-if="preview && originals.length && originals[originals.length - 1].mime.startsWith('image/')" class="upload-preview mt-2">
            <img :src="previewUrl(originals[originals.length - 1])" :alt="`Uploaded ${label ?? type.name}`">
        </div>
        <ul v-if="mine.length" class="list-unstyled mb-0 mt-2">
            <li v-for="d in mine" :key="d.uuid" class="d-flex flex-wrap align-items-center gap-2 small py-1">
                <i class="bi" :class="d.mime === 'application/pdf' ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image text-info'" aria-hidden="true"></i>
                <button type="button" class="btn btn-link btn-sm p-0 text-truncate" style="max-width: 18rem" @click="view(d)">{{ d.original_name }}</button>
                <span class="text-muted">{{ formatSize(d.size) }}</span>
                <span v-if="d.upload_type === 'undertaking'" class="badge text-bg-warning" title="Signed undertaking in place of the original">Undertaking — original {{ d.original_status === 'received_verified' ? 'verified' : d.original_status === 'received_rejected' ? 'not accepted' : 'still required' }}</span>
                <span v-else class="badge text-bg-light border">Original</span>
                <span v-if="d.is_frozen" class="badge text-bg-light border" title="Part of a submitted version">submitted</span>
                <button v-else-if="!disabled" type="button" class="btn btn-link btn-sm text-danger p-0 ms-auto" @click="remove(d)"><i class="bi bi-trash"></i><span class="visually-hidden">Remove</span></button>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.upload-preview { display: inline-block; padding: 0.375rem; background: #fff; border: 1px solid var(--line); border-radius: 0.375rem; }
/* Scaled down proportionally only: never stretched. */
.upload-preview img { display: block; width: auto; height: auto; max-width: min(100%, 16rem); max-height: 6rem; object-fit: contain; }
</style>
