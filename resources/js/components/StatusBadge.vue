<script setup lang="ts">
import { computed } from 'vue';
import { humanize, statusColor } from '@/utils/format';

const props = defineProps<{ value?: string | null; label?: string }>();
const color = computed(() => statusColor(props.value));
</script>

<template>
    <span class="badge" :class="[`text-bg-${color}`, color === 'light' ? 'border' : '']">{{ label ?? humanize(value) }}</span>
</template>
