<script setup lang="ts">
/** Sections 5–16 — Personal information and addresses. */
import { computed, watch } from 'vue';
import FieldError from '@/components/FieldError.vue';
import { ageBetween, formatAge, formatDmy, isoToday } from '@/utils/age';
import { useWizard } from '../context';
import StepUploads from '../StepUploads.vue';
import YesNo from '../YesNo.vue';
import AddressFields from './AddressFields.vue';
import type { PersonalData } from '../types';

const props = defineProps<{ form: PersonalData }>();
const { payload, errors } = useWizard();
const opts = computed(() => payload.value.options);
const inv = (k: string) => ({ 'is-invalid': !!errors[k] });
const idNeed = computed(() => props.form.identity_number_requirement ?? 'optional');
const salutations = computed<string[]>(() => (opts.value as { salutations?: string[] }).salutations ?? ['Dr', 'Mr', 'Mrs', 'Ms']);

function onName(e: Event) {
    const el = e.target as HTMLInputElement;
    const start = el.selectionStart;
    const upper = el.value.toUpperCase();
    props.form.full_name = upper;
    if (el.value !== upper) {
        el.value = upper;
        if (start !== null) el.setSelectionRange(start, start);
    }
}

function copyCorrespondence() {
    Object.assign(props.form.permanent, { ...props.form.correspondence });
}

function onSame(e: Event) {
    props.form.permanent_same_as_correspondence = (e.target as HTMLInputElement).checked;
    if (props.form.permanent_same_as_correspondence) copyCorrespondence();
}

watch(() => props.form.correspondence, () => {
    if (props.form.permanent_same_as_correspondence) copyCorrespondence();
}, { deep: true });

const hideUploads = computed(() => [
    ...(props.form.is_pwd ? [] : ['disability_certificate']),
    ...(props.form.category && props.form.category !== 'UR' ? [] : ['category_certificate']),
]);
const maxDob = (() => { const d = new Date(); d.setFullYear(d.getFullYear() - 14); return d.toISOString().slice(0, 10); })();

