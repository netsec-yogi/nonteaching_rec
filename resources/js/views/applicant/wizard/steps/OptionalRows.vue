<script setup lang="ts">
/**
 * A YES/NO question followed by repeatable rows when the answer is YES —
 * the shape shared by Sections 21, 22 and 23.
 */
import FieldError from '@/components/FieldError.vue';
import { errorsUnder, rowKey, today, useWizard } from '../context';
import RowUpload from '../RowUpload.vue';
import StepUploads from '../StepUploads.vue';
import YesNo from '../YesNo.vue';
import type { StepKey } from '../types';

export interface RowField {
    key: string;
    label: string;
    type?: 'text' | 'date' | 'textarea' | 'select';
    required?: boolean;
    options?: Record<string, string>;
    col?: string;
    max?: number;
    /** For a date that must not precede another field of the row. */
    minFrom?: string;
    pastOnly?: boolean;
}

type Row = Record<string, string | number | null> & { id: number | null };

const props = defineProps<{
    form: Record<string, unknown> & { rows: Row[] };
    step: StepKey;
    flag: string;
    heading: string;
    question: string;
    fields: RowField[];
    rowTitle: (row: Row) => string;
    addLabel: string;
    noLabel: string;
}>();

const { errors, readonly } = useWizard();

function blank(): Row {
    const row: Row = { id: null };
    for (const f of props.fields) row[f.key] = f.type === 'date' || f.type === 'select' ? null : '';
    return row;
}
function setFlag(v: boolean) {
    props.form[props.flag] = v;
    if (v && !props.form.rows.length) props.form.rows.push(blank());
}
function remove(i: number) {
    if (props.form.rows[i].id && !confirm('Remove this entry? Documents attached to it will be detached when you save.')) return;
    props.form.rows.splice(i, 1);
}
const k = (i: number, f: string) => `rows.${i}.${f}`;
const inv = (i: number, f: string) => ({ 'is-invalid': !!errors[k(i, f)] });
const flagValue = () => props.form[props.flag] as boolean | null;
const minFor = (row: Row, f: RowField) => (f.minFrom && row[f.minFrom] ? String(row[f.minFrom]) : undefined);
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header">{{ heading }}</div>
            <div class="card-body">
                <span class="form-label required d-block">{{ question }}</span>
                <YesNo :model-value="flagValue()" :name="flag" :invalid="!!errors[flag]" @update:model-value="setFlag" />
                <FieldError :errors="errors" :name="flag" />

                <template v-if="flagValue()">
                    <hr>
                    <FieldError :errors="errors" name="rows" />
                    <div v-for="(row, i) in form.rows" :key="rowKey(row)" class="border rounded p-3 mb-3" :class="{ 'border-danger': errorsUnder(errors, `rows.${i}.`).length }">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge text-bg-secondary">{{ i + 1 }}</span>
                            <span class="fw-semibold flex-grow-1 text-truncate">{{ rowTitle(row) }}</span>
                            <button v-if="!readonly" type="button" class="btn btn-sm btn-outline-danger" title="Remove" @click="remove(i)"><i class="bi bi-trash"></i><span class="visually-hidden">Remove</span></button>
                        </div>
                        <div class="row g-2">
                            <div v-for="f in fields" :key="f.key" :class="f.col ?? 'col-md-6'">
                                <label class="form-label small" :class="{ required: f.required }" :for="`${step}${i}-${f.key}`">{{ f.label }}</label>
                                <select v-if="f.type === 'select'" :id="`${step}${i}-${f.key}`" v-model="row[f.key]" class="form-select form-select-sm" :class="inv(i, f.key)">
                                    <option :value="null" disabled>Select…</option>
                                    <option v-for="(label, v) in f.options" :key="v" :value="v">{{ label }}</option>
                                </select>
                                <textarea v-else-if="f.type === 'textarea'" :id="`${step}${i}-${f.key}`" v-model="row[f.key]" rows="2" class="form-control form-control-sm" :class="inv(i, f.key)" :maxlength="f.max ?? 2000"></textarea>
                                <input v-else-if="f.type === 'date'" :id="`${step}${i}-${f.key}`" v-model="row[f.key]" type="date" class="form-control form-control-sm" :class="inv(i, f.key)"
                                    :min="minFor(row, f)" :max="f.pastOnly ? today() : undefined">
                                <input v-else :id="`${step}${i}-${f.key}`" v-model="row[f.key]" class="form-control form-control-sm" :class="inv(i, f.key)" :maxlength="f.max ?? 250">
                                <FieldError :errors="errors" :name="k(i, f.key)" />
                            </div>
                        </div>
                        <div class="mt-2"><RowUpload :step="step" :row-id="row.id" /></div>
                    </div>
                    <button v-if="!readonly" type="button" class="btn btn-outline-primary btn-sm" @click="form.rows.push(blank())"><i class="bi bi-plus-lg me-1"></i>{{ addLabel }}</button>
                </template>
                <p v-else-if="flagValue() === false" class="text-muted small mt-2 mb-0">{{ noLabel }}</p>
            </div>
        </div>
        <StepUploads :step="step" />
    </div>
</template>
