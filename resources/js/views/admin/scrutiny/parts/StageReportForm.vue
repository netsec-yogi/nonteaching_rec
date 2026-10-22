<script setup lang="ts">
/**
 * The officer's stage report. Non-decision stages record completeness and a
 * recommendation; the decision stage records the decision. Both require an
 * explicit confirmation before submission.
 */
import { computed, reactive, ref, watch } from 'vue';
import { api } from '@/api/client';
import { useForm } from '@/composables/useForm';
import FieldError from '@/components/FieldError.vue';
import BaseModal from '@/components/BaseModal.vue';
import { humanize } from '@/utils/format';
import type { ScrutinyContext } from './types';

const props = defineProps<{
    applicationUuid: string;
    stage: NonNullable<ScrutinyContext['stage']>;
    draft: ScrutinyContext['draft_review'];
    pendingMandatory: number;
    openClarifications: number;
}>();
const emit = defineEmits<{ (e: 'submitted'): void }>();

const { busy, errors, run } = useForm();
const form = reactive({ completeness: '', recommendation: '', decision: '', reasons: '', summary: '', notify_candidate: true });
const confirming = ref(false);

function fill() {
    const d = props.draft;
    form.completeness = d?.completeness ?? '';
    form.recommendation = d?.recommendation ?? '';
    form.decision = d?.decision ?? '';
    form.reasons = d?.reasons ?? '';
    form.summary = d?.summary ?? '';
}
fill();
watch(() => props.draft, fill);

const completenessOptions = [
    { value: 'complete', label: 'Complete' },
    { value: 'incomplete', label: 'Incomplete' },
    { value: 'clarification_required', label: 'Clarification required' },
];
const recommendationOptions = [
    { value: 'further_consideration', label: 'Recommended for further consideration' },
    { value: 'rejection', label: 'Recommended for rejection' },
];
const decisionOptions = [
    { value: 'recommended', label: 'Recommended (complete)', cls: 'btn-outline-success' },
    { value: 'incomplete', label: 'Incomplete', cls: 'btn-outline-warning' },
    { value: 'not_recommended', label: 'Not recommended', cls: 'btn-outline-danger' },
    { value: 'returned', label: 'Return to previous stage', cls: 'btn-outline-secondary' },
];

const isDecision = computed(() => props.stage.is_decision_stage);
const reasonsRequired = computed(() => isDecision.value
    ? !!form.decision && form.decision !== 'recommended'
    : form.recommendation === 'rejection' || (!!form.completeness && form.completeness !== 'complete'));

/** Client-side mirror of ScrutinyService::submitReview so the officer sees blockers before confirming. */
const blockers = computed(() => {
    const out: string[] = [];
    if (isDecision.value) {
        if (!form.decision) out.push('Choose a decision.');
    } else {
        if (props.pendingMandatory > 0) out.push(`${props.pendingMandatory} mandatory checklist item(s) still pending.`);
        if (!form.completeness) out.push('Record the completeness.');
        if (!form.recommendation) out.push('Record your recommendation.');
        if (props.openClarifications > 0 && form.completeness && form.completeness !== 'clarification_required') {
            out.push('A clarification request is still open: resolve it or report the application as requiring clarification.');
        }
    }
    if (reasonsRequired.value && !form.reasons.trim()) out.push('Record the reasons.');
    return out;
});

const label = (opts: { value: string; label: string }[], v: string) => opts.find((o) => o.value === v)?.label ?? humanize(v);

async function submit() {
    const payload = isDecision.value
        ? { decision: form.decision, reasons: form.reasons.trim() || null, summary: form.summary.trim() || null, notify_candidate: form.decision !== 'returned' && form.notify_candidate, confirm: true }
        : { completeness: form.completeness, recommendation: form.recommendation, reasons: form.reasons.trim() || null, summary: form.summary.trim() || null, confirm: true };
    const ok = await run(() => api.post(`/admin/applications/${props.applicationUuid}/scrutiny/review`, payload), isDecision.value ? 'Decision recorded.' : 'Stage report submitted.');
    confirming.value = false;
    if (ok) emit('submitted');
}
</script>

