<script setup lang="ts">
/**
 * Uploaded documents for staff: open inline, download, and (when allowed)
 * record a verification verdict. Never alters the file itself.
 */
import { ref } from 'vue';
import { api, download, openInline, toApiError } from '@/api/client';
import StatusBadge from '@/components/StatusBadge.vue';
import BaseModal from '@/components/BaseModal.vue';
import { formatDateTime, formatSize } from '@/utils/format';
import { useToast } from '@/stores/toast';
import type { StaffDocument } from './types';

const props = defineProps<{ applicationUuid: string; documents: StaffDocument[]; canDownload: boolean; canVerify?: boolean }>();
const emit = defineEmits<{ (e: 'verified'): void }>();
const toast = useToast();

const verifying = ref<StaffDocument | null>(null);
const verdict = ref<'verified' | 'rejected' | 'pending'>('verified');
const remarks = ref('');
const busy = ref(false);

const url = (d: StaffDocument) => `/applications/${props.applicationUuid}/documents/${d.uuid}`;
const fail = (e: unknown) => toast.error(toApiError(e).message);

function startVerify(d: StaffDocument) {
    verifying.value = d;
    verdict.value = d.verification_status === 'rejected' ? 'rejected' : 'verified';
    remarks.value = d.verification_remarks ?? '';
}

async function saveVerify() {
    if (!verifying.value) return;
    busy.value = true;
    try {
        await api.post(`/admin/applications/${props.applicationUuid}/documents/${verifying.value.uuid}/verify`, { status: verdict.value, remarks: remarks.value || null });
        toast.success('Document verification recorded.');
        verifying.value = null;
        emit('verified');
    } catch (e) { fail(e); } finally { busy.value = false; }
}
</script>

<template>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Document</th><th>File</th><th>Size</th><th>Uploaded</th><th>Verification</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
                <tr v-for="d in documents" :key="d.uuid">
                    <td>
                        <div class="fw-semibold small">{{ d.document_type_name }}</div>
                        <div v-if="d.label" class="text-muted small">{{ d.label }}</div>
                        <span v-if="d.clarification_response_id" class="badge text-bg-warning">Clarification response</span>
                    </td>
                    <td class="small text-break">{{ d.original_name }}</td>
                    <td class="small text-nowrap">{{ formatSize(d.size) }}</td>
                    <td class="small text-nowrap">{{ formatDateTime(d.uploaded_at) }}</td>
                    <td>
                        <StatusBadge :value="d.verification_status" />
                        <div v-if="d.verification_remarks" class="small text-muted">{{ d.verification_remarks }}</div>
                        <div v-if="d.verified_by" class="small text-muted">{{ d.verified_by }}, {{ formatDateTime(d.verified_at) }}</div>
                    </td>
                    <td class="text-end text-nowrap">
                        <template v-if="canDownload">
                            <button class="btn btn-sm btn-outline-secondary me-1" title="Open" @click="openInline(url(d)).catch(fail)"><i class="bi bi-eye"></i></button>
                            <button class="btn btn-sm btn-outline-secondary me-1" title="Download" @click="download(url(d), d.original_name).catch(fail)"><i class="bi bi-download"></i></button>
                        </template>
                        <button v-if="canVerify" class="btn btn-sm btn-outline-primary" @click="startVerify(d)">Verify</button>
                    </td>
                </tr>
                <tr v-if="!documents.length"><td colspan="6" class="text-muted small">No documents uploaded.</td></tr>
            </tbody>
        </table>
    </div>

    <BaseModal v-if="verifying" title="Record document verification" @close="verifying = null">
        <p class="small mb-2"><strong>{{ verifying.document_type_name }}</strong> — {{ verifying.original_name }}</p>
        <div class="btn-group mb-3" role="group">
            <input id="dv-v" v-model="verdict" class="btn-check" type="radio" value="verified"><label class="btn btn-outline-success btn-sm" for="dv-v">Verified</label>
            <input id="dv-r" v-model="verdict" class="btn-check" type="radio" value="rejected"><label class="btn btn-outline-danger btn-sm" for="dv-r">Deficient</label>
            <input id="dv-p" v-model="verdict" class="btn-check" type="radio" value="pending"><label class="btn btn-outline-secondary btn-sm" for="dv-p">Pending</label>
        </div>
        <label class="form-label small" :class="{ required: verdict === 'rejected' }" for="dv-remarks">Remarks</label>
        <textarea id="dv-remarks" v-model="remarks" class="form-control" rows="3" maxlength="500"></textarea>
        <template #footer>
            <button class="btn btn-secondary" @click="verifying = null">Cancel</button>
            <button class="btn btn-primary" :disabled="busy || (verdict === 'rejected' && !remarks.trim())" @click="saveVerify">Save</button>
        </template>
    </BaseModal>
</template>
