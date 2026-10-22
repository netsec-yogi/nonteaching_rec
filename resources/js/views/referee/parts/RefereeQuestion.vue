<script setup lang="ts">
/** One configurable question on the referee form, rendered by type. */
import { computed } from 'vue';

export interface RefereeQuestionDef {
    code: string; label: string; help_text: string | null; type: string; is_required: boolean;
    options: { min?: number; max?: number; labels?: Record<string, string>; choices?: string[] } | null;
}
export type AnswerValue = string | number | null;

const props = defineProps<{ question: RefereeQuestionDef; modelValue: AnswerValue; number: number; error?: string }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: AnswerValue): void }>();

const id = computed(() => `q-${props.question.code}`);
const scale = computed(() => {
    const min = props.question.options?.min ?? 1;
    const max = props.question.options?.max ?? 5;
    return Array.from({ length: Math.max(0, max - min + 1) }, (_, i) => min + i);
});
const value = computed({
    get: () => props.modelValue,
    set: (v: AnswerValue) => emit('update:modelValue', v === '' ? null : v),
});
const limit = computed(() => (props.question.type === 'short_text' ? 500 : 5000));
const length = computed(() => (typeof props.modelValue === 'string' ? props.modelValue.length : 0));
</script>

<template>
    <div :id="`${id}-wrap`" class="py-3 border-bottom" role="group" :aria-labelledby="`${id}-label`">
        <div :id="`${id}-label`" class="fw-semibold mb-1">
            <span class="text-muted me-1">{{ number }}.</span>{{ question.label }}
            <span v-if="question.is_required" class="text-danger" aria-hidden="true">*</span>
            <span v-if="question.is_required" class="visually-hidden">(required)</span>
        </div>
        <div v-if="question.help_text" class="form-text mt-0 mb-2">{{ question.help_text }}</div>

        <div v-if="question.type === 'rating'" class="rating-scale d-flex flex-wrap gap-2">
            <template v-for="n in scale" :key="n">
                <input :id="`${id}-${n}`" v-model.number="value" class="btn-check" type="radio" :name="id" :value="n">
                <label class="btn btn-outline-primary rating-option" :for="`${id}-${n}`">
                    <span class="d-block fw-bold">{{ n }}</span>
                    <span v-if="question.options?.labels?.[String(n)]" class="d-block small">{{ question.options.labels[String(n)] }}</span>
                </label>
            </template>
        </div>

        <div v-else-if="question.type === 'yes_no'" class="d-flex gap-4">
            <div class="form-check">
                <input :id="`${id}-yes`" v-model="value" class="form-check-input" type="radio" :name="id" value="yes">
                <label class="form-check-label" :for="`${id}-yes`">Yes</label>
            </div>
            <div class="form-check">
                <input :id="`${id}-no`" v-model="value" class="form-check-input" type="radio" :name="id" value="no">
                <label class="form-check-label" :for="`${id}-no`">No</label>
            </div>
        </div>

        <div v-else-if="question.type === 'choice'" class="vstack gap-1">
            <div v-for="(c, i) in question.options?.choices ?? []" :key="c" class="form-check">
                <input :id="`${id}-c${i}`" v-model="value" class="form-check-input" type="radio" :name="id" :value="c">
                <label class="form-check-label" :for="`${id}-c${i}`">{{ c }}</label>
            </div>
        </div>

        <template v-else>
            <input v-if="question.type === 'short_text'" :id="id" v-model="value" class="form-control" :class="{ 'is-invalid': error }" :maxlength="limit" :aria-label="question.label">
            <textarea v-else :id="id" v-model="value" class="form-control" :class="{ 'is-invalid': error }" rows="4" :maxlength="limit" :aria-label="question.label"></textarea>
            <div class="form-text text-end">{{ length }} / {{ limit }}</div>
        </template>

        <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
    </div>
</template>

<style scoped>
.rating-option { min-width: 6.5rem; line-height: 1.2; }
@media (max-width: 575.98px) { .rating-option { min-width: 0; flex: 1 1 28%; } }
</style>
