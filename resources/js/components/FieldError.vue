<script setup lang="ts">
/** Shows the first validation message for `name` from a Laravel errors bag. */
defineProps<{ errors: Record<string, string[]>; name: string }>();
</script>

<template>
    <div v-if="errors[name]?.length" class="invalid-feedback d-block">{{ errors[name][0] }}</div>
</template>
