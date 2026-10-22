<script setup lang="ts">
/** Read-only scrutiny summary for the application profile. Recording happens in the scrutiny workspace. */
import { computed } from 'vue';
import { download, toApiError } from '@/api/client';
import { useToast } from '@/stores/toast';
import StatusBadge from '@/components/StatusBadge.vue';
import { formatDateTime, humanize } from '@/utils/format';
import type { Profile } from './profileTypes';

const props = defineProps<{ uuid: string; profile: Profile }>();
const toast = useToast();
const s = computed(() => props.profile.scrutiny);
const app = computed(() => props.profile.application);

const verdicts = computed(() => {
    const counts: Record<string, number> = {};
    for (const c of s.value.checklist) counts[c.status] = (counts[c.status] ?? 0) + 1;
    return counts;
});
const total = computed(() => s.value.checklist.length || 1);
const BAR: Record<string, string> = { verified: 'bg-success', not_verified: 'bg-danger', not_applicable: 'bg-secondary', pending: 'bg-warning' };
const ELIG: Record<string, { cls: string; icon: string; label: string }> = {
    met: { cls: 'text-success', icon: 'bi-check-circle-fill', label: 'Met' },
    not_met: { cls: 'text-danger', icon: 'bi-x-circle-fill', label: 'Not met' },
    manual: { cls: 'text-warning', icon: 'bi-exclamation-circle-fill', label: 'Verify manually' },
};

function reportPdf() {
    download(`/admin/applications/${props.uuid}/scrutiny-report.pdf`, `Scrutiny-Report-${(app.value.reference_no ?? props.uuid).replace(/\//g, '-')}.pdf`)
        .catch((e) => toast.error(toApiError(e).message));
}
</script>

