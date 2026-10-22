<script setup lang="ts">
/** Referee status only (who was invited, delivered, received) — never the recommendation content. */
import StatusBadge from '@/components/StatusBadge.vue';
import { formatDate, formatDateTime } from '@/utils/format';
import type { RefereeRow } from './types';

defineProps<{ rows: RefereeRow[] }>();
</script>

<template>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>#</th><th>Referee</th><th>Email delivery</th><th>Recommendation</th><th>Deadline</th></tr></thead>
            <tbody>
                <tr v-for="r in rows" :key="r.position">
                    <td>{{ r.position }}</td>
                    <td><div class="fw-semibold small">{{ r.name }}</div><div class="small text-muted">{{ r.designation }}</div></td>
                    <td><StatusBadge :value="r.delivery_status" /><div v-if="r.opened" class="small text-muted">Link opened</div></td>
                    <td>
                        <StatusBadge :value="r.recommendation_status" />
                        <div v-if="r.submitted_at" class="small text-muted">{{ formatDateTime(r.submitted_at) }}</div>
                    </td>
                    <td class="small text-nowrap">{{ formatDate(r.expires_at) }}</td>
                </tr>
                <tr v-if="!rows.length"><td colspan="5" class="text-muted small">No referees.</td></tr>
            </tbody>
        </table>
    </div>
</template>
