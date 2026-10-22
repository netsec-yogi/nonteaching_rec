<script setup lang="ts">
/** Section 19 — Employment details, most recent first. */
import { computed } from 'vue';
import FieldError from '@/components/FieldError.vue';
import { monthsToText } from '@/utils/format';
import { errorsUnder, monthsBetween, rowKey, today, useWizard } from '../context';
import RowUpload from '../RowUpload.vue';
import StepUploads from '../StepUploads.vue';
import YesNo from '../YesNo.vue';
import type { EmploymentData, EmploymentRow } from '../types';

const props = defineProps<{ form: EmploymentData }>();
const { payload, errors, readonly } = useWizard();
const types = computed(() => payload.value.options.employment_types);

const blank = (): EmploymentRow => ({
    id: null, organization: '', post_held: '', employment_type: null, from_date: null, to_date: null, is_current: false,
    pay_scale_level: '', basic_pay: null, gross_pay: null, nature_of_duties: '',
});

function setHasEmployment(v: boolean) {
    props.form.has_employment = v;
    if (v && !props.form.rows.length) props.form.rows.push(blank());
    if (!v) props.form.is_in_service = false;
}
function add() { props.form.rows.unshift(blank()); }
function remove(i: number) {
    const r = props.form.rows[i];
    if (r.id && !confirm('Remove this employment? Documents attached to it will be detached when you save.')) return;
    props.form.rows.splice(i, 1);
}
function setCurrent(row: EmploymentRow, e: Event) {
    row.is_current = (e.target as HTMLInputElement).checked;
    if (row.is_current) row.to_date = null;
}

