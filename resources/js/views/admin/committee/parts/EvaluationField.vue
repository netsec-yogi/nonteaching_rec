<script setup lang="ts">
/** One field of a configurable evaluation form (yes_no, rating, choice, short_text, long_text). */
import { computed } from 'vue';

export interface FormField {
    code: string; label: string; help_text: string | null; type: string; is_required: boolean;
    options: { min?: number; max?: number; labels?: Record<string, string>; choices?: string[] } | null;
}
export type FieldValue = string | number | null;

const props = defineProps<{ field: FormField; modelValue: FieldValue; disabled?: boolean; error?: string; idPrefix?: string }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: FieldValue): void }>();

const id = computed(() => `${props.idPrefix ?? 'ef'}-${props.field.code}`);
const scale = computed(() => {
    const min = props.field.options?.min ?? 1;
    const max = props.field.options?.max ?? 5;
    return Array.from({ length: Math.max(0, max - min + 1) }, (_, i) => min + i);
});
const value = computed({
    get: () => props.modelValue,
    set: (v: FieldValue) => emit('update:modelValue', v === '' ? null : v),
});
</script>

<template>
    <div class="mb-3">
        <label class="form-label small fw-semibold mb-1 d-block" :class="{ required: field.is_required }" :for="field.type.endsWith('text') ? id : undefined">{{ field.label }}</label>
        <div v-if="field.help_text" class="form-text mt-0 mb-1">{{ field.help_text }}</div>

        <div v-if="field.type === 'yes_no'" class="btn-group btn-group-sm" role="radiogroup" :aria-label="field.label">
            <input :id="`${id}-yes`" v-model="value" class="btn-check" type="radio" :name="id" value="yes" :disabled="disabled">
            <label class="btn btn-outline-success" :for="`${id}-yes`">Yes</label>
            <input :id="`${id}-no`" v-model="value" class="btn-check" type="radio" :name="id" value="no" :disabled="disabled">
            <label class="btn btn-outline-danger" :for="`${id}-no`">No</label>
        </div>

        <div v-else-if="field.type === 'rating'" class="btn-group btn-group-sm flex-wrap" role="radiogroup" :aria-label="field.label">
            <template v-for="n in scale" :key="n">
                <input :id="`${id}-${n}`" v-model.number="value" class="btn-check" type="radio" :name="id" :value="n" :disabled="disabled">
                <label class="btn btn-outline-primary" :for="`${id}-${n}`" :title="field.options?.labels?.[String(n)]">
                    {{ n }}<span v-if="field.options?.labels?.[String(n)]" class="d-none d-md-inline"> · {{ field.options.labels[String(n)] }}</span>
                </label>
            </template>
        </div>

        <select v-else-if="field.type === 'choice'" :id="id" v-model="value" class="form-select form-select-sm" :class="{ 'is-invalid': error }" :disabled="disabled">
            <option :value="null">Select…</option>
            <option v-for="c in field.options?.choices ?? []" :key="c" :value="c">{{ c }}</option>
        </select>

        <input v-else-if="field.type === 'short_text'" :id="id" v-model="value" class="form-control form-control-sm" :class="{ 'is-invalid': error }" maxlength="500" :disabled="disabled">

        <textarea v-else :id="id" v-model="value" class="form-control form-control-sm" :class="{ 'is-invalid': error }" rows="3" maxlength="5000" :disabled="disabled"></textarea>

        <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
    </div>
</template>
