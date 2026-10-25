<script setup lang="ts">
/** One typed question of a referee or evaluation form, with type-specific options. */
import { computed, watch } from 'vue';
import FieldError from '@/components/FieldError.vue';
import { QUESTION_TYPES, rowErrors, type FormQuestion } from './types';

const props = defineProps<{ q: FormQuestion; index: number; errors: Record<string, string[]>; first: boolean; last: boolean }>();
const emit = defineEmits<{ (e: 'move', delta: number): void; (e: 'remove'): void }>();
const err = computed(() => rowErrors(props.errors, `questions.${props.index}.`));

// Keep `options` in the shape the chosen type expects.
watch(() => props.q.type, (t) => {
    const q = props.q;
    if (t === 'rating') q.options = { min: q.options?.min ?? 1, max: q.options?.max ?? 5, labels: q.options?.labels ?? {} };
    else if (t === 'choice') q.options = { choices: q.options?.choices?.length ? q.options.choices : [''] };
    else q.options = null;
}, { immediate: true });

const scale = computed(() => {
    const o = props.q.options;
    const min = Number(o?.min ?? 1), max = Number(o?.max ?? 5);
    return max >= min && max - min <= 10 ? Array.from({ length: max - min + 1 }, (_, i) => String(min + i)) : [];
});
function setLabel(v: string, text: string) {
    const o = props.q.options!;
    o.labels = { ...(o.labels ?? {}) };
    if (text) o.labels[v] = text; else delete o.labels[v];
}
function slug() {
    const q = props.q;
    if (!q.code && q.label) q.code = q.label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 40);
}
</script>

<template>
    <div class="card mb-2">
        <div class="card-body py-2">
            <div class="row g-2 align-items-start">
                <div class="col-auto pt-4 small fw-semibold text-muted">{{ index + 1 }}</div>
                <div class="col-md-6">
                    <label class="form-label small mb-0 required" :for="`q-label-${index}`">Question</label>
                    <input :id="`q-label-${index}`" v-model="q.label" class="form-control form-control-sm" maxlength="500" :class="{ 'is-invalid': err.label }" @blur="slug">
                    <FieldError :errors="err" name="label" />
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0 required" :for="`q-code-${index}`">Code</label>
                    <input :id="`q-code-${index}`" v-model="q.code" class="form-control form-control-sm font-monospace" maxlength="60" :class="{ 'is-invalid': err.code }">
                    <FieldError :errors="err" name="code" />
                </div>
                <div class="col">
                    <label class="form-label small mb-0 required" :for="`q-type-${index}`">Answer type</label>
                    <select :id="`q-type-${index}`" v-model="q.type" class="form-select form-select-sm" :class="{ 'is-invalid': err.type }">
                        <option v-for="(label, key) in QUESTION_TYPES" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <FieldError :errors="err" name="type" />
                </div>
                <div class="col-md-8 offset-md-0">
                    <input v-model="q.help_text" :aria-label="`Question ${index + 1} help text`" class="form-control form-control-sm" maxlength="500" placeholder="Help text (optional)">
                    <FieldError :errors="err" name="help_text" />
                </div>
                <div class="col-md d-flex align-items-center gap-3 small">
                    <div class="form-check mb-0"><input :id="`q-req-${index}`" v-model="q.is_required" class="form-check-input" type="checkbox"><label class="form-check-label" :for="`q-req-${index}`">Required</label></div>
                    <div class="ms-auto text-nowrap">
                        <button type="button" class="btn btn-sm btn-link p-0 me-1" :disabled="first" title="Move up" @click="emit('move', -1)"><i class="bi bi-arrow-up"></i></button>
                        <button type="button" class="btn btn-sm btn-link p-0 me-1" :disabled="last" title="Move down" @click="emit('move', 1)"><i class="bi bi-arrow-down"></i></button>
                        <button type="button" class="btn btn-sm btn-link text-danger p-0" title="Remove" @click="emit('remove')"><i class="bi bi-trash"></i></button>
                    </div>
                </div>

                <div v-if="q.type === 'rating' && q.options" class="col-12">
                    <div class="bg-light rounded p-2">
                        <div class="d-flex flex-wrap gap-2 align-items-end mb-2">
                            <div><label class="form-label small mb-0" :for="`q-min-${index}`">Lowest</label><input :id="`q-min-${index}`" v-model.number="q.options.min" type="number" min="0" max="10" class="form-control form-control-sm" style="width: 5rem" :class="{ 'is-invalid': err['options.min'] }"></div>
                            <div><label class="form-label small mb-0" :for="`q-max-${index}`">Highest</label><input :id="`q-max-${index}`" v-model.number="q.options.max" type="number" min="1" max="10" class="form-control form-control-sm" style="width: 5rem" :class="{ 'is-invalid': err['options.max'] }"></div>
                            <FieldError :errors="err" name="options.min" /><FieldError :errors="err" name="options.max" />
                        </div>
                        <div class="row g-1">
                            <div v-for="v in scale" :key="v" class="col-md-4 col-lg-3">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">{{ v }}</span>
                                    <input :value="q.options.labels?.[v] ?? ''" :aria-label="`Label for rating ${v}`" class="form-control" placeholder="Label (optional)" @input="setLabel(v, ($event.target as HTMLInputElement).value)">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else-if="q.type === 'choice' && q.options?.choices" class="col-12">
                    <div class="bg-light rounded p-2">
                        <div class="small fw-semibold mb-1">Choices</div>
                        <div v-for="(c, ci) in q.options.choices" :key="ci" class="input-group input-group-sm mb-1">
                            <input v-model="q.options.choices[ci]" :aria-label="`Choice ${ci + 1}`" class="form-control" maxlength="150" :class="{ 'is-invalid': err[`options.choices.${ci}`] }">
                            <button type="button" class="btn btn-outline-danger" :disabled="q.options.choices.length <= 1" @click="q.options.choices.splice(ci, 1)"><i class="bi bi-x"></i><span class="visually-hidden">Remove choice</span></button>
                        </div>
                        <FieldError :errors="err" name="options.choices" />
                        <button type="button" class="btn btn-sm btn-link p-0" @click="q.options.choices.push('')"><i class="bi bi-plus"></i> Add choice</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
