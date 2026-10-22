<script setup lang="ts">
/** Tab 4: the advertisement PDF(s) published alongside the online advertisement. */
import { ref } from 'vue';
import { api, openInline, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import { useToast } from '@/stores/toast';
import FieldError from '@/components/FieldError.vue';
import { formatDateTime, formatSize } from '@/utils/format';
import type { AdDocument, AdvertisementDetail } from './types';

const props = defineProps<{ ad: AdvertisementDetail }>();
const emit = defineEmits<{ (e: 'reload'): void }>();
const toast = useToast();
const form = useForm();
const title = ref('');
const isPublic = ref(true);
const file = ref<File | null>(null);
const input = ref<HTMLInputElement | null>(null);
const progress = ref<number | null>(null);

function pick(e: Event) {
    file.value = (e.target as HTMLInputElement).files?.[0] ?? null;
    if (file.value && !title.value) title.value = file.value.name.replace(/\.pdf$/i, '');
}

async function upload() {
    if (!file.value) { form.errors.file = ['Choose a PDF file.']; return; }
    if (file.value.size > 20 * 1024 * 1024) { form.errors.file = ['The file may not be larger than 20 MB.']; return; }
    const data = new FormData();
    data.append('title', title.value);
    data.append('file', file.value);
    data.append('is_public', isPublic.value ? '1' : '0');
    progress.value = 0;
    const res = await form.run(() => api.post(`/admin/advertisements/${props.ad.id}/documents`, data, {
        onUploadProgress: (p) => { progress.value = p.total ? Math.round((p.loaded / p.total) * 100) : null; },
    }), 'Document uploaded.');
    progress.value = null;
    if (res) {
        title.value = ''; file.value = null; if (input.value) input.value.value = '';
        emit('reload');
    }
}

async function remove(d: AdDocument) {
    if (!confirm(`Delete “${d.title}”? The file is removed permanently.`)) return;
    try {
        await api.delete(`/admin/advertisements/${props.ad.id}/documents/${d.id}`);
        toast.success('Document deleted.');
        emit('reload');
    } catch (e) { toast.error(toApiError(e).message); }
}
</script>

<template>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Title</th><th>File</th><th>Size</th><th>Visibility</th><th>Uploaded</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="d in ad.documents" :key="d.id">
                                <td class="fw-semibold">
                                    <a href="#" @click.prevent="openInline(`/admin/advertisements/${ad.id}/documents/${d.id}`).catch((e) => toast.error(toApiError(e).message))">{{ d.title }}</a>
                                </td>
                                <td class="small text-break">{{ d.original_name }}</td>
                                <td class="small text-nowrap">{{ formatSize(d.size) }}</td>
                                <td><span class="badge" :class="d.is_public ? 'text-bg-success' : 'text-bg-secondary'">{{ d.is_public ? 'Public' : 'Internal' }}</span></td>
                                <td class="small text-nowrap">{{ formatDateTime(d.created_at) }}</td>
                                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" :disabled="ad.status === 'archived'" @click="remove(d)"><i class="bi bi-trash"></i><span class="visually-hidden">Delete</span></button></td>
                            </tr>
                            <tr v-if="!ad.documents.length"><td colspan="6" class="text-center text-muted py-4">No advertisement PDF uploaded.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="small text-muted mt-2">Public documents can be downloaded from the public advertisement page once the advertisement is published.</p>
        </div>
        <div v-if="ad.status !== 'archived'" class="col-lg-5">
            <div class="card section-card">
                <div class="card-header">Upload PDF</div>
                <div class="card-body">
                    <div class="mb-2">
                        <label class="form-label required" for="doc-title">Title</label>
                        <input id="doc-title" v-model="title" class="form-control" maxlength="200" placeholder="Detailed advertisement (English)" :class="{ 'is-invalid': form.errors.title }">
                        <FieldError :errors="form.errors" name="title" />
                    </div>
                    <div class="mb-2">
                        <label class="form-label required" for="doc-file">PDF file (up to 20 MB)</label>
                        <input id="doc-file" ref="input" type="file" accept="application/pdf" class="form-control" :class="{ 'is-invalid': form.errors.file }" @change="pick">
                        <FieldError :errors="form.errors" name="file" />
                    </div>
                    <div class="form-check mb-3">
                        <input id="doc-public" v-model="isPublic" class="form-check-input" type="checkbox">
                        <label class="form-check-label" for="doc-public">Show on the public advertisement page</label>
                    </div>
                    <div v-if="progress !== null" class="progress mb-2" style="height: 6px"><div class="progress-bar" :style="{ width: `${progress}%` }"></div></div>
                    <div v-if="form.message.value && !Object.keys(form.errors).length" class="text-danger small mb-2">{{ form.message.value }}</div>
                    <button type="button" class="btn btn-primary" :disabled="form.busy.value" @click="upload"><i class="bi bi-upload me-1"></i>Upload</button>
                </div>
            </div>
        </div>
    </div>
</template>
