<script setup lang="ts">
/** Section 20 — Conviction / criminal case / disciplinary action / vigilance enquiry. */
import FieldError from '@/components/FieldError.vue';
import { useWizard } from '../context';
import StepUploads from '../StepUploads.vue';
import YesNo from '../YesNo.vue';
import type { CriminalData } from '../types';

defineProps<{ form: CriminalData }>();
const { errors } = useWizard();
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header">20. Convictions / pending proceedings</div>
            <div class="card-body">
                <p class="required form-label">Have you ever been convicted by a court of Law or is there any criminal case/disciplinary action/vigilance enquiry pending against you?</p>
                <YesNo v-model="form.sec20.answer" name="sec20" :invalid="!!errors['sec20.answer']" />
                <FieldError :errors="errors" name="sec20.answer" />
                <div v-if="form.sec20.answer" class="mt-3">
                    <label class="form-label required" for="sec20-details">If YES, give full details</label>
                    <textarea id="sec20-details" v-model="form.sec20.details" rows="5" class="form-control" :class="{ 'is-invalid': errors['sec20.details'] }" maxlength="5000"></textarea>
                    <div class="form-text">Court / authority, case number, offence or charge, present status. You may also upload a separate sheet below.</div>
                    <FieldError :errors="errors" name="sec20.details" />
                </div>
            </div>
        </div>
        <StepUploads step="criminal" :hide="form.sec20.answer ? [] : ['case_details']" />
    </div>
</template>
