<script setup lang="ts">
/** Section 21 — Certificates of excellence / merit received during the service period. */
import OptionalRows, { type RowField } from './OptionalRows.vue';
import type { MeritData } from '../types';

defineProps<{ form: MeritData }>();
const fields: RowField[] = [
    { key: 'title', label: 'Certificate / award', required: true },
    { key: 'awarded_by', label: 'Awarded by', required: true },
    { key: 'awarded_on', label: 'Date', type: 'date', pastOnly: true, col: 'col-md-4' },
    { key: 'description', label: 'Description', type: 'textarea', col: 'col-md-8' },
];
</script>

<template>
    <OptionalRows :form="(form as never)" step="merit" flag="has_merit_records"
        heading="21. Certificates of excellence / merit during the service period"
        question="Have you received any certificate of excellence / merit during your service period?"
        :fields="fields" :row-title="(r) => String(r.title || 'Certificate')" add-label="Add certificate"
        no-label="You have stated that you have not received any certificate of excellence / merit." />
</template>
