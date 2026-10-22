<script setup lang="ts">
defineProps<{ loading: boolean; error?: string | null }>();
</script>

<template>
    <div v-if="loading" class="card overflow-hidden" role="status" aria-label="Loading">
        <div class="loading-bar"></div>
        <div class="empty-state text-muted"><span class="spinner-border spinner-border-sm mb-2" aria-hidden="true"></span><span class="small">Loading…</span></div>
    </div>
    <div v-else-if="error" class="card">
        <div class="empty-state">
            <span class="empty-icon" style="background: var(--danger-bg); color: var(--danger-fg)"><i class="bi bi-exclamation-triangle"></i></span>
            <p class="empty-title mb-0">Something went wrong</p>
            <p class="empty-text mb-0">{{ error }}</p>
        </div>
    </div>
    <slot v-else />
</template>
