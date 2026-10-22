<script setup lang="ts">
/** Section 17 — Ex-servicemen sub-category. */
import FieldError from '@/components/FieldError.vue';
import { useWizard } from '../context';
import StepUploads from '../StepUploads.vue';
import YesNo from '../YesNo.vue';
import type { ExServicemanData } from '../types';

defineProps<{ form: ExServicemanData }>();
const { errors } = useWizard();
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header">17. Ex-servicemen</div>
            <div class="card-body">
                <span class="form-label required d-block">Whether you belong to the Ex-Servicemen sub-category?</span>
                <YesNo v-model="form.is_ex_serviceman" name="is_ex_serviceman" :invalid="!!errors.is_ex_serviceman" />
                <FieldError :errors="errors" name="is_ex_serviceman" />
                <div v-if="form.is_ex_serviceman" class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label required" for="esm-cat">Ex-servicemen category</label>
                        <input id="esm-cat" v-model="form.ex_serviceman_category" class="form-control" :class="{ 'is-invalid': errors.ex_serviceman_category }" maxlength="150" placeholder="e.g. Ex-Serviceman (Army), Disabled Ex-Serviceman">
                        <FieldError :errors="errors" name="ex_serviceman_category" />
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="esm-det">Details (service, period of service, date of release)</label>
                        <textarea id="esm-det" v-model="form.ex_serviceman_details" rows="3" class="form-control" :class="{ 'is-invalid': errors.ex_serviceman_details }" maxlength="2000"></textarea>
                        <FieldError :errors="errors" name="ex_serviceman_details" />
                    </div>
                </div>
            </div>
        </div>
        <StepUploads step="ex_serviceman" :hide="form.is_ex_serviceman ? [] : ['esm_certificate']" />
    </div>
</template>