<template>
    <div class="vstack gap-3">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span>Scrutiny status: <StatusBadge :value="app.scrutiny_status" /></span>
            <span v-if="app.current_stage" class="small text-muted">Current stage: {{ app.current_stage.name }}</span>
            <span v-if="app.outcome">Outcome: <StatusBadge :value="app.outcome" /></span>
            <div class="ms-auto d-flex gap-2">
                <router-link v-if="profile.can.scrutinize || profile.can.assign" :to="`/scrutiny/${uuid}`" class="btn btn-sm" :class="profile.can.scrutinize ? 'btn-primary' : 'btn-outline-primary'"><i class="bi bi-clipboard-check me-1"></i>Scrutiny workspace</router-link>
                <button class="btn btn-sm btn-outline-secondary" @click="reportPdf"><i class="bi bi-file-earmark-pdf me-1"></i>Scrutiny report PDF</button>
            </div>
        </div>

        <div class="card section-card">
            <div class="card-header">Assignments</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Stage</th><th>Officer</th><th>Status</th><th>Assigned</th><th>Submitted</th></tr></thead>
                    <tbody>
                        <tr v-for="a in s.assignments" :key="a.id"><td>{{ a.stage ?? '—' }}</td><td>{{ a.user ?? '—' }}</td><td><StatusBadge :value="a.status" /></td><td class="small">{{ formatDateTime(a.assigned_at) }}</td><td class="small">{{ formatDateTime(a.submitted_at) }}</td></tr>
                        <tr v-if="!s.assignments.length"><td colspan="5" class="text-muted small">Not yet assigned.</td></tr>
                    </tbody>
                </table>
            </div>
            <div v-if="s.stages.length" class="card-footer small text-muted">
                Workflow: <span v-for="(st, i) in s.stages" :key="st.id">{{ i ? ' → ' : '' }}{{ st.name }} ({{ humanize(st.role) }}){{ st.is_decision_stage ? ' ★' : '' }}</span>
            </div>
        </div>

        <div class="card section-card">
            <div class="card-header">Stage reports</div>
            <div class="card-body">
                <div v-for="(r, i) in s.reviews" :key="i" class="border rounded p-2 mb-2">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <strong>{{ r.stage }}</strong>
                        <span class="small text-muted">{{ r.by }} · {{ formatDateTime(r.submitted_at) }}</span>
                    </div>
                    <div class="small mt-1 d-flex flex-wrap gap-3">
                        <span v-if="r.completeness">Completeness: <StatusBadge :value="r.completeness" /></span>
                        <span v-if="r.recommendation">Recommendation: <StatusBadge :value="r.recommendation" /></span>
                        <span v-if="r.decision">Decision: <StatusBadge :value="r.decision" /></span>
                    </div>
                    <div v-if="r.summary" class="small text-pre mt-1">{{ r.summary }}</div>
                    <div v-if="r.reasons" class="small text-pre mt-1 text-muted">Reasons: {{ r.reasons }}</div>
                </div>
                <p v-if="!s.reviews.length" class="text-muted small mb-0">No stage report submitted yet.</p>
            </div>
        </div>

        <div class="card section-card">
            <div class="card-header d-flex justify-content-between"><span>Checklist verdicts</span><span class="small fw-normal text-muted">{{ s.checklist.length }} items</span></div>
            <div class="card-body">
                <div class="progress mb-2" style="height: 1.1rem" role="img" aria-label="Checklist verdict distribution">
                    <div v-for="(n, k) in verdicts" :key="k" class="progress-bar" :class="BAR[k] ?? 'bg-info'" :style="{ width: (n / total) * 100 + '%' }" :title="`${humanize(String(k))}: ${n}`">{{ n }}</div>
                </div>
                <div class="d-flex flex-wrap gap-3 small mb-3">
                    <span v-for="(n, k) in verdicts" :key="k"><StatusBadge :value="String(k)" /> {{ n }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Item</th><th>Candidate</th><th>Verdict</th><th>Remarks</th><th>By</th></tr></thead>
                        <tbody>
                            <tr v-for="c in s.checklist" :key="c.checklist_item_id">
                                <td class="small">{{ c.label }}<span v-if="c.is_mandatory" class="text-danger"> *</span></td>
                                <td class="small">{{ c.candidate_status ?? '—' }}<span v-if="c.candidate_page_reference" class="text-muted"> ({{ c.candidate_page_reference }})</span></td>
                                <td><StatusBadge :value="c.status" /></td>
                                <td class="small">{{ c.discrepancy_reason || c.remarks || '—' }}</td>
                                <td class="small text-muted text-nowrap">{{ c.verified_by ?? '' }}<br v-if="c.verified_by">{{ c.verified_at ? formatDateTime(c.verified_at) : '' }}</td>
                            </tr>
                            <tr v-if="!s.checklist.length"><td colspan="5" class="text-muted small">No checklist configured.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card section-card">
            <div class="card-header">Observations</div>
            <ul class="list-group list-group-flush">
                <li v-for="o in s.observations" :key="o.id" class="list-group-item small">
                    <div class="d-flex justify-content-between gap-2">
                        <span>
                            <span v-if="o.is_deficiency" class="badge text-bg-danger me-1">Deficiency</span>
                            <span v-if="o.section" class="badge text-bg-light border me-1">{{ humanize(o.section) }}</span>
                            <span v-if="o.resolved_at" class="badge text-bg-success me-1">Resolved {{ formatDateTime(o.resolved_at) }}</span>
                        </span>
                        <span class="text-muted text-nowrap">{{ o.by }} · {{ formatDateTime(o.at) }}</span>
                    </div>
                    <div class="text-pre mt-1">{{ o.observation }}</div>
                </li>
                <li v-if="!s.observations.length" class="list-group-item small text-muted">No observations.</li>
            </ul>
        </div>

        <div class="card section-card">
            <div class="card-header">Eligibility checks <span class="small fw-normal text-muted">(advisory — based on declared data)</span></div>
            <ul class="list-group list-group-flush">
                <li v-for="(e, i) in s.eligibility" :key="i" class="list-group-item small d-flex gap-2">
                    <i class="bi" :class="[ELIG[e.result]?.icon ?? 'bi-question-circle', ELIG[e.result]?.cls ?? '']"></i>
                    <div class="flex-grow-1">
                        <div class="fw-semibold">{{ e.label }} <span v-if="e.is_essential" class="badge text-bg-light border">Essential</span></div>
                        <div class="text-muted">{{ e.basis }}</div>
                    </div>
                    <span :class="ELIG[e.result]?.cls">{{ ELIG[e.result]?.label ?? humanize(e.result) }}</span>
                </li>
                <li v-if="!s.eligibility.length" class="list-group-item small text-muted">No eligibility criteria configured for this post.</li>
            </ul>
        </div>
    </div>
</template>
