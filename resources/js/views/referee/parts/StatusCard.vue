<script setup lang="ts">
/** Terminal states of a referee link: invalid, expired, revoked, completed (or a submitted thank-you). */
import { computed } from 'vue';

const props = defineProps<{ status: string; message: string; contactEmail?: string | null; reference?: string | null; submittedAt?: string | null }>();

const look = computed(() => ({
    invalid: { icon: 'bi-link-45deg', cls: 'text-danger', title: 'This link is not valid' },
    expired: { icon: 'bi-clock-history', cls: 'text-warning', title: 'The deadline has passed' },
    revoked: { icon: 'bi-slash-circle', cls: 'text-secondary', title: 'This invitation has been withdrawn' },
    completed: { icon: 'bi-check-circle', cls: 'text-success', title: 'Recommendation received' },
    error: { icon: 'bi-exclamation-triangle', cls: 'text-warning', title: 'Something went wrong' },
}[props.status] ?? { icon: 'bi-info-circle', cls: 'text-info', title: 'Notice' }));
</script>

<template>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5 px-4">
            <i class="bi display-4" :class="[look.icon, look.cls]" aria-hidden="true"></i>
            <h1 class="h4 page-title mt-3">{{ look.title }}</h1>
            <p class="mb-3 mx-auto" style="max-width: 36rem">{{ message }}</p>
            <div v-if="reference" class="d-inline-block border rounded px-4 py-2 mb-3 bg-light">
                <div class="small text-muted">Reference number</div>
                <div class="fs-5 fw-bold font-monospace">{{ reference }}</div>
                <div v-if="submittedAt" class="small text-muted">{{ submittedAt }}</div>
            </div>
            <p v-if="contactEmail" class="small text-muted mb-0">
                For any query, write to the Recruitment Section at <a :href="`mailto:${contactEmail}`">{{ contactEmail }}</a>.
            </p>
            <slot />
        </div>
    </div>
</template>
