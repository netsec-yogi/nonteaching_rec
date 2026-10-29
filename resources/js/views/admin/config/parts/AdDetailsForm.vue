<script setup lang="ts">
/** Tab 1 of the advertisement editor: every advertisement-level rule, created or updated in one request. */
import { onMounted, reactive, ref } from 'vue';
import { api } from '@/api/client';
import { useForm } from '@/composables/useForm';
import FieldError from '@/components/FieldError.vue';
import FeeScheduleEditor from './FeeScheduleEditor.vue';
import { ENDORSEMENT_MODES, IDENTITY_REQUIREMENTS, PAYMENT_METHODS, cleanFee, type AdvertisementDetail, type Cycle, type FeeSchedule, type FormTemplate } from './types';

const props = defineProps<{ ad: AdvertisementDetail | null; cycles: Cycle[]; refereeForms: FormTemplate[]; evaluationForms: FormTemplate[] }>();
const emit = defineEmits<{ (e: 'saved', ad: AdvertisementDetail): void }>();
const form = useForm();
const locked = !!props.ad && props.ad.status === 'archived';
const hasSubmissions = !!props.ad && Object.entries(props.ad.application_counts ?? {}).some(([s, n]) => s !== 'draft' && n > 0);

const a = props.ad;
const f = reactive({
    recruitment_cycle_id: a?.recruitment_cycle_id ?? (props.cycles.find((c) => c.is_active)?.id ?? null) as number | null,
    number: a?.number ?? '',
    title: a?.title ?? '',
    publication_date: a?.publication_date ?? '',
    department: a?.department ?? '',
    summary: a?.summary ?? '',
    instructions: a?.instructions ?? '',
    reservation_details: a?.reservation_details ?? '',
    age_relaxation_rules: a?.age_relaxation_rules ?? '',
    fee_rules_text: a?.fee_rules_text ?? '',
    payment_instructions: a?.payment_instructions ?? '',
    opens_at: a?.opens_at ?? '',
    closes_at: a?.closes_at ?? '',
    reference_prefix: a?.reference_prefix ?? '',
    fee_schedule: (a?.fee_schedule ?? { default: 0, exemptions: {} }) as FeeSchedule | null,
    payment_methods: [...(a?.payment_methods ?? ['neft'])],
    referees_required: a?.referees_required ?? 3,
    referee_recommendations_mandatory: a?.referee_recommendations_mandatory ?? false,
    referee_deadline_days: a?.referee_deadline_days ?? 14,
    referee_final_reminder_days_before: a?.referee_final_reminder_days_before ?? 2 as number | string | null,
    referee_max_reminders: a?.referee_max_reminders ?? 3,
    referee_extra_verification: a?.referee_extra_verification ?? false,
    referee_form_template_id: a?.referee_form_template_id ?? (props.refereeForms.find((t) => t.is_active)?.id ?? null) as number | null,
    endorsement_mode: a?.endorsement_mode ?? 'required_for_in_service',
    identity_number_requirement: a?.identity_number_requirement ?? 'optional',
    endorsement_instructions: a?.endorsement_instructions ?? '',
    committee_evaluation_mode: a?.committee_evaluation_mode ?? 'individual',
    evaluation_form_template_id: a?.evaluation_form_template_id ?? (props.evaluationForms.find((t) => t.is_active)?.id ?? null) as number | null,
    allow_withdrawal: a?.allow_withdrawal ?? true,
    allow_clarification_uploads: a?.allow_clarification_uploads ?? true,
    retention_years: (a?.retention_years ?? null) as number | string | null,
    contact_email: a?.contact_email ?? '',
    contact_phone: a?.contact_phone ?? '',
});
const defaultRetention = ref<number | null>(null);
onMounted(async () => {
    try {
        const { data } = await api.get<{ referee_deadline_days: number; retention_years: number }>('/admin/config-defaults');
        defaultRetention.value = data.retention_years;
        if (!a) f.referee_deadline_days = data.referee_deadline_days;
    } catch { /* keep built-in values */ }
});
const reminderText = ref((a?.referee_reminder_days ?? [5, 10]).join(', '));

const n = (v: unknown) => (v === '' || v === null || v === undefined ? null : Number(v));
const s = (v: string | null) => (v === '' ? null : v);

