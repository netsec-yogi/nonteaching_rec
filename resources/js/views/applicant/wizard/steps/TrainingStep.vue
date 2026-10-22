<script setup lang="ts">
/** Section 22 — Training courses conducted / attended, consultancy services offered. */
import { computed } from 'vue';
import { useWizard } from '../context';
import OptionalRows, { type RowField } from './OptionalRows.vue';
import type { TrainingData } from '../types';

defineProps<{ form: TrainingData }>();
const { payload } = useWizard();
const fields = computed<RowField[]>(() => [
    { key: 'type', label: 'Type', type: 'select', required: true, options: payload.value.options.training_types, col: 'col-md-4' },
    { key: 'title', label: 'Title of the course / service', required: true, col: 'col-md-8' },
    { key: 'organization', label: 'Organisation / venue' },
    { key: 'duration', label: 'Duration', max: 60, col: 'col-md-6' },
    { key: 'from_date', label: 'From', type: 'date', pastOnly: true, col: 'col-6 col-md-3' },
    { key: 'to_date', label: 'To', type: 'date', minFrom: 'from_date', col: 'col-6 col-md-3' },
    { key: 'description', label: 'Description', type: 'textarea', col: 'col-md-6' },
]);
</script>

<template>
    <OptionalRows :form="(form as never)" step="training" flag="has_training_records"
        heading="22. Training courses conducted / attended, consultancy services offered"
        question="Have you conducted or attended any training course, or offered any consultancy service?"
        :fields="fields" :row-title="(r) => String(r.title || 'Training / consultancy')" add-label="Add training / consultancy"
        no-label="You have stated that you have no training or consultancy activities to report." />
</template>
