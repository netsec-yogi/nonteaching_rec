<script setup lang="ts">
/** Sections 26–30 — Additional declarations, questions rendered verbatim from the server. */
import { computed } from 'vue';
import FieldError from '@/components/FieldError.vue';
import { DECLARATION_LABELS, useWizard } from '../context';
import StepUploads from '../StepUploads.vue';
import YesNo from '../YesNo.vue';
import type { DeclarationsData } from '../types';

const props = defineProps<{ form: DeclarationsData }>();
const { payload, errors } = useWizard();
const questions = computed(() => Object.entries(payload.value.options.declaration_questions));
// The wizard shell guarantees an entry for every question code.
const entry = (code: string) => props.form[code];
const needsDetails = (code: string, when: boolean | null) => when !== null && entry(code).answer === when;
const detailsLabel = (code: string, when: boolean | null) => {
    if (when === null) return 'Remarks (optional)';
    if (code === 'sec30') return 'If NO, give reasons';
    if (code === 'sec28') return 'If YES, give particulars (post, advertisement number, year)';
    return 'If YES, give details';
};
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header">Sections 26–30 · Declarations</div>
            <ul class="list-group list-group-flush">
                <li v-for="[code, q] in questions" :key="code" class="list-group-item py-3">
                    <div class="d-flex gap-2">
                        <span class="fw-bold text-nowrap">{{ DECLARATION_LABELS[code] ?? code }}.</span>
                        <div class="flex-grow-1">
                            <p class="mb-2 required form-label">{{ q.question }}</p>
                            <YesNo :model-value="entry(code).answer" :name="code" :invalid="!!errors[`${code}.answer`]" @update:model-value="(v) => (entry(code).answer = v)" />
                            <FieldError :errors="errors" :name="`${code}.answer`" />
                            <div v-if="needsDetails(code, q.details_when) || (q.details_when === null && entry(code).answer !== null)" class="mt-2">
                                <label class="form-label small" :class="{ required: q.details_when !== null }" :for="`${code}-details`">{{ detailsLabel(code, q.details_when) }}</label>
                                <textarea :id="`${code}-details`" v-model="entry(code).details" rows="3" class="form-control form-control-sm" :class="{ 'is-invalid': errors[`${code}.details`] }" maxlength="5000"></textarea>
                                <FieldError :errors="errors" :name="`${code}.details`" />
                            </div>
                        </div>
                    </div>
                </li>
            </ul>
        </div>
        <StepUploads step="declarations" />
    </div>
</template>
