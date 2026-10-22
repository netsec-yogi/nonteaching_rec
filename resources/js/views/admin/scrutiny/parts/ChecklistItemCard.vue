<script setup lang="ts">
/**
 * One checklist item in the scrutiny workspace: what the candidate marked,
 * the verification instructions, the linked document(s) and the officer's
 * verdict. Saving records a verdict only — the application itself is never
 * altered.
 */
import { computed, reactive, ref, watch } from 'vue';
import { api, openInline, toApiError, type ValidationErrors } from '@/api/client';
import FieldError from '@/components/FieldError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { formatDateTime, humanize } from '@/utils/format';
import { useToast } from '@/stores/toast';
import type { StaffDocument } from '@/components/staff/types';
import { ITEM_STATUSES, documentUrl, type ChecklistEntry, type ItemStatus } from './types';

const props = defineProps<{ applicationUuid: string; item: ChecklistEntry; documents: StaffDocument[]; readOnly: boolean; canOpen: boolean }>();
const emit = defineEmits<{ (e: 'saved', checklist: ChecklistEntry[]): void }>();
const toast = useToast();

const form = reactive({ status: props.item.status as ItemStatus, remarks: props.item.remarks ?? '', discrepancy_reason: props.item.discrepancy_reason ?? '', document_id: props.item.document_id as number | null });
const errors = reactive<ValidationErrors>({});
const busy = ref(false);
const uid = `ci-${props.item.checklist_item_id}`;

function reset() {
    form.status = props.item.status;
    form.remarks = props.item.remarks ?? '';
    form.discrepancy_reason = props.item.discrepancy_reason ?? '';
    form.document_id = props.item.document_id;
}
watch(() => props.item, reset);

const dirty = computed(() => form.status !== props.item.status || form.remarks !== (props.item.remarks ?? '')
    || form.discrepancy_reason !== (props.item.discrepancy_reason ?? '') || form.document_id !== props.item.document_id);
const needsReason = computed(() => form.status === 'not_verified' && props.item.requires_reason);
const reasonMissing = computed(() => needsReason.value && !form.discrepancy_reason.trim());

/** Documents relevant to this item: the officer's chosen one, the candidate's reference, or same document type. */
const linked = computed(() => {
    const ids = new Set<number>();
    const out: StaffDocument[] = [];
    const add = (d?: StaffDocument) => { if (d && !ids.has(d.id)) { ids.add(d.id); out.push(d); } };
    if (props.item.document_id) add(props.documents.find((d) => d.id === props.item.document_id));
    if (props.item.candidate_document_id) add(props.documents.find((d) => d.id === props.item.candidate_document_id));
    if (props.item.document_type) props.documents.filter((d) => d.document_type === props.item.document_type).forEach(add);
    return out;
});

const candidateMark = computed(() => {
    const s = (props.item.candidate_status ?? '').toLowerCase();
    if (!s) return { text: 'Not marked', cls: 'text-bg-light border' };
    if (['yes', 'y', '✓', 'tick', 'enclosed', 'attached'].includes(s)) return { text: '✓ Enclosed', cls: 'text-bg-success' };
    if (['no', 'n', 'x', 'not_enclosed'].includes(s)) return { text: 'X Not enclosed', cls: 'text-bg-danger' };
    if (['na', 'n/a', 'not_applicable'].includes(s)) return { text: 'NA', cls: 'text-bg-secondary' };
    return { text: props.item.candidate_status as string, cls: 'text-bg-light border' };
});

const borderClass = computed(() => ({
    verified: 'border-success', not_verified: 'border-danger', not_applicable: 'border-secondary', pending: props.item.is_mandatory ? 'border-warning' : '',
}[props.item.status]));

function open(d: StaffDocument) {
    openInline(documentUrl(props.applicationUuid, d.uuid)).catch((e) => toast.error(toApiError(e).message));
}

