<script setup lang="ts">
/** Section 25 — Referees. */
import { computed, onMounted, ref } from 'vue';
import FieldError from '@/components/FieldError.vue';
import { useWizard } from '../context';
import StepUploads from '../StepUploads.vue';
import type { RefereesData } from '../types';

const props = defineProps<{ form: RefereesData }>();
const { payload, errors } = useWizard();
const k = (i: number, f: string) => `referees.${i}.${f}`;
const inv = (i: number, f: string) => ({ 'is-invalid': !!errors[k(i, f)] });
const inService = computed(() => !!payload.value.data.employment.is_in_service);
const employerGiven = computed(() => props.form.referees.some((r) => r.is_employer_or_superior));
const testimonialUploaded = computed(() => payload.value.documents.some((d) => d.document_type === 'employer_testimonial'));

// Sample endorsement letter (a public blank format, for reference only — never attached to the application).
const sampleUrl = computed(() => payload.value.application.reference_documents?.endorsement_sample ?? null);
const sampleUnavailable = ref(false);
onMounted(async () => {
    if (!sampleUrl.value) { sampleUnavailable.value = true; return; }
    try { sampleUnavailable.value = !(await fetch(sampleUrl.value, { method: 'HEAD' })).ok; } catch { sampleUnavailable.value = true; }
});
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header">25. Referees</div>
            <div class="card-body">
                <div class="alert alert-primary small">
                    <div class="fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>Conditions for referees (as on the application form)</div>
                    <p class="mb-1">
                        Give the names and addresses of <strong>{{ form.required_count }}</strong> referees. Referees should be
                        <strong>persons resident in India</strong>, <strong>holders of responsible positions</strong>,
                        <strong>intimately acquainted with the applicant's character and work</strong>, and
                        <strong>must not be relations</strong> of the applicant.
                    </p>
                    <p class="mb-0">
                        Where the applicant is employed, the <strong>present / most recent employer or immediate superior</strong>
                        should be given as one of the referees, or a <strong>testimonial</strong> from them must be produced
                        (upload it below).
                    </p>
                </div>
                <div class="alert alert-light border small">
                    <i class="bi bi-envelope-lock me-1"></i>
                    After your <strong>final submission</strong>, each referee will receive a secure link by email to submit a
                    recommendation online. Recommendations are <strong>confidential</strong>: you will only be able to see whether
                    a recommendation has been received, not its contents. Make sure the email addresses are correct.
                </div>
                <div v-if="inService && !employerGiven && !testimonialUploaded" class="alert alert-warning small py-2">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    You are in service: tick "present / most recent employer or immediate superior" for one referee, or upload a testimonial from your employer.
                </div>
                <FieldError :errors="errors" name="referees" />

                <div class="row g-3">
                    <div v-for="(r, i) in form.referees" :key="r.position" class="col-12">
                        <div class="border rounded p-3">
                            <div class="fw-semibold mb-2"><span class="badge text-bg-primary me-2">{{ r.position }}</span>Referee {{ r.position }}</div>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label small required" :for="`r${i}-name`">Name</label>
                                    <input :id="`r${i}-name`" v-model="r.name" class="form-control form-control-sm" :class="inv(i, 'name')" maxlength="200">
                                    <FieldError :errors="errors" :name="k(i, 'name')" />
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small required" :for="`r${i}-desig`">Designation</label>
                                    <input :id="`r${i}-desig`" v-model="r.designation" class="form-control form-control-sm" :class="inv(i, 'designation')" maxlength="200">
                                    <FieldError :errors="errors" :name="k(i, 'designation')" />
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small required" :for="`r${i}-org`">Organisation</label>
                                    <input :id="`r${i}-org`" v-model="r.organization" class="form-control form-control-sm" :class="inv(i, 'organization')" maxlength="250">
                                    <FieldError :errors="errors" :name="k(i, 'organization')" />
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small required" :for="`r${i}-addr`">Postal address</label>
                                    <textarea :id="`r${i}-addr`" v-model="r.address" rows="1" class="form-control form-control-sm" :class="inv(i, 'address')" maxlength="1000"></textarea>
                                    <FieldError :errors="errors" :name="k(i, 'address')" />
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small required" :for="`r${i}-email`">Email</label>
                                    <input :id="`r${i}-email`" v-model.trim="r.email" type="email" class="form-control form-control-sm" :class="inv(i, 'email')" maxlength="255">
                                    <FieldError :errors="errors" :name="k(i, 'email')" />
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small required" :for="`r${i}-phone`">Phone / mobile</label>
                                    <input :id="`r${i}-phone`" v-model.trim="r.phone" type="tel" inputmode="tel" class="form-control form-control-sm" :class="inv(i, 'phone')" maxlength="20">
                                    <FieldError :errors="errors" :name="k(i, 'phone')" />
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label small required" :for="`r${i}-rel`">In what capacity known to you</label>
                                    <input :id="`r${i}-rel`" v-model="r.relationship" class="form-control form-control-sm" :class="inv(i, 'relationship')" maxlength="200" placeholder="e.g. Head of Department at previous employer">
                                    <FieldError :errors="errors" :name="k(i, 'relationship')" />
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small required" :for="`r${i}-yrs`">Years known</label>
                                    <input :id="`r${i}-yrs`" v-model.number="r.years_known" type="number" min="0" max="80" class="form-control form-control-sm" :class="inv(i, 'years_known')">
                                    <FieldError :errors="errors" :name="k(i, 'years_known')" />
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input :id="`r${i}-res`" v-model="r.is_resident_of_india" class="form-check-input" :class="inv(i, 'is_resident_of_india')" type="checkbox">
                                        <label class="form-check-label small required" :for="`r${i}-res`">The referee is a person resident in India</label>
                                        <FieldError :errors="errors" :name="k(i, 'is_resident_of_india')" />
                                    </div>
                                    <div class="form-check">
                                        <input :id="`r${i}-rel2`" v-model="r.is_not_relative" class="form-check-input" :class="inv(i, 'is_not_relative')" type="checkbox">
                                        <label class="form-check-label small required" :for="`r${i}-rel2`">The referee is not my relation</label>
                                        <FieldError :errors="errors" :name="k(i, 'is_not_relative')" />
                                    </div>
                                    <div class="form-check">
                                        <input :id="`r${i}-emp`" v-model="r.is_employer_or_superior" class="form-check-input" type="checkbox">
                                        <label class="form-check-label small" :for="`r${i}-emp`">The referee is my present / most recent employer or immediate superior</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-check mt-3">
                    <input id="ref-confirm" v-model="form.confirm_accuracy" class="form-check-input" :class="{ 'is-invalid': errors.confirm_accuracy }" type="checkbox">
                    <label class="form-check-label required" for="ref-confirm">I confirm the referee details are accurate and that the referees meet the conditions above.</label>
                    <FieldError :errors="errors" name="confirm_accuracy" />
                </div>
            </div>
        </div>
        <StepUploads step="referees" title="Endorsement by the Employer (In case of in-service candidates)">
            <template #intro>
                <div class="border-bottom pb-3 mb-2">
                    <p class="small text-muted mb-2">
                        Applicants who are currently employed should refer to this sample format when obtaining the employer endorsement
                        from their Head of Department, employer, or other competent authority, as applicable.
                    </p>
                    <div v-if="sampleUrl && !sampleUnavailable" class="d-flex flex-wrap align-items-center gap-2">
                        <a :href="sampleUrl" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Download Sample Employer Endorsement Letter (PDF)
                            <span class="visually-hidden">(opens in a new tab)</span>
                        </a>
                        <a :href="sampleUrl" download="Sample_endorsement.pdf" class="btn btn-sm btn-link"><i class="bi bi-download me-1" aria-hidden="true"></i>Save a copy</a>
                    </div>
                    <div v-else class="alert alert-warning small py-2 mb-0" role="status">
                        <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>The sample endorsement letter is temporarily unavailable. Please try again later or contact the Recruitment Section.
                    </div>
                </div>
            </template>
        </StepUploads>
    </div>
</template>
