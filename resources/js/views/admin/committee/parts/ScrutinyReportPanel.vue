<script setup lang="ts">
/** Consolidated scrutiny report for the committee (read-only): stage reports, deficiencies, checklist, eligibility, observations. */
import StatusBadge from '@/components/StatusBadge.vue';
import { formatDate, formatDateTime, humanize } from '@/utils/format';
import EligibilityTable from '../../scrutiny/parts/EligibilityTable.vue';
import type { ScrutinyReport } from '../../scrutiny/parts/types';

defineProps<{ report: ScrutinyReport }>();
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header">Status summary</div>
            <div class="card-body small d-flex flex-wrap gap-3">
                <span v-for="(v, k) in report.status" :key="k"><span class="text-muted">{{ humanize(String(k)) }}:</span> <strong>{{ v ?? '—' }}</strong></span>
            </div>
        </div>

        <div class="card section-card">
            <div class="card-header">Stage reports</div>
            <ul class="list-group list-group-flush small">
                <li v-for="(r, i) in report.reviews" :key="i" class="list-group-item">
                    <div class="d-flex"><strong class="flex-grow-1">{{ r.stage }}</strong><span class="text-muted">{{ r.by }}, {{ formatDateTime(r.submitted_at) }}</span></div>
                    <div v-if="r.decision">Decision: <strong>{{ r.decision }}</strong></div>
                    <div v-if="r.completeness">Completeness: <strong>{{ r.completeness }}</strong> · Recommendation: <strong>{{ r.recommendation }}</strong></div>
                    <div v-if="r.reasons" class="text-pre text-muted">{{ r.reasons }}</div>
                </li>
                <li v-if="!report.reviews.length" class="list-group-item text-muted">No stage report submitted.</li>
            </ul>
        </div>

        <div class="card section-card" :class="report.deficiencies.length ? 'border-danger' : ''">
            <div class="card-header">Outstanding deficiencies</div>
            <div class="card-body small">
                <ol v-if="report.deficiencies.length" class="mb-0 text-danger"><li v-for="(d, i) in report.deficiencies" :key="i">{{ d }}</li></ol>
                <span v-else class="text-success"><i class="bi bi-check2-circle me-1"></i>None recorded.</span>
            </div>
        </div>

        <div class="card section-card">
            <div class="card-header">Checklist verification</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 small">
                    <thead class="table-light"><tr><th>Item</th><th>Candidate</th><th>Verdict</th><th>Remarks</th></tr></thead>
                    <tbody>
                        <tr v-for="i in report.items" :key="i.checklist_item_id">
                            <td>{{ i.label }}<span v-if="i.is_mandatory" class="text-danger"> *</span></td>
                            <td class="text-uppercase">{{ i.candidate_status ?? '—' }}<span v-if="i.candidate_page_reference" class="text-muted text-lowercase"> (p. {{ i.candidate_page_reference }})</span></td>
                            <td><StatusBadge :value="i.status" /></td>
                            <td>{{ i.discrepancy_reason || i.remarks || '' }}<div v-if="i.verified_by" class="text-muted">{{ i.verified_by }}</div></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card section-card">
            <div class="card-header">Eligibility (advisory)</div>
            <div class="card-body"><EligibilityTable :rows="report.eligibility" /></div>
        </div>

        <div class="card section-card">
            <div class="card-header">Observations</div>
            <ul class="list-group list-group-flush small">
                <li v-for="(o, i) in report.observations" :key="i" class="list-group-item">
                    <span class="text-muted">{{ humanize(o.section ?? 'general') }}</span>
                    <span v-if="o.deficiency" class="badge text-bg-danger ms-1">Deficiency</span>
                    <span v-if="o.resolved_at" class="badge text-bg-success ms-1">Resolved</span>
                    <div class="text-pre">{{ o.text }}</div>
                    <div class="text-muted">{{ o.by }}, {{ formatDateTime(o.at) }}</div>
                </li>
                <li v-if="!report.observations.length" class="list-group-item text-muted">None.</li>
            </ul>
        </div>

        <div v-if="report.clarifications.length" class="card section-card">
            <div class="card-header">Clarifications</div>
            <ul class="list-group list-group-flush small">
                <li v-for="(c, i) in report.clarifications" :key="i" class="list-group-item d-flex gap-2">
                    <span class="flex-grow-1">{{ c.subject }} <span class="text-muted">— issued {{ formatDate(c.issued_at) }}</span><span v-if="c.resolution" class="d-block text-muted">{{ c.resolution }}</span></span>
                    <StatusBadge :value="c.status" />
                </li>
            </ul>
        </div>
    </div>
</template>
