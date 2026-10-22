<script setup lang="ts">
/** Live checklist for the portal's password policy (min 10, mixed case, number, symbol). */
import { computed } from 'vue';

const props = defineProps<{ password: string; confirmation?: string; id?: string }>();

const rules = computed(() => {
    const p = props.password ?? '';
    const list = [
        { label: 'At least 10 characters', ok: p.length >= 10 },
        { label: 'Upper and lower case letters', ok: /[a-z]/.test(p) && /[A-Z]/.test(p) },
        { label: 'At least one number', ok: /\d/.test(p) },
        { label: 'At least one symbol (e.g. @ # $ %)', ok: /[^A-Za-z0-9]/.test(p) },
    ];
    if (props.confirmation !== undefined) list.push({ label: 'Both passwords match', ok: p !== '' && p === props.confirmation });
    return list;
});
</script>

<template>
    <ul :id="id" class="list-unstyled small mb-0 mt-2">
        <li v-for="r in rules" :key="r.label" :class="r.ok ? 'text-success' : 'text-muted'">
            <i class="bi me-1" :class="r.ok ? 'bi-check-circle-fill' : 'bi-circle'" aria-hidden="true"></i>{{ r.label }}
            <span class="visually-hidden">{{ r.ok ? '(met)' : '(not met)' }}</span>
        </li>
    </ul>
</template>
