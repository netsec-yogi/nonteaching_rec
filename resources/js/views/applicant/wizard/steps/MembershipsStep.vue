<script setup lang="ts">
/** Section 23 — Corporate membership of professional institutes. */
import OptionalRows, { type RowField } from './OptionalRows.vue';
import type { MembershipsData } from '../types';

defineProps<{ form: MembershipsData }>();
const fields: RowField[] = [
    { key: 'institute_name', label: 'Name of the professional institute', required: true, col: 'col-12' },
    { key: 'membership_category', label: 'Category of membership', required: true, max: 150, col: 'col-md-4' },
    { key: 'membership_number', label: 'Membership number', required: true, max: 100, col: 'col-md-4' },
    { key: 'member_since', label: 'Member since', type: 'date', pastOnly: true, col: 'col-md-4' },
];
</script>

<template>
    <OptionalRows :form="(form as never)" step="memberships" flag="has_memberships"
        heading="23. Corporate membership of professional institutes"
        question="Are you a corporate member of any professional institute?"
        :fields="fields" :row-title="(r) => String(r.institute_name || 'Membership')" add-label="Add membership"
        no-label="You have stated that you are not a corporate member of any professional institute." />
</template>
