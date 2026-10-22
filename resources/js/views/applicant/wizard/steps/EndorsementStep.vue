<script setup lang="ts">
/** Section 32 — Endorsement by the employer (in-service candidates). */
import { computed } from 'vue';
import FieldError from '@/components/FieldError.vue';
import { today, useWizard } from '../context';
import StepUploads from '../StepUploads.vue';
import type { EndorsementData } from '../types';

const props = defineProps<{ form: EndorsementData }>();
const { payload, errors } = useWizard();
const req = computed(() => props.form.required);
const name = computed(() => payload.value.data.personal.full_name || 'the applicant');
const types = computed(() => payload.value.options.employment_types);
const inv = (k: string) => ({ 'is-invalid': !!errors[k] });
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header">32. Endorsement by the employer</div>
            <div v-if="!form.applicable" class="card-body">
                <div class="alert alert-secondary mb-0">
                    <i class="bi bi-dash-circle me-1"></i>
                    <strong>Not applicable.</strong> The employer's endorsement applies only to candidates presently in service,
                    and only where the advertisement calls for it. You have not stated that you are in service (Section 19),
                    or the endorsement is not required for this post. You may continue to the next step.
                </div>
            </div>
            <div v-else class="card-body">
                <div class="alert small" :class="req ? 'alert-warning' : 'alert-info'">
                    <i class="bi me-1" :class="req ? 'bi-exclamation-triangle' : 'bi-info-circle'"></i>
                    <template v-if="req"><strong>Mandatory.</strong> As you are in service, the endorsement of your employer is required under this advertisement. Fill in the particulars from the endorsement issued by your forwarding officer and upload the signed and sealed endorsement.</template>
                    <template v-else>As you are in service you may furnish your employer's endorsement. It is optional for this post.</template>
                    <div v-if="form.instructions" class="text-pre mt-2">{{ form.instructions }}</div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" :class="{ required: req }" for="en-org">Name of employer / organisation</label>
                        <input id="en-org" v-model="form.employer_organization" class="form-control" :class="inv('employer_organization')" maxlength="250">
                        <FieldError :errors="errors" name="employer_organization" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" :class="{ required: req }" for="en-desig">Present designation of the applicant</label>
                        <input id="en-desig" v-model="form.designation" class="form-control" :class="inv('designation')" maxlength="200">
                        <FieldError :errors="errors" name="designation" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" :class="{ required: req }" for="en-type">Nature of appointment</label>
                        <select id="en-type" v-model="form.employment_type" class="form-select" :class="inv('employment_type')">
                            <option :value="null">Select…</option>
                            <option v-for="(label, v) in types" :key="v" :value="v">{{ label }}</option>
                        </select>
                        <FieldError :errors="errors" name="employment_type" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" :class="{ required: req }" for="en-start">Date of joining</label>
                        <input id="en-start" v-model="form.employment_start_date" type="date" class="form-control" :class="inv('employment_start_date')" :max="today()">
                        <FieldError :errors="errors" name="employment_start_date" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="en-incr">Date of next increment</label>
                        <input id="en-incr" v-model="form.next_increment_date" type="date" class="form-control" :class="inv('next_increment_date')">
                        <FieldError :errors="errors" name="next_increment_date" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" :class="{ required: req }" for="en-scale">Pay scale / Pay level</label>
                        <input id="en-scale" v-model="form.pay_scale_level" class="form-control" :class="inv('pay_scale_level')" maxlength="150">
                        <FieldError :errors="errors" name="pay_scale_level" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" :class="{ required: req }" for="en-basic">Present basic pay (₹)</label>
                        <input id="en-basic" v-model.number="form.basic_pay" type="number" min="0" step="1" class="form-control" :class="inv('basic_pay')">
                        <FieldError :errors="errors" name="basic_pay" />
                    </div>
                </div>

                <div class="border rounded p-3 mt-3 bg-light">
                    <p class="small mb-2">
                        Forwarded. Certified that the particulars furnished by <strong>{{ name }}</strong> have been verified with the
                        office records and found correct.
                    </p>
                    <div class="form-check mb-2">
                        <input id="en-disc" v-model="form.no_disciplinary_case_certified" class="form-check-input" :class="inv('no_disciplinary_case_certified')" type="checkbox">
                        <label class="form-check-label small" :class="{ required: req }" for="en-disc">
                            It is further certified that no disciplinary / vigilance case has been held, is contemplated or is pending against the applicant.
                        </label>
                        <FieldError :errors="errors" name="no_disciplinary_case_certified" />
                    </div>
                    <div class="form-check">
                        <input id="en-noc" v-model="form.no_objection_certified" class="form-check-input" :class="inv('no_objection_certified')" type="checkbox">
                        <label class="form-check-label small" :class="{ required: req }" for="en-noc">
                            This office has no objection to the application being considered for the post applied for.
                        </label>
                        <FieldError :errors="errors" name="no_objection_certified" />
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <label class="form-label" :class="{ required: req }" for="en-oname">Name of the forwarding officer</label>
                        <input id="en-oname" v-model="form.forwarding_officer_name" class="form-control" :class="inv('forwarding_officer_name')" maxlength="200">
                        <FieldError :errors="errors" name="forwarding_officer_name" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" :class="{ required: req }" for="en-odesig">Designation of the forwarding officer (with seal)</label>
                        <input id="en-odesig" v-model="form.forwarding_officer_designation" class="form-control" :class="inv('forwarding_officer_designation')" maxlength="200">
                        <FieldError :errors="errors" name="forwarding_officer_designation" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" :class="{ required: req }" for="en-place">Place</label>
                        <input id="en-place" v-model="form.place" class="form-control" :class="inv('place')" maxlength="100">
                        <FieldError :errors="errors" name="place" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" :class="{ required: req }" for="en-date">Date of endorsement</label>
                        <input id="en-date" v-model="form.endorsed_on" type="date" class="form-control" :class="inv('endorsed_on')" :max="today()">
                        <FieldError :errors="errors" name="endorsed_on" />
                    </div>
                </div>
            </div>
        </div>
        <StepUploads v-if="form.applicable" step="endorsement" :force-required="req ? ['employer_endorsement'] : []" />
    </div>
</template>