<template>
    <div class="card section-card">
        <div class="card-header d-flex align-items-center">
            <span class="flex-grow-1">{{ isDecision ? 'Decision' : 'Stage report' }} — {{ stage.name }}</span>
            <span v-if="draft?.status === 'draft'" class="badge text-bg-warning">Returned for revision</span>
        </div>
        <div class="card-body">
            <template v-if="isDecision">
                <label class="form-label small required d-block">Decision</label>
                <div class="btn-group btn-group-sm flex-wrap mb-1" role="group" aria-label="Decision">
                    <template v-for="o in decisionOptions" :key="o.value">
                        <input :id="`dec-${o.value}`" v-model="form.decision" class="btn-check" type="radio" name="decision" :value="o.value">
                        <label class="btn" :class="o.cls" :for="`dec-${o.value}`">{{ o.label }}</label>
                    </template>
                </div>
                <FieldError :errors="errors" name="decision" />
                <div v-if="form.decision === 'returned'" class="form-text">The application goes back to the previous stage’s officer; their report reopens and this decision is recorded again later.</div>
            </template>
            <div v-else class="row g-2">
                <div class="col-md-6">
                    <label class="form-label small required" for="rv-completeness">Completeness</label>
                    <select id="rv-completeness" v-model="form.completeness" class="form-select form-select-sm" :class="{ 'is-invalid': errors.completeness }">
                        <option value="" disabled>Select…</option>
                        <option v-for="o in completenessOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                    <FieldError :errors="errors" name="completeness" />
                </div>
                <div class="col-md-6">
                    <label class="form-label small required" for="rv-recommendation">Recommendation</label>
                    <select id="rv-recommendation" v-model="form.recommendation" class="form-select form-select-sm" :class="{ 'is-invalid': errors.recommendation }">
                        <option value="" disabled>Select…</option>
                        <option v-for="o in recommendationOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                    <FieldError :errors="errors" name="recommendation" />
                </div>
            </div>

            <div class="mt-2">
                <label class="form-label small" :class="{ required: reasonsRequired }" for="rv-reasons">Reasons</label>
                <textarea id="rv-reasons" v-model="form.reasons" class="form-control form-control-sm" :class="{ 'is-invalid': errors.reasons }" rows="3" maxlength="10000"></textarea>
                <FieldError :errors="errors" name="reasons" />
            </div>
            <div class="mt-2">
                <label class="form-label small" for="rv-summary">Summary of scrutiny</label>
                <textarea id="rv-summary" v-model="form.summary" class="form-control form-control-sm" rows="3" maxlength="10000"></textarea>
            </div>
            <div v-if="isDecision && form.decision && form.decision !== 'returned'" class="form-check mt-2">
                <input id="rv-notify" v-model="form.notify_candidate" class="form-check-input" type="checkbox">
                <label class="form-check-label small" for="rv-notify">Notify the candidate of this decision by email</label>
            </div>

            <FieldError :errors="errors" name="checklist" />
            <FieldError :errors="errors" name="confirm" />

            <ul v-if="blockers.length" class="small text-warning-emphasis mt-2 mb-0 ps-3">
                <li v-for="b in blockers" :key="b">{{ b }}</li>
            </ul>
            <div class="text-end mt-2">
                <button type="button" class="btn btn-primary" :disabled="busy || blockers.length > 0" @click="confirming = true">
                    <i class="bi bi-send-check me-1"></i>{{ isDecision ? 'Record decision…' : 'Submit stage report…' }}
                </button>
            </div>
        </div>
    </div>

    <BaseModal v-if="confirming" :title="isDecision ? 'Confirm decision' : 'Confirm stage report'" @close="confirming = false">
        <p class="small">You are about to submit the following for <strong>{{ stage.name }}</strong>. Once submitted, the report is locked and the application moves on.</p>
        <table class="table table-sm kv-table small">
            <tbody>
                <template v-if="isDecision">
                    <tr><th>Decision</th><td class="fw-semibold">{{ label(decisionOptions, form.decision) }}</td></tr>
                    <tr v-if="form.decision !== 'returned'"><th>Notify candidate</th><td>{{ form.notify_candidate ? 'Yes' : 'No' }}</td></tr>
                </template>
                <template v-else>
                    <tr><th>Completeness</th><td>{{ label(completenessOptions, form.completeness) }}</td></tr>
                    <tr><th>Recommendation</th><td class="fw-semibold">{{ label(recommendationOptions, form.recommendation) }}</td></tr>
                </template>
                <tr><th>Reasons</th><td class="text-pre">{{ form.reasons || '—' }}</td></tr>
            </tbody>
        </table>
        <p class="small text-muted mb-0">I confirm that I have scrutinised this application and that this report is accurate.</p>
        <template #footer>
            <button type="button" class="btn btn-secondary" @click="confirming = false">Cancel</button>
            <button type="button" class="btn btn-primary" :disabled="busy" @click="submit">
                <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>Confirm and submit
            </button>
        </template>
    </BaseModal>
</template>
