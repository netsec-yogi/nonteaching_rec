<script setup lang="ts">
/**
 * Clarification requests to the candidate: issue a request, read the
 * candidate's responses (with any documents they uploaded) and resolve or
 * close the request. Clarification documents are attached to the response,
 * never substituted into the submitted application.
 */
import { computed, reactive, ref } from 'vue';
import { api, download, openInline, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import FieldError from '@/components/FieldError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import BaseModal from '@/components/BaseModal.vue';
import { formatDate, formatDateTime, formatSize } from '@/utils/format';
import { useToast } from '@/stores/toast';
import { documentUrl, type ChecklistEntry, type Clarification, type ClarificationDocument } from './types';

const props = defineProps<{ applicationUuid: string; clarifications: Clarification[]; checklist: ChecklistEntry[]; canCorrespond: boolean; canOpen: boolean }>();
const emit = defineEmits<{ (e: 'changed'): void }>();
const toast = useToast();

const issuing = ref(false);
const issueForm = useForm();
const draft = reactive({ subject: '', message: '', due_at: '', allow_uploads: true, checklist_item_ids: [] as number[] });

const resolving = ref<Clarification | null>(null);
const resolveForm = useForm();
const resolution = reactive({ status: 'resolved' as 'resolved' | 'closed', remarks: '' });

const tomorrow = computed(() => {
    const d = new Date();
    d.setDate(d.getDate() + 1);
    return d.toISOString().slice(0, 10);
});
/** Items with a problem first, so the officer can reference them quickly. */
const items = computed(() => [...props.checklist].sort((a, b) => Number(b.status === 'not_verified') - Number(a.status === 'not_verified')));

function startIssue() {
    const flagged = props.checklist.filter((i) => i.status === 'not_verified');
    draft.subject = '';
    draft.message = flagged.length
        ? 'Please clarify / furnish the following:\n' + flagged.map((i, n) => `${n + 1}. ${i.label}${i.discrepancy_reason ? ' — ' + i.discrepancy_reason : ''}`).join('\n')
        : '';
    draft.due_at = '';
    draft.allow_uploads = true;
    draft.checklist_item_ids = flagged.map((i) => i.checklist_item_id);
    issueForm.clear();
    issuing.value = true;
}

async function issue() {
    const ok = await issueForm.run(() => api.post(`/admin/applications/${props.applicationUuid}/scrutiny/clarifications`, {
        subject: draft.subject.trim(),
        message: draft.message.trim(),
        due_at: draft.due_at || null,
        allow_uploads: draft.allow_uploads,
        checklist_item_ids: draft.checklist_item_ids.length ? draft.checklist_item_ids : null,
    }), 'Clarification request sent to the candidate.');
    if (ok) {
        issuing.value = false;
        emit('changed');
    }
}

function startResolve(c: Clarification, status: 'resolved' | 'closed') {
    resolving.value = c;
    resolution.status = status;
    resolution.remarks = '';
    resolveForm.clear();
}

async function resolve() {
    if (!resolving.value) return;
    const c = resolving.value;
    const ok = await resolveForm.run(() => api.post(`/admin/applications/${props.applicationUuid}/scrutiny/clarifications/${c.id}/resolve`, {
        status: resolution.status, remarks: resolution.remarks.trim() || null,
    }), resolution.status === 'resolved' ? 'Clarification marked as resolved.' : 'Clarification closed.');
    if (ok) {
        resolving.value = null;
        emit('changed');
    }
}

const fail = (e: unknown) => toast.error(toApiError(e).message);
const openDoc = (d: ClarificationDocument) => openInline(documentUrl(props.applicationUuid, d.uuid)).catch(fail);
const downloadDoc = (d: ClarificationDocument) => download(documentUrl(props.applicationUuid, d.uuid), d.original_name).catch(fail);
</script>

<template>
    <div>
        <div class="d-flex align-items-center mb-2">
            <p class="small text-muted mb-0 flex-grow-1">Requests sent to the candidate and their responses. The submitted application is never altered by a response.</p>
            <button v-if="canCorrespond" type="button" class="btn btn-sm btn-primary" @click="startIssue"><i class="bi bi-envelope-plus me-1"></i>Request clarification</button>
        </div>

        <div v-for="c in clarifications" :key="c.id" class="card section-card mb-2">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                <span class="flex-grow-1">{{ c.subject }}</span>
                <StatusBadge :value="c.status" />
            </div>
            <div class="card-body small">
                <div class="text-muted mb-1">Issued by {{ c.by ?? '—' }} on {{ formatDateTime(c.created_at) }} · due {{ formatDate(c.due_at) }}</div>
                <div class="text-pre mb-2">{{ c.message }}</div>

                <div v-for="(r, i) in c.responses" :key="i" class="border-start border-3 border-info ps-2 mb-2">
                    <div class="text-muted">Candidate’s response · {{ formatDateTime(r.at) }}</div>
                    <div class="text-pre">{{ r.message }}</div>
                    <ul v-if="r.documents.length" class="list-unstyled mb-0 mt-1">
                        <li v-for="d in r.documents" :key="d.uuid" class="d-flex align-items-center gap-2">
                            <i class="bi" :class="d.mime === 'application/pdf' ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image text-info'"></i>
                            <span>{{ d.document_type_name }}<span v-if="d.label" class="text-muted"> — {{ d.label }}</span></span>
                            <span class="text-muted">{{ formatSize(d.size) }}</span>
                            <template v-if="canOpen">
                                <button type="button" class="btn btn-link btn-sm p-0" @click="openDoc(d)">Open</button>
                                <button type="button" class="btn btn-link btn-sm p-0" @click="downloadDoc(d)">Download</button>
                            </template>
                        </li>
                    </ul>
                </div>
                <div v-if="!c.responses.length" class="text-muted fst-italic mb-2">No response yet.</div>

                <div v-if="c.resolution_remarks" class="alert alert-light border py-1 px-2 mb-2"><strong>Resolution:</strong> {{ c.resolution_remarks }}</div>

                <div v-if="canCorrespond && ['open', 'responded'].includes(c.status)" class="d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="startResolve(c, 'closed')">Close</button>
                    <button type="button" class="btn btn-sm btn-success" @click="startResolve(c, 'resolved')">Mark resolved</button>
                </div>
            </div>
        </div>
        <p v-if="!clarifications.length" class="text-muted small">No clarification has been requested.</p>

        <BaseModal v-if="issuing" title="Request clarification from the candidate" size="lg" @close="issuing = false">
            <div class="alert alert-info small py-2">The candidate is emailed and can respond from their application page{{ draft.allow_uploads ? ', attaching documents' : '' }}.</div>
            <div class="mb-2">
                <label class="form-label small required" for="cl-subject">Subject</label>
                <input id="cl-subject" v-model="draft.subject" class="form-control" :class="{ 'is-invalid': issueForm.errors.subject }" maxlength="250">
                <FieldError :errors="issueForm.errors" name="subject" />
            </div>
            <div class="mb-2">
                <label class="form-label small required" for="cl-message">Message</label>
                <textarea id="cl-message" v-model="draft.message" class="form-control" :class="{ 'is-invalid': issueForm.errors.message }" rows="6" maxlength="5000"></textarea>
                <FieldError :errors="issueForm.errors" name="message" />
            </div>
            <div class="row g-2 mb-2">
                <div class="col-md-5">
                    <label class="form-label small" for="cl-due">Respond by</label>
                    <input id="cl-due" v-model="draft.due_at" type="date" class="form-control" :min="tomorrow" :class="{ 'is-invalid': issueForm.errors.due_at }">
                    <div class="form-text">Leave blank for the default period.</div>
                    <FieldError :errors="issueForm.errors" name="due_at" />
                </div>
                <div class="col-md-7 d-flex align-items-center">
                    <div class="form-check">
                        <input id="cl-uploads" v-model="draft.allow_uploads" class="form-check-input" type="checkbox">
                        <label class="form-check-label small" for="cl-uploads">Allow the candidate to upload documents with the response</label>
                    </div>
                </div>
            </div>
            <label class="form-label small">Related checklist items</label>
            <div class="border rounded p-2" style="max-height: 14rem; overflow-y: auto">
                <div v-for="i in items" :key="i.checklist_item_id" class="form-check small">
                    <input :id="`cl-item-${i.checklist_item_id}`" v-model="draft.checklist_item_ids" class="form-check-input" type="checkbox" :value="i.checklist_item_id">
                    <label class="form-check-label" :for="`cl-item-${i.checklist_item_id}`">
                        {{ i.label }} <StatusBadge v-if="i.status !== 'pending'" :value="i.status" />
                    </label>
                </div>
            </div>
            <FieldError :errors="issueForm.errors" name="checklist_item_ids" />
            <template #footer>
                <button type="button" class="btn btn-secondary" @click="issuing = false">Cancel</button>
                <button type="button" class="btn btn-primary" :disabled="issueForm.busy.value || !draft.subject.trim() || !draft.message.trim()" @click="issue">Send request</button>
            </template>
        </BaseModal>

        <BaseModal v-if="resolving" :title="resolution.status === 'resolved' ? 'Mark clarification resolved' : 'Close clarification'" @close="resolving = null">
            <p class="small mb-2"><strong>{{ resolving.subject }}</strong></p>
            <div class="btn-group btn-group-sm mb-2" role="group">
                <input id="cr-res" v-model="resolution.status" class="btn-check" type="radio" value="resolved"><label class="btn btn-outline-success" for="cr-res">Resolved</label>
                <input id="cr-cls" v-model="resolution.status" class="btn-check" type="radio" value="closed"><label class="btn btn-outline-secondary" for="cr-cls">Closed without resolution</label>
            </div>
            <label class="form-label small" for="cr-remarks">Remarks</label>
            <textarea id="cr-remarks" v-model="resolution.remarks" class="form-control" rows="3" maxlength="2000"></textarea>
            <FieldError :errors="resolveForm.errors" name="remarks" />
            <template #footer>
                <button type="button" class="btn btn-secondary" @click="resolving = null">Cancel</button>
                <button type="button" class="btn btn-primary" :disabled="resolveForm.busy.value" @click="resolve">Save</button>
            </template>
        </BaseModal>
    </div>
</template>
