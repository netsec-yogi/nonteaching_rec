<script setup lang="ts">
/** Minimal accessible modal without Bootstrap's JS. Use v-if on the parent to open/close. */
import { onMounted, onBeforeUnmount } from 'vue';

defineProps<{ title: string; size?: 'sm' | 'lg' | 'xl' }>();
const emit = defineEmits<{ (e: 'close'): void }>();
const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') emit('close'); };
onMounted(() => { document.addEventListener('keydown', onKey); document.body.classList.add('modal-open'); });
onBeforeUnmount(() => { document.removeEventListener('keydown', onKey); document.body.classList.remove('modal-open'); });
</script>

<template>
    <Teleport to="body">
        <div class="modal d-block" tabindex="-1" role="dialog" aria-modal="true" @click.self="emit('close')">
            <div class="modal-dialog modal-dialog-scrollable" :class="size ? `modal-${size}` : ''">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ title }}</h5>
                        <button type="button" class="btn-close" aria-label="Close" @click="emit('close')"></button>
                    </div>
                    <div class="modal-body"><slot /></div>
                    <div v-if="$slots.footer" class="modal-footer"><slot name="footer" /></div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop show"></div>
    </Teleport>
</template>
