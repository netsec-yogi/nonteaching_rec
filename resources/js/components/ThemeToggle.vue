<script setup lang="ts">
/** Light / dark switch for the top bar (hostel AppLayout behaviour). */
import { isDark, toggleTheme } from '@/utils/theme';
</script>

<template>
    <button type="button" class="shell-icon-button theme-toggle" :aria-label="isDark ? 'Switch to light theme' : 'Switch to dark theme'" :title="isDark ? 'Light theme' : 'Dark theme'" @click="toggleTheme">
        <i class="bi bi-moon" aria-hidden="true"></i><i class="bi bi-sun" aria-hidden="true"></i>
    </button>
</template>
