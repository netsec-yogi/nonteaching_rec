<script setup lang="ts">
/** The documents the advertisement requires of this application, with mandatory status and verification state. */
import { computed } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import type { RequiredDocument } from './types';

const props = defineProps<{ documents: RequiredDocument[] }>();
const LABELS: Record<string, string> = {
    missing: 'Missing', not_provided: 'Not provided', verified: 'Verified', rejected: 'Rejected', pending: 'Pending verification', not_applicable: 'Not applicable',
};
const missing = computed(() => props.documents.filter((d) => d.status === 'missing').length);
</script>

<template>
    <div class="card section-card">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <span>Required documents</span>
            <span v-if="missing" class="badge text-bg-danger ms-auto">{{ missing }} mandatory missing</span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 table-cards">
                <thead class="table-light"><tr><th>Document</th><th>Requirement</th><th>Applies when</th><th>Uploaded</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="d in documents" :key="d.document_type" :class="{ 'table-danger': d.status === 'missing' }">
                        <td class="small fw-semibold">{{ d.name }}</td>
                        <td class="small"><span class="badge" :class="d.mandatory ? 'text-bg-primary' : 'text-bg-light border'">{{ d.mandatory ? 'Mandatory' : 'Optional' }}</span></td>
                        <td class="small text-muted">{{ d.condition }}</td>
                        <td class="small">{{ d.uploaded }}<span v-if="d.missing_rows" class="text-danger"> · {{ d.missing_rows }} entr{{ d.missing_rows === 1 ? 'y' : 'ies' }} without one</span></td>
                        <td><StatusBadge :value="d.status === 'missing' ? 'deficient' : d.status === 'not_provided' ? 'not_applicable' : d.status" :label="LABELS[d.status] ?? d.status" /></td>
                    </tr>
                    <tr v-if="!documents.length"><td colspan="5" class="text-center text-muted small py-3">The advertisement requires no documents for this application.</td></tr>
                </tbody>
            </table>
        </div>
        <p class="small text-muted mb-0 px-3 py-2">Verify each uploaded file in the table below. A missing optional document does not make the application incomplete.</p>
    </div>
</template>
