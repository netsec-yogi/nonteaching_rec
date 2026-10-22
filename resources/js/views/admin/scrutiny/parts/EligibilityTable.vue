<script setup lang="ts">
/** Advisory eligibility checks computed from the declared data. Never changes status. */
import type { EligibilityRow } from './types';

defineProps<{ rows: EligibilityRow[] }>();
const meta: Record<string, { text: string; cls: string; icon: string }> = {
    met: { text: 'Met', cls: 'text-bg-success', icon: 'bi-check-circle' },
    not_met: { text: 'Not met', cls: 'text-bg-danger', icon: 'bi-x-circle' },
    manual: { text: 'Verify manually', cls: 'text-bg-warning', icon: 'bi-eye' },
};
const m = (r: string) => meta[r] ?? { text: r, cls: 'text-bg-secondary', icon: 'bi-question-circle' };
</script>

<template>
    <div>
        <p class="small text-muted"><i class="bi bi-info-circle me-1"></i>Advisory only — computed from what the candidate declared. Verify against the documents.</p>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Criterion</th><th>Result</th><th>Basis</th></tr></thead>
                <tbody>
                    <tr v-for="(r, i) in rows" :key="i">
                        <td>{{ r.label }} <span v-if="r.is_essential" class="badge text-bg-light border">Essential</span></td>
                        <td class="text-nowrap"><span class="badge" :class="m(r.result).cls"><i class="bi me-1" :class="m(r.result).icon"></i>{{ m(r.result).text }}</span></td>
                        <td class="small">{{ r.basis }}</td>
                    </tr>
                    <tr v-if="!rows.length"><td colspan="3" class="text-muted small">No eligibility criteria configured for this post.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
