<script setup lang="ts">
/** The candidate's declaration (page 3 of the form), accepted electronically. */
import FieldError from '@/components/FieldError.vue';
import { formatDateTime } from '@/utils/format';
import { today, useWizard } from '../context';
import StepUploads from '../StepUploads.vue';
import type { CandidateDeclarationData } from '../types';

defineProps<{ form: CandidateDeclarationData }>();
const { payload, errors } = useWizard();
const affirmations: { key: 'affirm_true' | 'affirm_read_instructions' | 'affirm_not_concealed' | 'affirm_consequences'; text: string }[] = [
    { key: 'affirm_read_instructions', text: 'I have carefully read and understood the instructions and particulars supplied to me.' },
    { key: 'affirm_true', text: 'All the entries in this form are true to the best of my knowledge and belief.' },
    { key: 'affirm_not_concealed', text: 'I have not concealed any material information which may debar my candidature for the post applied for.' },
    { key: 'affirm_consequences', text: 'I understand that in the event of suppression or distortion of any fact, I will be denied employment in the Institute, and if already employed, my services will be terminated forthwith.' },
];
const minDate = (() => { const d = new Date(); d.setDate(d.getDate() - 30); return d.toISOString().slice(0, 10); })();
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header d-flex justify-content-between flex-wrap gap-2">
                <span>Declaration</span>
                <span v-if="form.version" class="small text-muted fw-normal">Text version {{ form.version }}</span>
            </div>
            <div class="card-body">
                <blockquote class="border-start border-4 ps-3 py-1 mb-3 text-pre" style="font-family: Georgia, serif">{{ form.text }}</blockquote>
                <p class="small text-muted">
                    Name: <strong>{{ payload.data.personal.full_name ?? '—' }}</strong>
                    <span v-if="form.accepted_at"> · Previously accepted on {{ formatDateTime(form.accepted_at) }}</span>
                </p>
                <div v-for="a in affirmations" :key="a.key" class="form-check mb-2">
                    <input :id="a.key" v-model="form[a.key]" class="form-check-input" :class="{ 'is-invalid': errors[a.key] }" type="checkbox">
                    <label class="form-check-label required" :for="a.key">{{ a.text }}</label>
                    <FieldError :errors="errors" :name="a.key" />
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label required" for="decl-place">Place</label>
                        <input id="decl-place" v-model="form.place" class="form-control" :class="{ 'is-invalid': errors.place }" maxlength="100">
                        <FieldError :errors="errors" name="place" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label required" for="decl-date">Date</label>
                        <input id="decl-date" v-model="form.date" type="date" class="form-control" :class="{ 'is-invalid': errors.date }" :min="minDate" :max="today()">
                        <FieldError :errors="errors" name="date" />
                    </div>
                </div>
                <p class="small text-muted mt-3 mb-0"><i class="bi bi-pen me-1"></i>Saving this step records your electronic acceptance of the declaration in place of a signature.</p>
            </div>
        </div>
        <StepUploads step="declaration" />
    </div>
</template>