async function save() {
    const reminders = reminderText.value.split(/[,\s]+/).filter(Boolean).map(Number);
    const payload = {
        ...f,
        publication_date: s(f.publication_date), department: s(f.department), summary: s(f.summary), instructions: s(f.instructions),
        reservation_details: s(f.reservation_details), age_relaxation_rules: s(f.age_relaxation_rules), fee_rules_text: s(f.fee_rules_text),
        payment_instructions: s(f.payment_instructions), endorsement_instructions: s(f.endorsement_instructions),
        contact_email: s(f.contact_email), contact_phone: s(f.contact_phone),
        fee_schedule: cleanFee(f.fee_schedule),
        referee_reminder_days: reminders,
        referee_final_reminder_days_before: n(f.referee_final_reminder_days_before),
        retention_years: n(f.retention_years),
    };
    const res = await form.run(() => (a
        ? api.put(`/admin/advertisements/${a.id}`, payload)
        : api.post('/admin/advertisements', payload)), a ? 'Advertisement saved.' : 'Draft advertisement created.');
    if (res) emit('saved', res.data as AdvertisementDetail);
}

const e = form.errors;
</script>

<template>
    <form novalidate @submit.prevent="save">
        <div v-if="locked" class="alert alert-secondary">This advertisement is archived and can no longer be edited.</div>
        <div v-else-if="hasSubmissions" class="alert alert-warning small mb-3">
            <i class="bi bi-lock me-1"></i>Applications have been submitted. The advertisement number, reference prefix and number of referees can no longer be changed; other changes apply to candidates going forward.
        </div>
        <div v-if="form.message.value && Object.keys(e).length" class="alert alert-danger small">Please correct the highlighted fields. {{ form.message.value }}</div>

        <fieldset :disabled="locked">
            <div class="card section-card mb-3">
                <div class="card-header">Advertisement</div>
                <div class="card-body row g-3">
                    <div class="col-md-4">
                        <label class="form-label required" for="ad-cycle">Recruitment cycle</label>
                        <select id="ad-cycle" v-model="f.recruitment_cycle_id" class="form-select" :class="{ 'is-invalid': e.recruitment_cycle_id }">
                            <option :value="null" disabled>Choose…</option>
                            <option v-for="c in cycles" :key="c.id" :value="c.id">{{ c.name }} ({{ c.year }}){{ c.is_active ? '' : ' — inactive' }}</option>
                        </select>
                        <FieldError :errors="e" name="recruitment_cycle_id" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required" for="ad-number">Advertisement number</label>
                        <input id="ad-number" v-model="f.number" class="form-control" maxlength="60" placeholder="IIITM/NT/2026/04" :class="{ 'is-invalid': e.number }">
                        <FieldError :errors="e" name="number" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="ad-pub">Publication date</label>
                        <input id="ad-pub" v-model="f.publication_date" type="date" class="form-control" :class="{ 'is-invalid': e.publication_date }">
                        <FieldError :errors="e" name="publication_date" />
                    </div>
                    <div class="col-md-8">
                        <label class="form-label required" for="ad-title">Title</label>
                        <input id="ad-title" v-model="f.title" class="form-control" maxlength="300" :class="{ 'is-invalid': e.title }">
                        <FieldError :errors="e" name="title" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="ad-dept">Department / section</label>
                        <input id="ad-dept" v-model="f.department" class="form-control" maxlength="200" :class="{ 'is-invalid': e.department }">
                        <FieldError :errors="e" name="department" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required" for="ad-open">Applications open</label>
                        <input id="ad-open" v-model="f.opens_at" type="datetime-local" class="form-control" :class="{ 'is-invalid': e.opens_at }">
                        <FieldError :errors="e" name="opens_at" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required" for="ad-close">Applications close</label>
                        <input id="ad-close" v-model="f.closes_at" type="datetime-local" class="form-control" :class="{ 'is-invalid': e.closes_at }">
                        <FieldError :errors="e" name="closes_at" />
                        <div class="form-text">Submissions are refused after this moment (server time, IST).</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required" for="ad-prefix">Reference number prefix</label>
                        <input id="ad-prefix" v-model="f.reference_prefix" class="form-control" maxlength="30" placeholder="IIITM/NT/26-04" :class="{ 'is-invalid': e.reference_prefix }">
                        <FieldError :errors="e" name="reference_prefix" />
                        <div class="form-text">Letters, digits, “/” and “-”. Submitted applications are numbered PREFIX/0001, …</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="ad-summary">Summary</label>
                        <textarea id="ad-summary" v-model="f.summary" rows="2" class="form-control" :class="{ 'is-invalid': e.summary }"></textarea>
                        <FieldError :errors="e" name="summary" />
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="ad-instr">General instructions to candidates</label>
                        <textarea id="ad-instr" v-model="f.instructions" rows="6" class="form-control" :class="{ 'is-invalid': e.instructions }"></textarea>
                        <FieldError :errors="e" name="instructions" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="ad-res">Reservation details</label>
                        <textarea id="ad-res" v-model="f.reservation_details" rows="4" class="form-control" :class="{ 'is-invalid': e.reservation_details }"></textarea>
                        <FieldError :errors="e" name="reservation_details" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="ad-age">Age relaxation rules</label>
                        <textarea id="ad-age" v-model="f.age_relaxation_rules" rows="4" class="form-control" :class="{ 'is-invalid': e.age_relaxation_rules }"></textarea>
                        <FieldError :errors="e" name="age_relaxation_rules" />
                    </div>
                </div>
            </div>

            <div class="card section-card mb-3">
                <div class="card-header">Application fee and payment</div>
                <div class="card-body row g-3">
                    <div class="col-12">
                        <FeeScheduleEditor v-model="f.fee_schedule" :errors="e" prefix="fee_schedule" />
                        <div class="form-text">Posts may override this schedule individually.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="ad-feetext">Fee rules (as shown to candidates)</label>
                        <textarea id="ad-feetext" v-model="f.fee_rules_text" rows="3" class="form-control" :class="{ 'is-invalid': e.fee_rules_text }"></textarea>
                        <FieldError :errors="e" name="fee_rules_text" />
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="ad-payinstr">Payment instructions</label>
                        <textarea id="ad-payinstr" v-model="f.payment_instructions" rows="3" class="form-control" :class="{ 'is-invalid': e.payment_instructions }"></textarea>
                        <FieldError :errors="e" name="payment_instructions" />
                    </div>
                    <div class="col-12">
                        <div class="form-label required">Accepted payment methods</div>
                        <div v-for="(label, key) in PAYMENT_METHODS" :key="key" class="form-check form-check-inline">
                            <input :id="`pm-${key}`" v-model="f.payment_methods" class="form-check-input" type="checkbox" :value="key">
                            <label class="form-check-label" :for="`pm-${key}`">{{ label }}</label>
                        </div>
                        <FieldError :errors="e" name="payment_methods" />
                    </div>
                </div>
            </div>

            <div class="card section-card mb-3">
                <div class="card-header">Referees</div>
                <div class="card-body row g-3">
                    <div class="col-md-3">
                        <label class="form-label required" for="ad-refn">Referees required</label>
                        <input id="ad-refn" v-model.number="f.referees_required" type="number" min="0" max="5" class="form-control" :class="{ 'is-invalid': e.referees_required }">
                        <FieldError :errors="e" name="referees_required" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label required" for="ad-refdl">Response deadline (days)</label>
                        <input id="ad-refdl" v-model.number="f.referee_deadline_days" type="number" min="1" max="120" class="form-control" :class="{ 'is-invalid': e.referee_deadline_days }">
                        <FieldError :errors="e" name="referee_deadline_days" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="ad-refrem">Reminders after (days)</label>
                        <input id="ad-refrem" v-model="reminderText" class="form-control" placeholder="5, 10" :class="{ 'is-invalid': Object.keys(e).some((k) => k.startsWith('referee_reminder_days')) }">
                        <div v-for="k in Object.keys(e).filter((k) => k.startsWith('referee_reminder_days'))" :key="k" class="invalid-feedback d-block">{{ e[k][0] }}</div>
                        <div class="form-text">Comma-separated days after invitation.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="ad-reffinal">Final reminder (days before deadline)</label>
                        <input id="ad-reffinal" v-model="f.referee_final_reminder_days_before" type="number" min="1" max="60" class="form-control" :class="{ 'is-invalid': e.referee_final_reminder_days_before }">
                        <FieldError :errors="e" name="referee_final_reminder_days_before" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label required" for="ad-refmax">Maximum reminders</label>
                        <input id="ad-refmax" v-model.number="f.referee_max_reminders" type="number" min="0" max="10" class="form-control" :class="{ 'is-invalid': e.referee_max_reminders }">
                        <FieldError :errors="e" name="referee_max_reminders" />
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="ad-refform">Recommendation form</label>
                        <select id="ad-refform" v-model="f.referee_form_template_id" class="form-select" :class="{ 'is-invalid': e.referee_form_template_id }">
                            <option :value="null">— None —</option>
                            <option v-for="t in refereeForms" :key="t.id" :value="t.id">{{ t.name }}{{ t.is_active ? '' : ' (inactive)' }}</option>
                        </select>
                        <FieldError :errors="e" name="referee_form_template_id" />
                    </div>
                    <div class="col-md-4 d-flex flex-column justify-content-end">
                        <div class="form-check">
                            <input id="ad-refmand" v-model="f.referee_recommendations_mandatory" class="form-check-input" type="checkbox">
                            <label class="form-check-label" for="ad-refmand">Recommendations mandatory before committee</label>
                        </div>
                        <div class="form-check">
                            <input id="ad-refver" v-model="f.referee_extra_verification" class="form-check-input" type="checkbox">
                            <label class="form-check-label" for="ad-refver">Referees confirm a one-time email code</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card section-card mb-3">
                <div class="card-header">Employer endorsement and Screening Committee</div>
                <div class="card-body row g-3">
                    <div class="col-md-4">
                        <label class="form-label required" for="ad-endmode">Employer endorsement</label>
                        <select id="ad-endmode" v-model="f.endorsement_mode" class="form-select" :class="{ 'is-invalid': e.endorsement_mode }">
                            <option v-for="(label, key) in ENDORSEMENT_MODES" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <FieldError :errors="e" name="endorsement_mode" />
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="ad-endinstr">Endorsement instructions</label>
                        <textarea id="ad-endinstr" v-model="f.endorsement_instructions" rows="2" class="form-control" :class="{ 'is-invalid': e.endorsement_instructions }"></textarea>
                        <FieldError :errors="e" name="endorsement_instructions" />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required" for="ad-idreq">PAN / Aadhaar number</label>
                        <select id="ad-idreq" v-model="f.identity_number_requirement" class="form-select" :class="{ 'is-invalid': e.identity_number_requirement }" aria-describedby="ad-idreq-help">
                            <option v-for="(label, key) in IDENTITY_REQUIREMENTS" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <FieldError :errors="e" name="identity_number_requirement" />
                        <div id="ad-idreq-help" class="form-text">Both are optional unless required here for an approved recruitment purpose. Numbers are format-checked only.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required" for="ad-cmode">Committee evaluation mode</label>
                        <select id="ad-cmode" v-model="f.committee_evaluation_mode" class="form-select" :class="{ 'is-invalid': e.committee_evaluation_mode }">
                            <option value="individual">Individual — each member evaluates, chair records the decision</option>
                            <option value="collective">Collective — one evaluation for the committee</option>
                        </select>
                        <FieldError :errors="e" name="committee_evaluation_mode" />
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="ad-evform">Evaluation form</label>
                        <select id="ad-evform" v-model="f.evaluation_form_template_id" class="form-select" :class="{ 'is-invalid': e.evaluation_form_template_id }">
                            <option :value="null">— None —</option>
                            <option v-for="t in evaluationForms" :key="t.id" :value="t.id">{{ t.name }}{{ t.is_active ? '' : ' (inactive)' }}</option>
                        </select>
                        <FieldError :errors="e" name="evaluation_form_template_id" />
                    </div>
                </div>
            </div>

            <div class="card section-card mb-3">
                <div class="card-header">Candidate options, retention and contact</div>
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <div class="form-check">
                            <input id="ad-wd" v-model="f.allow_withdrawal" class="form-check-input" type="checkbox">
                            <label class="form-check-label" for="ad-wd">Candidates may withdraw a submitted application</label>
                        </div>
                        <div class="form-check">
                            <input id="ad-clar" v-model="f.allow_clarification_uploads" class="form-check-input" type="checkbox">
                            <label class="form-check-label" for="ad-clar">Candidates may upload documents in reply to clarification requests</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="ad-ret">Retention (years)</label>
                        <input id="ad-ret" v-model="f.retention_years" type="number" min="1" max="50" class="form-control" :class="{ 'is-invalid': e.retention_years }" :placeholder="defaultRetention ? `System default (${defaultRetention})` : 'System default'">
                        <FieldError :errors="e" name="retention_years" />
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="ad-cemail">Contact email</label>
                        <input id="ad-cemail" v-model="f.contact_email" type="email" class="form-control" :class="{ 'is-invalid': e.contact_email }">
                        <FieldError :errors="e" name="contact_email" />
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="ad-cphone">Contact phone</label>
                        <input id="ad-cphone" v-model="f.contact_phone" class="form-control" maxlength="30" :class="{ 'is-invalid': e.contact_phone }">
                        <FieldError :errors="e" name="contact_phone" />
                    </div>
                </div>
            </div>
            <FieldError :errors="e" name="status" />
        </fieldset>

        <div v-if="!locked" class="sticky-actions d-flex gap-2 justify-content-end">
            <button type="submit" class="btn btn-primary" :disabled="form.busy.value">
                <span v-if="form.busy.value" class="spinner-border spinner-border-sm me-1"></span>{{ a ? 'Save details' : 'Create draft advertisement' }}
            </button>
        </div>
    </form>
</template>