const duration = (r: EmploymentRow) => monthsBetween(r.from_date, r.is_current ? null : r.to_date);
const total = computed(() => props.form.rows.reduce((sum, r) => sum + (duration(r) ?? 0), 0));
const k = (i: number, f: string) => `rows.${i}.${f}`;
const inv = (i: number, f: string) => ({ 'is-invalid': !!errors[k(i, f)] });
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header">19. Employment details (in reverse chronological order — present / most recent first)</div>
            <div class="card-body">
                <div class="row g-3 mb-2">
                    <div class="col-md-6">
                        <span class="form-label required d-block">Do you have any employment experience?</span>
                        <YesNo :model-value="form.has_employment" name="has_employment" :invalid="!!errors.has_employment" @update:model-value="setHasEmployment" />
                        <FieldError :errors="errors" name="has_employment" />
                    </div>
                    <div v-if="form.has_employment" class="col-md-6">
                        <span class="form-label required d-block">Are you presently in service (Government / PSU / Autonomous Body / Private)?</span>
                        <YesNo v-model="form.is_in_service" name="is_in_service" :invalid="!!errors.is_in_service" />
                        <div class="form-text">In-service candidates may need to apply through proper channel with the employer's endorsement (Section 32).</div>
                        <FieldError :errors="errors" name="is_in_service" />
                    </div>
                </div>

                <template v-if="form.has_employment">
                    <FieldError :errors="errors" name="rows" />
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <span class="small text-muted">Rows are arranged most-recent-first when you save. Total experience entered: <strong>{{ monthsToText(total) }}</strong></span>
                        <button v-if="!readonly" type="button" class="btn btn-outline-primary btn-sm" @click="add"><i class="bi bi-plus-lg me-1"></i>Add employment</button>
                    </div>

                    <div v-for="(row, i) in form.rows" :key="rowKey(row)" class="border rounded p-3 mb-3" :class="{ 'border-danger': errorsUnder(errors, `rows.${i}.`).length }">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge text-bg-secondary">{{ i + 1 }}</span>
                            <span class="fw-semibold flex-grow-1 text-truncate">{{ row.post_held || 'Post' }}<span v-if="row.organization">, {{ row.organization }}</span></span>
                            <span v-if="row.is_current" class="badge text-bg-success">Current</span>
                            <button v-if="!readonly" type="button" class="btn btn-sm btn-outline-danger" title="Remove" @click="remove(i)"><i class="bi bi-trash"></i><span class="visually-hidden">Remove</span></button>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label small required" :for="`e${i}-org`">Name of Department / Institute / Organisation</label>
                                <input :id="`e${i}-org`" v-model="row.organization" class="form-control form-control-sm" :class="inv(i, 'organization')" maxlength="250">
                                <FieldError :errors="errors" :name="k(i, 'organization')" />
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small required" :for="`e${i}-post`">Post held</label>
                                <input :id="`e${i}-post`" v-model="row.post_held" class="form-control form-control-sm" :class="inv(i, 'post_held')" maxlength="200">
                                <FieldError :errors="errors" :name="k(i, 'post_held')" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small required" :for="`e${i}-type`">Regular / Temporary / Permanent / Contract</label>
                                <select :id="`e${i}-type`" v-model="row.employment_type" class="form-select form-select-sm" :class="inv(i, 'employment_type')">
                                    <option :value="null" disabled>Select…</option>
                                    <option v-for="(label, v) in types" :key="v" :value="v">{{ label }}</option>
                                </select>
                                <FieldError :errors="errors" :name="k(i, 'employment_type')" />
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small required" :for="`e${i}-from`">From</label>
                                <input :id="`e${i}-from`" v-model="row.from_date" type="date" class="form-control form-control-sm" :class="inv(i, 'from_date')" :max="today()">
                                <FieldError :errors="errors" :name="k(i, 'from_date')" />
                            </div>
                            <div class="col-6 col-md-3">
                                <template v-if="!row.is_current">
                                    <label class="form-label small required" :for="`e${i}-to`">To</label>
                                    <input :id="`e${i}-to`" v-model="row.to_date" type="date" class="form-control form-control-sm" :class="inv(i, 'to_date')" :min="row.from_date ?? undefined" :max="today()">
                                    <FieldError :errors="errors" :name="k(i, 'to_date')" />
                                </template>
                                <template v-else>
                                    <span class="form-label small d-block">To</span>
                                    <div class="form-control-plaintext form-control-sm py-1">Till date</div>
                                </template>
                            </div>
                            <div class="col-md-2">
                                <span class="form-label small d-block">Duration</span>
                                <div class="form-control-plaintext form-control-sm py-1 fw-semibold">{{ duration(row) !== null ? monthsToText(duration(row)) : '—' }}</div>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input :id="`e${i}-cur`" class="form-check-input" type="checkbox" :checked="row.is_current" @change="setCurrent(row, $event)">
                                    <label class="form-check-label small" :for="`e${i}-cur`">Currently working here</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small" :for="`e${i}-scale`">Pay scale / Pay level</label>
                                <input :id="`e${i}-scale`" v-model="row.pay_scale_level" class="form-control form-control-sm" :class="inv(i, 'pay_scale_level')" maxlength="150">
                                <FieldError :errors="errors" :name="k(i, 'pay_scale_level')" />
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label small" :for="`e${i}-basic`">Basic pay (₹)</label>
                                <input :id="`e${i}-basic`" v-model.number="row.basic_pay" type="number" min="0" step="1" class="form-control form-control-sm" :class="inv(i, 'basic_pay')">
                                <FieldError :errors="errors" :name="k(i, 'basic_pay')" />
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label small" :for="`e${i}-gross`">Gross pay (₹)</label>
                                <input :id="`e${i}-gross`" v-model.number="row.gross_pay" type="number" min="0" step="1" class="form-control form-control-sm" :class="inv(i, 'gross_pay')">
                                <FieldError :errors="errors" :name="k(i, 'gross_pay')" />
                            </div>
                            <div class="col-12">
                                <label class="form-label small required" :for="`e${i}-duty`">Nature of duty</label>
                                <textarea :id="`e${i}-duty`" v-model="row.nature_of_duties" rows="2" class="form-control form-control-sm" :class="inv(i, 'nature_of_duties')" maxlength="3000"></textarea>
                                <FieldError :errors="errors" :name="k(i, 'nature_of_duties')" />
                            </div>
                        </div>
                        <div class="mt-2"><RowUpload step="employment" :row-id="row.id" /></div>
                    </div>
                </template>
                <p v-else-if="form.has_employment === false" class="text-muted small mb-0">You have stated that you have no employment experience.</p>
            </div>
        </div>
        <StepUploads step="employment" :hide="form.has_employment ? [] : ['analogous_post_proof']" />
    </div>
</template>
