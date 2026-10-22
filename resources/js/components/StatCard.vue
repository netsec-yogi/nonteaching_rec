<script setup lang="ts">
/** KPI tile (hostel StatCard). With `to`, the whole tile opens the detail behind the figure. */
defineProps<{
    label: string;
    value: number | string | null | undefined;
    icon?: string;
    tone?: 'default' | 'brand' | 'success' | 'warning' | 'danger';
    hint?: string | null;
    to?: string | Record<string, unknown>;
}>();
</script>

<template>
    <component :is="to ? 'router-link' : 'div'" :to="to" class="card stat-tile text-decoration-none" :class="{ 'card-hover': to }">
        <div class="d-flex align-items-start justify-content-between gap-3">
            <div class="min-w-0">
                <p class="stat-tile-label mb-0">{{ label }}</p>
                <p class="stat-tile-value mb-0">{{ value === null || value === undefined ? '—' : typeof value === 'number' ? value.toLocaleString('en-IN') : value }}</p>
            </div>
            <span v-if="icon" class="stat-tile-icon" :class="tone && tone !== 'default' ? `tone-${tone}` : ''"><i class="bi" :class="`bi-${icon}`" aria-hidden="true"></i></span>
        </div>
        <p v-if="hint" class="stat-tile-hint mb-0">{{ hint }}</p>
    </component>
</template>
