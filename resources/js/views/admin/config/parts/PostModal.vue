<script setup lang="ts">
/** Create or edit one post: details, category-wise vacancies, eligibility criteria and overrides. */
import { computed, reactive } from 'vue';
import { api } from '@/api/client';
import { useForm } from '@/composables/useForm';
import BaseModal from '@/components/BaseModal.vue';
import FieldError from '@/components/FieldError.vue';
import FeeScheduleEditor from './FeeScheduleEditor.vue';
import {
    CRITERION_TYPES, ENDORSEMENT_MODES, QUALIFICATION_LEVELS, cleanFee, move, rowErrors,
    type Criterion, type FeeSchedule, type Post, type Vacancy,
} from './types';

const props = defineProps<{ advertisementId: number; post: Post | null; nextSort: number }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'saved'): void }>();
const form = useForm();
const p = props.post;

const f = reactive({
    code: p?.code ?? '', name: p?.name ?? '', department: p?.department ?? '', group_classification: p?.group_classification ?? '',
    pay_level: p?.pay_level ?? '', pay_scale: p?.pay_scale ?? '', total_vacancies: p?.total_vacancies ?? 1 as number | string,
    age_min: p?.age_min ?? '' as number | string, age_max: p?.age_max ?? '' as number | string,
    age_cutoff_date: p?.age_cutoff_date?.slice(0, 10) ?? '', age_relaxation: p?.age_relaxation ?? '',
    essential_qualifications: p?.essential_qualifications ?? '', desirable_qualifications: p?.desirable_qualifications ?? '',
    experience_requirements: p?.experience_requirements ?? '', other_conditions: p?.other_conditions ?? '',
    fee_schedule: p?.fee_schedule ?? null as FeeSchedule | null, endorsement_mode: p?.endorsement_mode ?? '' as string,
    status: p?.status ?? 'open', sort_order: p?.sort_order ?? props.nextSort as number | string,
});
const vacancies = reactive<Vacancy[]>((p?.vacancies ?? []).map((v) => ({ category: v.category, count: v.count, remarks: v.remarks })));
const criteria = reactive<Criterion[]>((p?.eligibility_criteria ?? []).map((c) => ({
    type: c.type, label: c.label, value: c.value, qualification_level: c.qualification_level, is_essential: c.is_essential, description: c.description,
})));
const vacancySum = computed(() => vacancies.reduce((t, v) => t + (Number(v.count) || 0), 0));
const e = form.errors;

const valueHint: Record<string, string> = {
    min_percentage: 'Percentage, e.g. 55', min_experience_months: 'Months, e.g. 36', max_age: 'Years, e.g. 35', qualification_level: 'Optional', custom: 'Optional',
};

function addCriterion() { criteria.push({ type: 'min_percentage', label: '', value: '', qualification_level: null, is_essential: true, description: null }); }

const nul = (v: unknown) => (v === '' || v === undefined ? null : v);

async function save() {
    const payload = {
        ...Object.fromEntries(Object.entries(f).map(([k, v]) => [k, nul(v)])),
        fee_schedule: cleanFee(f.fee_schedule),
        endorsement_mode: f.endorsement_mode || null,
        vacancies: vacancies.map((v) => ({ category: v.category.trim().toUpperCase(), count: Number(v.count), remarks: v.remarks || null })),
        criteria: criteria.map((c) => ({ ...c, value: c.value || null, qualification_level: c.qualification_level || null, description: c.description || null })),
    };
    const url = `/admin/advertisements/${props.advertisementId}/posts`;
    if (f.age_max !== '' && f.age_min === '') {
        // The server compares age_max with age_min and rejects a maximum without a minimum.
        form.clear();
        form.errors.age_min = ['Enter a minimum age as well (e.g. 18) when a maximum age is given.'];
        form.message.value = 'Please correct the highlighted fields.';
        return;
    }
    const res = await form.run(() => (p ? api.put(`${url}/${p.id}`, payload) : api.post(url, payload)), p ? 'Post saved.' : 'Post added.');
    if (res) emit('saved');
}
</script>