async function save() {
    if (reasonMissing.value) { errors.discrepancy_reason = ['Give the reason for the discrepancy.']; return; }
    busy.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        const { data } = await api.put(`/admin/applications/${props.applicationUuid}/scrutiny/items/${props.item.checklist_item_id}`, {
            status: form.status,
            remarks: form.remarks.trim() || null,
            discrepancy_reason: form.status === 'not_verified' ? (form.discrepancy_reason.trim() || null) : null,
            document_id: form.document_id,
        });
        toast.success(`“${props.item.label}” recorded as ${humanize(form.status).toLowerCase()}.`);
        emit('saved', data.checklist as ChecklistEntry[]);
    } catch (e) {
        const ae = toApiError(e);
        Object.assign(errors, ae.errors);
        if (!Object.keys(ae.errors).length) toast.error(ae.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="card border-start border-3" :class="borderClass">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap align-items-start gap-2 mb-1">
                <div class="flex-grow-1">
                    <div class="fw-semibold">
                        {{ item.label }}
                        <span v-if="item.is_mandatory" class="badge text-bg-light border text-danger ms-1" title="Mandatory item">Mandatory</span>
                    </div>
                    <div class="small text-muted">
                        <span v-if="item.section">{{ humanize(item.section) }} · </span>{{ item.code }}
                    </div>
                </div>
                <StatusBadge :value="item.status" />
            </div>

            <div class="small mb-2">
                <span class="text-muted">Candidate marked:</span>
                <span class="badge ms-1" :class="candidateMark.cls">{{ candidateMark.text }}</span>
                <span v-if="item.candidate_page_reference" class="ms-2"><span class="text-muted">Page ref:</span> {{ item.candidate_page_reference }}</span>
            </div>

            <div v-if="item.instructions" class="small bg-light border rounded px-2 py-1 mb-2">
                <i class="bi bi-info-circle me-1 text-info"></i><span class="text-pre">{{ item.instructions }}</span>
            </div>

            <div class="small mb-2">
                <span class="text-muted me-1">Supporting document:</span>
                <template v-if="linked.length">
                    <button v-for="d in linked" :key="d.uuid" type="button" class="btn btn-link btn-sm p-0 me-2 align-baseline"
                        :disabled="!canOpen" :title="canOpen ? 'Open in a new tab' : 'You do not have permission to open documents'" @click="open(d)">
                        <i class="bi" :class="d.mime === 'application/pdf' ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image text-info'"></i>
                        {{ d.label || d.document_type_name || d.original_name }}
                        <StatusBadge v-if="d.verification_status !== 'pending'" :value="d.verification_status" class="ms-1" />
                    </button>
                </template>
                <span v-else class="text-muted fst-italic">none linked</span>
            </div>

            <fieldset :disabled="readOnly || busy">
                <div class="btn-group btn-group-sm flex-wrap mb-2" role="group" :aria-label="`Verdict for ${item.label}`">
                    <template v-for="s in ITEM_STATUSES" :key="s.value">
                        <input :id="`${uid}-${s.value}`" v-model="form.status" class="btn-check" type="radio" :name="`${uid}-status`" :value="s.value">
                        <label class="btn" :class="s.btn" :for="`${uid}-${s.value}`"><i class="bi me-1" :class="s.icon"></i>{{ s.label }}</label>
                    </template>
                </div>

                <div v-if="form.status === 'not_verified'" class="mb-2">
                    <label class="form-label small mb-1" :class="{ required: item.requires_reason }" :for="`${uid}-reason`">Discrepancy reason</label>
                    <textarea :id="`${uid}-reason`" v-model="form.discrepancy_reason" class="form-control form-control-sm" :class="{ 'is-invalid': errors.discrepancy_reason || (dirty && reasonMissing) }" rows="2" maxlength="2000"></textarea>
                    <FieldError :errors="errors" name="discrepancy_reason" />
                </div>

                <div class="row g-2">
                    <div class="col-md-7">
                        <label class="form-label small mb-1" :for="`${uid}-remarks`">Remarks</label>
                        <textarea :id="`${uid}-remarks`" v-model="form.remarks" class="form-control form-control-sm" rows="2" maxlength="2000"></textarea>
                        <FieldError :errors="errors" name="remarks" />
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small mb-1" :for="`${uid}-doc`">Document verified against</label>
                        <select :id="`${uid}-doc`" v-model="form.document_id" class="form-select form-select-sm">
                            <option :value="null">—</option>
                            <option v-for="d in documents" :key="d.id" :value="d.id">{{ d.document_type_name || 'Document' }}{{ d.label ? ' – ' + d.label : '' }}</option>
                        </select>
                        <FieldError :errors="errors" name="document_id" />
                    </div>
                </div>
            </fieldset>

            <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                <div class="small text-muted flex-grow-1">
                    <template v-if="item.verified_by">Recorded by {{ item.verified_by }}<span v-if="item.verified_at">, {{ formatDateTime(item.verified_at) }}</span></template>
                    <template v-else>No verdict recorded yet.</template>
                    <span v-if="item.status === 'not_verified' && item.discrepancy_reason && !dirty" class="d-block text-danger">Discrepancy: {{ item.discrepancy_reason }}</span>
                </div>
                <template v-if="!readOnly">
                    <button v-if="dirty" type="button" class="btn btn-sm btn-link text-secondary" :disabled="busy" @click="reset">Undo</button>
                    <button type="button" class="btn btn-sm btn-primary" :disabled="busy || !dirty || reasonMissing" @click="save">
                        <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>Save
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>
