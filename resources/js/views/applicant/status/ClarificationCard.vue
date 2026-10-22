<script setup lang="ts">
/** One clarification request with previous responses and, when open, a response form. */
import { computed, reactive, ref } from 'vue';
import { api } from '@/api/client';
import { useForm } from '@/composables/useForm';
import StatusBadge from '@/components/StatusBadge.vue';
import FieldError from '@/components/FieldError.vue';
import { formatDateTime, formatSize } from '@/utils/format';
import type { StatusClarification } from './types';

const props = defineProps<{ uuid: string; clarification: StatusClarification }>();
const emit = defineEmits<{ (e: 'responded'): void }>();

const MAX_FILES = 10;
const MAX_BYTES = 10 * 1024 * 1024;
const { busy, errors, message, run } = useForm();
const text = ref('');
let seq = 0;
const files = reactive<{ key: number; file: File | null; label: string; error: string | null }[]>([]);

const c = computed(() => props.clarification);
const overdue = computed(() => !!c.value.due_at && new Date(c.value.due_at).getTime() < Date.now());
const statusLabel: Record<string, string> = { open: 'Response required', responded: 'Response sent', resolved: 'Resolved', closed: 'Closed' };
const fid = (suffix: string) => `clar-${c.value.id}-${suffix}`;

function addFile() {
    if (files.length < MAX_FILES) files.push({ key: ++seq, file: null, label: '', error: null });
}

function pick(row: (typeof files)[number], e: Event) {
    const f = (e.target as HTMLInputElement).files?.[0] ?? null;
    row.error = f && f.size > MAX_BYTES ? 'The file is larger than 10 MB.' : null;
    row.file = row.error ? null : f;
    if (row.error) (e.target as HTMLInputElement).value = '';
}

async function submit() {
    const form = new FormData();
    form.append('message', text.value.trim());
    files.filter((r) => r.file).forEach((r, i) => {
        form.append(`files[${i}][file]`, r.file as File);
        form.append(`files[${i}][document_type]`, 'clarification_document');
        if (r.label.trim()) form.append(`files[${i}][label]`, r.label.trim());
    });
    const ok = await run(() => api.post(`/applications/${props.uuid}/clarifications/${c.value.id}`, form), 'Your response has been sent.');
    if (ok) {
        text.value = '';
        files.splice(0);
        emit('responded');
    }
}

/** Errors for uploaded file i, from Laravel's dotted keys. */
function fileError(i: number): string | undefined {
    return errors[`files.${i}.file`]?.[0] ?? errors[`files.${i}.document_type`]?.[0] ?? errors[`files.${i}.label`]?.[0];
}
</script>

<template>
    <article class="card" :class="c.can_respond ? 'border-warning' : ''">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2" :class="c.can_respond ? 'bg-warning-subtle' : 'bg-white'">
            <h3 class="h6 mb-0">{{ c.subject }}</h3>
            <StatusBadge :value="c.status" :label="statusLabel[c.status]" />
        </div>
        <div class="card-body">
            <div class="small text-muted mb-2">
                Requested {{ formatDateTime(c.created_at) }}
                <span v-if="c.due_at"> · <span :class="overdue && c.status === 'open' ? 'text-danger fw-semibold' : (c.can_respond ? 'fw-semibold text-body' : '')">Respond by {{ formatDateTime(c.due_at) }}</span></span>
            </div>
            <div class="text-pre mb-3">{{ c.message }}</div>

            <template v-if="c.responses.length">
                <h4 class="small text-uppercase text-muted">Your previous response{{ c.responses.length === 1 ? '' : 's' }}</h4>
                <div v-for="(r, i) in c.responses" :key="i" class="border-start border-3 ps-3 mb-2">
                    <div class="small text-muted">{{ formatDateTime(r.at) }}</div>
                    <div class="text-pre small">{{ r.message }}</div>
                </div>
            </template>

            <form v-if="c.can_respond" class="mt-3 pt-3 border-top" novalidate @submit.prevent="submit">
                <div v-if="message && !Object.keys(errors).length" class="alert alert-danger small" role="alert">{{ message }}</div>
                <div class="mb-3">
                    <label :for="fid('msg')" class="form-label required">Your response</label>
                    <textarea :id="fid('msg')" v-model="text" class="form-control" :class="{ 'is-invalid': errors.message }" rows="4" maxlength="5000" required></textarea>
                    <div class="form-text text-end">{{ text.length }} / 5000</div>
                    <FieldError :errors="errors" name="message" />
                </div>

                <div v-if="c.allow_uploads" class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="form-label mb-0">Supporting documents <span class="text-muted small fw-normal">(optional, up to {{ MAX_FILES }} files, 10 MB each)</span></span>
                        <button type="button" class="btn btn-sm btn-outline-primary" :disabled="files.length >= MAX_FILES" @click="addFile">
                            <i class="bi bi-paperclip me-1" aria-hidden="true"></i>Add file
                        </button>
                    </div>
                    <div v-for="(row, i) in files" :key="row.key" class="row g-2 align-items-start mb-2">
                        <div class="col-md-6">
                            <label :for="fid(`file-${row.key}`)" class="visually-hidden">File {{ i + 1 }}</label>
                            <input :id="fid(`file-${row.key}`)" type="file" class="form-control form-control-sm" :class="{ 'is-invalid': row.error || fileError(i) }" accept="application/pdf,image/jpeg,image/png" @change="pick(row, $event)">
                            <div v-if="row.file" class="form-text">{{ row.file.name }} · {{ formatSize(row.file.size) }}</div>
                            <div v-if="row.error || fileError(i)" class="invalid-feedback d-block">{{ row.error ?? fileError(i) }}</div>
                        </div>
                        <div class="col-md-5">
                            <label :for="fid(`label-${row.key}`)" class="visually-hidden">Description for file {{ i + 1 }}</label>
                            <input :id="fid(`label-${row.key}`)" v-model="row.label" type="text" class="form-control form-control-sm" maxlength="250" placeholder="Description, e.g. Corrected caste certificate">
                        </div>
                        <div class="col-md-1 text-end">
                            <button type="button" class="btn btn-sm btn-link text-danger" @click="files.splice(i, 1)"><i class="bi bi-x-lg" aria-hidden="true"></i><span class="visually-hidden">Remove file {{ i + 1 }}</span></button>
                        </div>
                    </div>
                    <FieldError :errors="errors" name="files" />
                </div>

                <button type="submit" class="btn btn-primary" :disabled="busy || !text.trim()">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                    Send response
                </button>
            </form>
            <p v-else-if="c.status === 'open'" class="small text-muted mb-0 mt-2">This request is no longer open for responses. Contact the Recruitment Section if you need help.</p>
        </div>
    </article>
</template>
