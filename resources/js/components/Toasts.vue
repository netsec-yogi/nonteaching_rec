<script setup lang="ts">
/** Toasts in the style of the hostel FlashMessages: top right, white card, coloured bar and icon. */
import { useToast } from '@/stores/toast';

const toast = useToast();
const icon = { success: 'bi-check-circle', info: 'bi-info-circle', warning: 'bi-exclamation-triangle', danger: 'bi-exclamation-circle' };
const color = { success: '#059669', info: '#2a4ccc', warning: '#d97706', danger: '#dc2626' };
</script>

<template>
    <div class="flash-stack" aria-live="polite">
        <div v-for="t in toast.items" :key="t.id" class="flash" :role="t.variant === 'danger' ? 'alert' : 'status'">
            <span class="flash-bar" :style="{ background: color[t.variant] }" aria-hidden="true"></span>
            <i class="bi" :class="icon[t.variant]" :style="{ color: color[t.variant] }" aria-hidden="true"></i>
            <span class="flex-grow-1">{{ t.message }}</span>
            <button type="button" class="flash-close" aria-label="Dismiss" @click="toast.dismiss(t.id)"><i class="bi bi-x-lg small"></i></button>
        </div>
    </div>
</template>
