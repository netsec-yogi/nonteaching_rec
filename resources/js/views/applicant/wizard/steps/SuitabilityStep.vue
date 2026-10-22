<script setup lang="ts">
/** Section 24 — Paragraph (not exceeding 500 words) on suitability for the post. */
import { computed } from 'vue';
import FieldError from '@/components/FieldError.vue';
import { wordCount } from '@/utils/format';
import { useWizard } from '../context';
import StepUploads from '../StepUploads.vue';
import type { SuitabilityData } from '../types';

const props = defineProps<{ form: SuitabilityData }>();
const { payload, errors } = useWizard();
const words = computed(() => wordCount(props.form.suitability_statement));
const max = computed(() => props.form.max_words || 500);
const remaining = computed(() => max.value - words.value);
const over = computed(() => remaining.value < 0);
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header">24. Suitability for the post</div>
            <div class="card-body">
                <label class="form-label required" for="suit">
                    Write a paragraph (not exceeding {{ max }} words) justifying your suitability for the post of
                    <strong>{{ payload.application.post.name }}</strong>.
                </label>
                <textarea id="suit" v-model="form.suitability_statement" rows="14" class="form-control" :class="{ 'is-invalid': errors.suitability_statement || over }" maxlength="10000"></textarea>
                <div class="d-flex justify-content-between small mt-1" :class="over ? 'text-danger fw-semibold' : 'text-muted'" aria-live="polite">
                    <span>{{ words }} / {{ max }} words</span>
                    <span v-if="over">{{ -remaining }} word{{ -remaining === 1 ? '' : 's' }} over the limit</span>
                    <span v-else>{{ remaining }} word{{ remaining === 1 ? '' : 's' }} remaining</span>
                </div>
                <FieldError :errors="errors" name="suitability_statement" />
            </div>
        </div>
        <StepUploads step="suitability" />
    </div>
</template>
