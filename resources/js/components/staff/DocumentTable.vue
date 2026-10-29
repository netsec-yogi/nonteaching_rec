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

const ORIGINAL_LABELS: Record<string, string> = { awaited: 'Original required', received_verified: 'Original received and verified', received_rejected: 'Original not accepted' };
const original = ref<StaffDocument | null>(null);
const originalStatus = ref<'received_verified' | 'received_rejected' | 'awaited'>('received_verified');
const originalRemarks = ref('');
function startOriginal(d: StaffDocument) {
    original.value = d;
    originalStatus.value = d.original_status === 'received_rejected' ? 'received_rejected' : 'received_verified';
    originalRemarks.value = d.original_remarks ?? '';
}
async function saveOriginal() {
    if (!original.value) return;
    busy.value = true;
    try {
        await api.post(`/admin/applications/${props.applicationUuid}/documents/${original.value.uuid}/original`, { status: originalStatus.value, remarks: originalRemarks.value || null });
        toast.success('Original document recorded.');
        original.value = null;
        emit('verified');
    } catch (e) { fail(e); } finally { busy.value = false; }
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
                        <span v-if="d.upload_type === 'undertaking'" class="badge text-bg-warning">Signed undertaking</span>
                        <span v-else class="badge text-bg-light border">Original</span>
                    </td>
                    <td class="small text-break">{{ d.original_name }}</td>
                    <td class="small text-nowrap">{{ formatSize(d.size) }}</td>
                    <td class="small text-nowrap">{{ formatDateTime(d.uploaded_at) }}</td>
                    <td>
                        <StatusBadge :value="d.verification_status" />
                        <div v-if="d.verification_remarks" class="small text-muted">{{ d.verification_remarks }}</div>
                        <div v-if="d.verified_by" class="small text-muted">{{ d.verified_by }}, {{ formatDateTime(d.verified_at) }}</div>
                        <template v-if="d.upload_type === 'undertaking'">
                            <div class="small mt-1"><StatusBadge :value="d.original_status === 'received_verified' ? 'verified' : d.original_status === 'received_rejected' ? 'rejected' : 'pending'" :label="ORIGINAL_LABELS[d.original_status ?? 'awaited']" /></div>
                            <div v-if="d.original_status === 'awaited'" class="small text-muted">{{ d.original_due_stage }}</div>
                            <div v-if="d.original_received_by" class="small text-muted">{{ d.original_received_by }}, {{ formatDateTime(d.original_received_at) }}</div>
                            <div v-if="d.original_remarks" class="small text-muted">{{ d.original_remarks }}</div>
                        </template>
                    </td>
                    <td class="text-end text-nowrap">
                        <template v-if="canDownload">
                            <button class="btn btn-sm btn-outline-secondary me-1" title="Open" @click="openInline(url(d)).catch(fail)"><i class="bi bi-eye"></i></button>
                            <button class="btn btn-sm btn-outline-secondary me-1" title="Download" @click="download(url(d), d.original_name).catch(fail)"><i class="bi bi-download"></i></button>
                        </template>
                        <button v-if="canVerify" class="btn btn-sm btn-outline-primary" :aria-label="`Verify ${d.document_type_name}`" @click="startVerify(d)">Verify</button>
                        <button v-if="canVerify && d.upload_type === 'undertaking'" class="btn btn-sm btn-outline-warning ms-1" @click="startOriginal(d)">Record original</button>
                    </td>
                </tr>
                <tr v-if="!documents.length"><td colspan="6" class="text-muted small">No documents uploaded.</td></tr>
            </tbody>
        </table>
    </div>

    <BaseModal v-if="original" title="Record the original document" @close="original = null">
        <p class="small mb-2"><strong>{{ original.document_type_name }}</strong> — submitted as a signed undertaking. Record whether the original was produced (at the interview or document verification) and checked. The undertaking and its verification stay on record.</p>
        <div class="btn-group mb-3" role="group">
            <input id="or-v" v-model="originalStatus" class="btn-check" type="radio" value="received_verified"><label class="btn btn-outline-success btn-sm" for="or-v">Received and verified</label>
            <input id="or-r" v-model="originalStatus" class="btn-check" type="radio" value="received_rejected"><label class="btn btn-outline-danger btn-sm" for="or-r">Not accepted</label>
            <input id="or-a" v-model="originalStatus" class="btn-check" type="radio" value="awaited"><label class="btn btn-outline-secondary btn-sm" for="or-a">Still required</label>
        </div>
        <label class="form-label small" :class="{ required: originalStatus === 'received_rejected' }" for="or-remarks">Remarks</label>
        <textarea id="or-remarks" v-model="originalRemarks" class="form-control" rows="3" maxlength="500"></textarea>
        <template #footer>
            <button class="btn btn-secondary" @click="original = null">Cancel</button>
            <button class="btn btn-primary" :disabled="busy || (originalStatus === 'received_rejected' && !originalRemarks.trim())" @click="saveOriginal">Save</button>
        </template>
    </BaseModal>

    <BaseModal v-if="verifying" title="Record document verification" @close="verifying = null">
        <p v-if="verifying.upload_type === 'undertaking'" class="small alert alert-warning py-2">This is a signed undertaking: verifying it accepts the undertaking (that it carries the required declaration). It does not verify the original document.</p>
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