<template>
    <BaseModal :title="p ? `Edit post ${p.code}` : 'Add post'" size="xl" @close="emit('close')">
        <div v-if="form.message.value && Object.keys(e).length" class="alert alert-danger small">Please correct the highlighted fields.</div>
        <div class="row g-3">
            <div class="col-md-2">
                <label class="form-label required" for="p-code">Post code</label>
                <input id="p-code" v-model="f.code" class="form-control" maxlength="30" :class="{ 'is-invalid': e.code }">
                <FieldError :errors="e" name="code" />
            </div>
            <div class="col-md-5">
                <label class="form-label required" for="p-name">Name of the post</label>
                <input id="p-name" v-model="f.name" class="form-control" maxlength="200" :class="{ 'is-invalid': e.name }">
                <FieldError :errors="e" name="name" />
            </div>
            <div class="col-md-3">
                <label class="form-label" for="p-dept">Department</label>
                <input id="p-dept" v-model="f.department" class="form-control" maxlength="200" :class="{ 'is-invalid': e.department }">
                <FieldError :errors="e" name="department" />
            </div>
            <div class="col-md-2">
                <label class="form-label required" for="p-status">Status</label>
                <select id="p-status" v-model="f.status" class="form-select">
                    <option value="open">Open</option><option value="closed">Closed</option>
                </select>
                <FieldError :errors="e" name="status" />
            </div>
            <div class="col-md-2">
                <label class="form-label" for="p-group">Group</label>
                <input id="p-group" v-model="f.group_classification" class="form-control" maxlength="30" placeholder="Group B" :class="{ 'is-invalid': e.group_classification }">
                <FieldError :errors="e" name="group_classification" />
            </div>
            <div class="col-md-2">
                <label class="form-label" for="p-level">Pay level</label>
                <input id="p-level" v-model="f.pay_level" class="form-control" maxlength="50" :class="{ 'is-invalid': e.pay_level }">
                <FieldError :errors="e" name="pay_level" />
            </div>
            <div class="col-md-4">
                <label class="form-label" for="p-scale">Pay scale</label>
                <input id="p-scale" v-model="f.pay_scale" class="form-control" maxlength="200" :class="{ 'is-invalid': e.pay_scale }">
                <FieldError :errors="e" name="pay_scale" />
            </div>
            <div class="col-md-2">
                <label class="form-label required" for="p-vac">Total vacancies</label>
                <input id="p-vac" v-model="f.total_vacancies" type="number" min="0" class="form-control" :class="{ 'is-invalid': e.total_vacancies }">
                <FieldError :errors="e" name="total_vacancies" />
            </div>
            <div class="col-md-2">
                <label class="form-label" for="p-sort">Display order</label>
                <input id="p-sort" v-model="f.sort_order" type="number" min="0" class="form-control" :class="{ 'is-invalid': e.sort_order }">
                <FieldError :errors="e" name="sort_order" />
            </div>
        </div>

        <h6 class="mt-4">Category-wise vacancies</h6>
        <table class="table table-sm mb-1">
            <thead class="table-light"><tr><th style="width: 20%">Category</th><th style="width: 15%">Posts</th><th>Remarks</th><th></th></tr></thead>
            <tbody>
                <tr v-for="(v, i) in vacancies" :key="i">
                    <td>
                        <input v-model="v.category" class="form-control form-control-sm text-uppercase" maxlength="20" list="vac-cats" :class="{ 'is-invalid': e[`vacancies.${i}.category`] }">
                        <FieldError :errors="rowErrors(e, `vacancies.${i}.`)" name="category" />
                    </td>
                    <td>
                        <input v-model="v.count" type="number" min="0" class="form-control form-control-sm" :class="{ 'is-invalid': e[`vacancies.${i}.count`] }">
                        <FieldError :errors="rowErrors(e, `vacancies.${i}.`)" name="count" />
                    </td>
                    <td><input v-model="v.remarks" class="form-control form-control-sm" maxlength="255" placeholder="e.g. 1 reserved for PwBD (OH)"></td>
                    <td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger" @click="vacancies.splice(i, 1)"><i class="bi bi-x-lg"></i><span class="visually-hidden">Remove</span></button></td>
                </tr>
            </tbody>
        </table>
        <datalist id="vac-cats"><option v-for="c in ['UR', 'SC', 'ST', 'OBC', 'EWS', 'PWD', 'ESM']" :key="c" :value="c" /></datalist>
        <div class="d-flex align-items-center gap-3">
            <button type="button" class="btn btn-sm btn-outline-primary" @click="vacancies.push({ category: '', count: 1, remarks: null })"><i class="bi bi-plus"></i> Add category</button>
            <small v-if="vacancies.length" :class="vacancySum === Number(f.total_vacancies) ? 'text-muted' : 'text-warning'">
                Category total {{ vacancySum }} of {{ f.total_vacancies }}<span v-if="vacancySum !== Number(f.total_vacancies)"> — does not match the total</span>
            </small>
        </div>

        <h6 class="mt-4">Eligibility (as published)</h6>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label" for="p-amin">Minimum age</label>
                <input id="p-amin" v-model="f.age_min" type="number" min="14" max="70" class="form-control" :class="{ 'is-invalid': e.age_min }">
                <FieldError :errors="e" name="age_min" />
            </div>
            <div class="col-md-3">
                <label class="form-label" for="p-amax">Maximum age</label>
                <input id="p-amax" v-model="f.age_max" type="number" min="14" max="70" class="form-control" :class="{ 'is-invalid': e.age_max }">
                <FieldError :errors="e" name="age_max" />
            </div>
            <div class="col-md-3">
                <label class="form-label" for="p-acut">Age reckoned on</label>
                <input id="p-acut" v-model="f.age_cutoff_date" type="date" class="form-control" :class="{ 'is-invalid': e.age_cutoff_date }">
                <FieldError :errors="e" name="age_cutoff_date" />
            </div>
            <div class="col-md-3">
                <label class="form-label" for="p-arel">Age relaxation</label>
                <input id="p-arel" v-model="f.age_relaxation" class="form-control" :class="{ 'is-invalid': e.age_relaxation }">
                <FieldError :errors="e" name="age_relaxation" />
            </div>
            <div class="col-md-6">
                <label class="form-label" for="p-ess">Essential qualifications</label>
                <textarea id="p-ess" v-model="f.essential_qualifications" rows="3" class="form-control" :class="{ 'is-invalid': e.essential_qualifications }"></textarea>
                <FieldError :errors="e" name="essential_qualifications" />
            </div>
            <div class="col-md-6">
                <label class="form-label" for="p-des">Desirable qualifications</label>
                <textarea id="p-des" v-model="f.desirable_qualifications" rows="3" class="form-control" :class="{ 'is-invalid': e.desirable_qualifications }"></textarea>
                <FieldError :errors="e" name="desirable_qualifications" />
            </div>
            <div class="col-md-6">
                <label class="form-label" for="p-exp">Experience requirements</label>
                <textarea id="p-exp" v-model="f.experience_requirements" rows="3" class="form-control" :class="{ 'is-invalid': e.experience_requirements }"></textarea>
                <FieldError :errors="e" name="experience_requirements" />
            </div>
            <div class="col-md-6">
                <label class="form-label" for="p-oth">Other conditions</label>
                <textarea id="p-oth" v-model="f.other_conditions" rows="3" class="form-control" :class="{ 'is-invalid': e.other_conditions }"></textarea>
                <FieldError :errors="e" name="other_conditions" />
            </div>
        </div>

        <h6 class="mt-4">Eligibility criteria <small class="text-muted fw-normal">— structured rules shown to scrutiny officers as an aid; they never reject a candidate automatically</small></h6>
        <div v-for="(c, i) in criteria" :key="i" class="border rounded p-2 mb-2 bg-light">
            <div class="row g-2 align-items-start">
                <div class="col-md-3">
                    <label class="form-label small mb-0" :for="`crit-type-${i}`">Type</label>
                    <select :id="`crit-type-${i}`" v-model="c.type" class="form-select form-select-sm" :class="{ 'is-invalid': e[`criteria.${i}.type`] }">
                        <option v-for="(label, key) in CRITERION_TYPES" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <FieldError :errors="rowErrors(e, `criteria.${i}.`)" name="type" />
                </div>
                <div class="col-md-4">
                    <label class="form-label small mb-0 required" :for="`crit-label-${i}`">Label</label>
                    <input :id="`crit-label-${i}`" v-model="c.label" class="form-control form-control-sm" maxlength="300" :class="{ 'is-invalid': e[`criteria.${i}.label`] }">
                    <FieldError :errors="rowErrors(e, `criteria.${i}.`)" name="label" />
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0" :for="`crit-value-${i}`">Value</label>
                    <input :id="`crit-value-${i}`" v-model="c.value" class="form-control form-control-sm" maxlength="100" :placeholder="valueHint[c.type]" :class="{ 'is-invalid': e[`criteria.${i}.value`] }">
                    <FieldError :errors="rowErrors(e, `criteria.${i}.`)" name="value" />
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-0" :for="`crit-level-${i}`">Qualification level</label>
                    <select :id="`crit-level-${i}`" v-model="c.qualification_level" class="form-select form-select-sm" :class="{ 'is-invalid': e[`criteria.${i}.qualification_level`] }">
                        <option :value="null">— Any —</option>
                        <option v-for="(label, key) in QUALIFICATION_LEVELS" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <FieldError :errors="rowErrors(e, `criteria.${i}.`)" name="qualification_level" />
                </div>
                <div class="col-md-7">
                    <input v-model="c.description" class="form-control form-control-sm" maxlength="2000" placeholder="Notes for scrutiny (optional)">
                </div>
                <div class="col-md-5 d-flex align-items-center gap-3 justify-content-end">
                    <div class="btn-group btn-group-sm" role="group" :aria-label="`Criterion ${i + 1} weight`">
                        <input :id="`crit-ess-${i}`" v-model="c.is_essential" type="radio" class="btn-check" :value="true">
                        <label class="btn btn-outline-primary" :for="`crit-ess-${i}`">Essential</label>
                        <input :id="`crit-des-${i}`" v-model="c.is_essential" type="radio" class="btn-check" :value="false">
                        <label class="btn btn-outline-primary" :for="`crit-des-${i}`">Desirable</label>
                    </div>
                    <button type="button" class="btn btn-sm btn-link p-0" :disabled="i === 0" title="Move up" @click="move(criteria, i, -1)"><i class="bi bi-arrow-up"></i></button>
                    <button type="button" class="btn btn-sm btn-link p-0" :disabled="i === criteria.length - 1" title="Move down" @click="move(criteria, i, 1)"><i class="bi bi-arrow-down"></i></button>
                    <button type="button" class="btn btn-sm btn-link text-danger p-0" title="Remove" @click="criteria.splice(i, 1)"><i class="bi bi-trash"></i></button>
                </div>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" @click="addCriterion"><i class="bi bi-plus"></i> Add criterion</button>

        <h6 class="mt-4">Post-level overrides</h6>
        <div class="row g-3">
            <div class="col-md-8">
                <FeeScheduleEditor v-model="f.fee_schedule" optional :errors="e" prefix="fee_schedule" />
            </div>
            <div class="col-md-4">
                <label class="form-label" for="p-end">Employer endorsement</label>
                <select id="p-end" v-model="f.endorsement_mode" class="form-select" :class="{ 'is-invalid': e.endorsement_mode }">
                    <option value="">Same as advertisement</option>
                    <option v-for="(label, key) in ENDORSEMENT_MODES" :key="key" :value="key">{{ label }}</option>
                </select>
                <FieldError :errors="e" name="endorsement_mode" />
            </div>
        </div>

        <template #footer>
            <button type="button" class="btn btn-outline-secondary" @click="emit('close')">Cancel</button>
            <button type="button" class="btn btn-primary" :disabled="form.busy.value" @click="save">
                <span v-if="form.busy.value" class="spinner-border spinner-border-sm me-1"></span>{{ p ? 'Save post' : 'Add post' }}
            </button>
        </template>
    </BaseModal>
</template>