// Age shown as the date is typed; display only (the server recalculates it on the reference date).
const today = isoToday();
const dobInFuture = computed(() => /^\d{4}-\d{2}-\d{2}$/.test(props.form.date_of_birth ?? '') && props.form.date_of_birth! > today);
const age = computed(() => (dobInFuture.value ? null : ageBetween(props.form.date_of_birth, props.form.age_reference?.date)));
const ageBasis = computed(() => ({
    advertisement: 'reckoning date in the advertisement',
    application_date: 'date of application',
    today: 'today, until you submit',
})[props.form.age_reference?.basis ?? 'today']);
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header">Sections 5–16 · Personal information</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-3 col-md-2">
                        <label class="form-label" for="p-salutation">Title</label>
                        <select id="p-salutation" v-model="form.salutation" class="form-select" :class="inv('salutation')">
                            <option :value="null">—</option>
                            <option v-for="s in salutations" :key="s" :value="s">{{ s }}</option>
                        </select>
                        <FieldError :errors="errors" name="salutation" />
                    </div>
                    <div class="col-sm-9 col-md-10">
                        <label class="form-label required" for="p-name">5. Name in full (in CAPITAL LETTERS)</label>
                        <input id="p-name" :value="form.full_name" class="form-control text-uppercase" :class="inv('full_name')" maxlength="200" autocomplete="name" @input="onName">
                        <div class="form-text">As it appears in your Class 10 certificate.</div>
                        <FieldError :errors="errors" name="full_name" />
                    </div>
                    <div class="col-md-4 col-xl-2">
                        <label class="form-label required" for="p-reltype">6. Father's / Husband's name</label>
                        <select id="p-reltype" v-model="form.relation_type" class="form-select" :class="inv('relation_type')">
                            <option :value="null" disabled>Select…</option>
                            <option value="father">Father's name</option>
                            <option value="husband">Husband's name</option>
                        </select>
                        <FieldError :errors="errors" name="relation_type" />
                    </div>
                    <div class="col-md-8 col-xl-3">
                        <label class="form-label required" for="p-relname">{{ form.relation_type === 'husband' ? "Husband's" : "Father's" }} name</label>
                        <input id="p-relname" v-model="form.relation_name" class="form-control" :class="inv('relation_name')" maxlength="200">
                        <FieldError :errors="errors" name="relation_name" />
                    </div>
                    <div class="col-md-4 col-xl-2">
                        <label class="form-label required" for="p-dob">7. Date of birth</label>
                        <input id="p-dob" v-model="form.date_of_birth" type="date" class="form-control" :class="{ 'is-invalid': !!errors.date_of_birth || dobInFuture }" :max="maxDob">
                        <div v-if="dobInFuture" class="invalid-feedback d-block">The date of birth cannot be in the future.</div>
                        <FieldError v-else :errors="errors" name="date_of_birth" />
                    </div>
                    <div class="col-md-4 col-xl-2">
                        <label class="form-label" for="p-age">Age</label>
                        <input id="p-age" :value="age ? formatAge(age) : ''" class="form-control" readonly aria-describedby="p-age-ref">
                        <div id="p-age-ref" class="form-text">Age as on: {{ formatDmy(form.age_reference?.date) }} ({{ ageBasis }})</div>
                    </div>
                    <div class="col-md-4 col-xl-3">
                        <label class="form-label required" for="p-pob">Place of birth</label>
                        <input id="p-pob" v-model="form.place_of_birth" class="form-control" :class="inv('place_of_birth')" maxlength="150">
                        <FieldError :errors="errors" name="place_of_birth" />
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <label class="form-label required" for="p-mobile">8. Mobile number</label>
                        <input id="p-mobile" v-model.trim="form.mobile" type="tel" inputmode="tel" class="form-control" :class="inv('mobile')" maxlength="16" autocomplete="tel" placeholder="10-digit mobile number">
                        <FieldError :errors="errors" name="mobile" />
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <label class="form-label required" for="p-email">Email</label>
                        <input id="p-email" v-model.trim="form.email" type="email" class="form-control" :class="inv('email')" maxlength="255" autocomplete="email">
                        <FieldError :errors="errors" name="email" />
                    </div>
                    <div class="col-md-4 col-xl-2">
                        <label class="form-label required" for="p-nat">9. Nationality</label>
                        <input id="p-nat" v-model="form.nationality" class="form-control" :class="inv('nationality')" maxlength="60">
                        <FieldError :errors="errors" name="nationality" />
                    </div>
                    <div class="col-md-4 col-xl-2">
                        <label class="form-label required" for="p-gender">10. Gender</label>
                        <select id="p-gender" v-model="form.gender" class="form-select" :class="inv('gender')">
                            <option :value="null" disabled>Select…</option>
                            <option v-for="(label, v) in opts.genders" :key="v" :value="v">{{ label }}</option>
                        </select>
                        <FieldError :errors="errors" name="gender" />
                    </div>
                    <div class="col-md-4 col-xl-2">
                        <label class="form-label required" for="p-marital">11. Marital status</label>
                        <select id="p-marital" v-model="form.marital_status" class="form-select" :class="inv('marital_status')">
                            <option :value="null" disabled>Select…</option>
                            <option v-for="(label, v) in opts.marital_statuses" :key="v" :value="v">{{ label }}</option>
                        </select>
                        <FieldError :errors="errors" name="marital_status" />
                    </div>
                    <div class="col-md-6 col-xl-4">
                        <label class="form-label required" for="p-cat">12. Category</label>
                        <select id="p-cat" v-model="form.category" class="form-select" :class="inv('category')">
                            <option :value="null" disabled>Select…</option>
                            <option v-for="(label, v) in opts.categories" :key="v" :value="v">{{ label }}</option>
                        </select>
                        <div class="form-text">A category certificate in the prescribed format is required for SC / ST / OBC-NCL / EWS.</div>
                        <FieldError :errors="errors" name="category" />
                    </div>
                    <div class="col-md-6 col-xl-8">
                        <span class="form-label required d-block">13. Whether Physically Handicapped (Person with Disability)</span>
                        <YesNo v-model="form.is_pwd" name="is_pwd" :invalid="!!errors.is_pwd" yes-label="Yes" no-label="No" />
                        <FieldError :errors="errors" name="is_pwd" />
                    </div>
                    <template v-if="form.is_pwd">
                        <div class="col-md-8">
                            <label class="form-label required" for="p-dtype">Type of disability</label>
                            <input id="p-dtype" v-model="form.disability_type" class="form-control" :class="inv('disability_type')" maxlength="150">
                            <FieldError :errors="errors" name="disability_type" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="p-dpct">Percentage of disability</label>
                            <div class="input-group">
                                <input id="p-dpct" v-model.number="form.disability_percentage" type="number" min="1" max="100" class="form-control" :class="inv('disability_percentage')">
                                <span class="input-group-text">%</span>
                            </div>
                            <FieldError :errors="errors" name="disability_percentage" />
                        </div>
                    </template>
                    <div class="col-md-12 col-xl-6">
                        <label class="form-label required" for="p-rail">14. Nearest railway station</label>
                        <input id="p-rail" v-model="form.nearest_railway_station" class="form-control" :class="inv('nearest_railway_station')" maxlength="150">
                        <FieldError :errors="errors" name="nearest_railway_station" />
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <label class="form-label" :class="{ required: idNeed === 'pan' }" for="p-pan">PAN Card Number ({{ idNeed === 'pan' ? 'Required' : idNeed === 'pan_or_aadhaar' ? 'PAN or Aadhaar required' : 'Optional' }})</label>
                        <input id="p-pan" v-model.trim="form.pan_number" class="form-control font-monospace text-uppercase" :class="inv('pan_number')"
                            maxlength="10" autocomplete="off" spellcheck="false" placeholder="ABCDE1234F" aria-describedby="p-id-help"
                            @input="form.pan_number = (form.pan_number ?? '').toUpperCase()">
                        <FieldError :errors="errors" name="pan_number" />
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <label class="form-label" :class="{ required: idNeed === 'aadhaar' }" for="p-aadhaar">Aadhaar Card Number ({{ idNeed === 'aadhaar' ? 'Required' : idNeed === 'pan_or_aadhaar' ? 'PAN or Aadhaar required' : 'Optional' }})</label>
                        <input id="p-aadhaar" v-model.trim="form.aadhaar_number" class="form-control font-monospace" :class="inv('aadhaar_number')"
                            inputmode="numeric" maxlength="14" autocomplete="off" placeholder="12 digits" aria-describedby="p-id-help">
                        <FieldError :errors="errors" name="aadhaar_number" />
                    </div>
                    <div id="p-id-help" class="col-12 form-text mt-1">
                        <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Used only for this recruitment. Stored encrypted and shown masked
                        (only the last four characters) to staff; checked for format only, not verified with the Income Tax Department or UIDAI.
                    </div>
                </div>
            </div>
        </div>

        <div class="card section-card">
            <div class="card-header">15. Address for correspondence</div>
            <div class="card-body"><AddressFields :address="form.correspondence" prefix="correspondence" /></div>
        </div>

        <div class="card section-card">
            <div class="card-header">16. Permanent address</div>
            <div class="card-body">
                <div class="form-check" :class="{ 'mb-3': !form.permanent_same_as_correspondence }">
                    <input id="p-same" class="form-check-input" type="checkbox" :checked="form.permanent_same_as_correspondence" @change="onSame">
                    <label class="form-check-label" for="p-same">Same as the address for correspondence</label>
                </div>
                <AddressFields v-if="!form.permanent_same_as_correspondence" :address="form.permanent" prefix="permanent" />
            </div>
        </div>

        <StepUploads step="personal" :hide="hideUploads" title="Photograph, signature and documents" />
    </div>
</template>
