<script setup lang="ts">
/**
 * Scrutiny workspace (spec 10.2–10.4). Left: the structured checklist with a
 * verdict per item. Right: the frozen application, documents, referee status,
 * advisory eligibility, observations and clarifications. Scrutiny never edits
 * the candidate's application — verdicts, observations and clarifications are
 * separate records.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { api, download, toApiError } from '@/api/client';
import LoadingState from '@/components/LoadingState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ApplicationSnapshot from '@/components/staff/ApplicationSnapshot.vue';
import DocumentTable from '@/components/staff/DocumentTable.vue';
import { useAuth } from '@/stores/auth';
import { useToast } from '@/stores/toast';
import { formatDateTime, humanize } from '@/utils/format';
import ChecklistItemCard from './parts/ChecklistItemCard.vue';
import ObservationsPanel from './parts/ObservationsPanel.vue';
import ClarificationsPanel from './parts/ClarificationsPanel.vue';
import StageReportForm from './parts/StageReportForm.vue';
import EligibilityTable from './parts/EligibilityTable.vue';
import RefereeStatusTable from './parts/RefereeStatusTable.vue';
import RecommendationModal from '../committee/parts/RecommendationModal.vue';
import type { ApplicationProfile, ChecklistEntry, ScrutinyContext } from './parts/types';

const props = defineProps<{ uuid: string }>();
const auth = useAuth();
const toast = useToast();

const loading = ref(true);
const error = ref<string | null>(null);
const profile = ref<ApplicationProfile | null>(null);
const ctx = ref<ScrutinyContext | null>(null);
const tab = ref<'application' | 'documents' | 'referees' | 'eligibility' | 'observations' | 'clarifications' | 'reports'>('application');
const filter = ref<'all' | 'pending' | 'not_verified' | 'mandatory'>('all');
const openRec = ref<number | null>(null);

async function load(initial = false) {
    if (initial) loading.value = true;
    error.value = null;
    try {
        const [p, c] = await Promise.all([
            api.get(`/admin/applications/${props.uuid}`),
            api.get(`/admin/applications/${props.uuid}/scrutiny`),
        ]);
        profile.value = p.data;
        ctx.value = c.data;
    } catch (e) {
        const ae = toApiError(e);
        error.value = ae.status === 403 ? 'You do not have access to this application.' : ae.status === 404 ? 'Application not found.' : ae.message;
    } finally {
        loading.value = false;
    }
}
onMounted(() => load(true));
watch(() => props.uuid, () => load(true));

const app = computed(() => profile.value?.application);
const checklist = computed<ChecklistEntry[]>(() => ctx.value?.checklist ?? []);
const canAct = computed(() => !!ctx.value?.can_act);
const canOpen = computed(() => !!profile.value?.can.download);

const mandatory = computed(() => checklist.value.filter((i) => i.is_mandatory));
const pendingMandatory = computed(() => mandatory.value.filter((i) => i.status === 'pending').length);
const decided = computed(() => checklist.value.filter((i) => i.status !== 'pending').length);
const notVerified = computed(() => checklist.value.filter((i) => i.status === 'not_verified').length);
const progressPct = computed(() => checklist.value.length ? Math.round((decided.value / checklist.value.length) * 100) : 0);
const openClarifications = computed(() => (profile.value?.clarifications ?? []).filter((c) => c.status === 'open').length);
const awaitingAction = computed(() => (profile.value?.clarifications ?? []).filter((c) => ['open', 'responded'].includes(c.status)).length);
const openObservations = computed(() => (profile.value?.scrutiny.observations ?? []).filter((o) => !o.resolved_at).length);

const filtered = computed(() => checklist.value.filter((i) => filter.value === 'all'
    || (filter.value === 'pending' && i.status === 'pending')
    || (filter.value === 'not_verified' && i.status === 'not_verified')
    || (filter.value === 'mandatory' && i.is_mandatory)));
/** Checklist grouped by section, preserving the configured order. */
const groups = computed(() => {
    const out: { section: string; items: ChecklistEntry[] }[] = [];
    for (const item of filtered.value) {
        const key = item.section || 'general';
        let g = out.find((x) => x.section === key);
        if (!g) out.push((g = { section: key, items: [] }));
        g.items.push(item);
    }
    return out;
});
const sectionTitle = (key: string) => profile.value?.section_keys.find((s) => s.key === key)?.title ?? humanize(key);

/** Why the workspace is read-only, in plain words. */
const readOnlyReason = computed(() => {
    if (!ctx.value || canAct.value) return null;
    if (!ctx.value.stage) return 'This application has not yet been placed in a scrutiny stage.';
    const mine = (profile.value?.scrutiny.assignments ?? []).filter((a) => a.user_id === auth.user?.id);
    const here = mine.find((a) => a.stage_id === ctx.value!.stage!.id);
    if (here?.status === 'submitted') return `You have already submitted your report for “${ctx.value.stage.name}”. The workspace is read-only.`;
    if (ctx.value.assignment) return 'Your account does not hold the scrutiny permission for this application.';
    if (mine.some((a) => a.status === 'submitted')) return `Your stage report has been submitted; the application is now at “${ctx.value.stage.name}”, which is not assigned to you.`;
    return `You are not assigned to the current stage (“${ctx.value.stage.name}”) of this application. You can read it but not record verdicts.`;
});

function onItemSaved(list: ChecklistEntry[]) {
    if (ctx.value) ctx.value.checklist = list;
}

function reportPdf() {
    download(`/admin/applications/${props.uuid}/scrutiny-report.pdf`, `Scrutiny-Report-${(app.value?.reference_no ?? props.uuid).replace(/\//g, '-')}.pdf`)
        .catch((e) => toast.error(toApiError(e).message));
}

async function onSubmitted() {
    await load();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

const tabs = computed(() => [
    { key: 'application', label: 'Application', icon: 'bi-file-text' },
    { key: 'documents', label: `Documents (${profile.value?.documents.length ?? 0})`, icon: 'bi-paperclip' },
    { key: 'referees', label: 'Referees', icon: 'bi-people', hidden: !profile.value?.referees && !profile.value?.recommendations },
    { key: 'eligibility', label: 'Eligibility', icon: 'bi-clipboard-check' },
    { key: 'observations', label: `Observations${openObservations.value ? ` (${openObservations.value})` : ''}`, icon: 'bi-chat-left-text' },
    { key: 'clarifications', label: `Clarifications${awaitingAction.value ? ` (${awaitingAction.value})` : ''}`, icon: 'bi-envelope' },
    { key: 'reports', label: 'Stage reports', icon: 'bi-journal-check' },
].filter((t) => !t.hidden) as { key: typeof tab.value; label: string; icon: string }[]);
</script>

<template>
    <LoadingState :loading="loading" :error="error">
        <template v-if="profile && ctx && app">
            <div class="d-flex flex-wrap align-items-start gap-2 mb-3">
                <div class="flex-grow-1">
                    <router-link to="/scrutiny" class="small"><i class="bi bi-arrow-left me-1"></i>Scrutiny queue</router-link>
                    <h1 class="page-title h4 mb-0 mt-1">{{ profile.snapshot.personal.full_name }}</h1>
                    <div class="small text-muted">
                        {{ app.reference_no ?? 'No reference' }} · {{ app.post.name }} · Advt. {{ app.advertisement.number }}
                        <span v-if="profile.snapshot.personal.category"> · {{ profile.snapshot.personal.category }}</span>
                    </div>
                </div>
                <div class="text-end">
                    <div class="mb-1">
                        <span v-if="ctx.stage" class="badge text-bg-info me-1">{{ ctx.stage.name }}{{ ctx.stage.is_decision_stage ? ' · decision stage' : '' }}</span>
                        <StatusBadge :value="app.scrutiny_status" />
                        <StatusBadge v-if="app.outcome" :value="app.outcome" class="ms-1" />
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="reportPdf"><i class="bi bi-file-earmark-pdf me-1"></i>Scrutiny report PDF</button>
                </div>
            </div>

            <div v-if="readOnlyReason" class="alert alert-secondary small d-flex gap-2"><i class="bi bi-lock"></i><div>{{ readOnlyReason }}</div></div>
            <div v-if="profile.live_differs" class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1"></i>The candidate has reopened this application; you are reviewing the last submitted version.</div>
            <div class="alert alert-light border small py-2"><i class="bi bi-shield-check me-1"></i>Scrutiny records verdicts, observations and clarifications only. The candidate’s submitted application cannot be changed from here.</div>

            <div class="card mb-3">
                <div class="card-body py-2">
                    <div class="d-flex flex-wrap gap-3 small align-items-center">
                        <span><strong>{{ decided }}</strong> of {{ checklist.length }} items recorded</span>
                        <span :class="pendingMandatory ? 'text-warning-emphasis fw-semibold' : 'text-success'">
                            <i class="bi" :class="pendingMandatory ? 'bi-hourglass-split' : 'bi-check2-all'"></i>
                            {{ pendingMandatory ? `${pendingMandatory} mandatory item(s) pending` : 'All mandatory items recorded' }}
                        </span>
                        <span v-if="notVerified" class="text-danger"><i class="bi bi-x-circle"></i> {{ notVerified }} not verified</span>
                        <span class="ms-auto text-muted">Fee <StatusBadge :value="app.fee_status" /> · Documents <StatusBadge :value="app.document_status" /> · Referees <StatusBadge :value="app.referee_status" /></span>
                    </div>
                    <div class="progress mt-2" style="height: 6px" role="progressbar" :aria-valuenow="progressPct" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" :class="pendingMandatory ? 'bg-warning' : 'bg-success'" :style="{ width: progressPct + '%' }"></div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <!-- Left: checklist and stage report -->
                <div class="col-xl-6">
                    <div class="d-flex align-items-center mb-2">
                        <h2 class="h6 mb-0 flex-grow-1">Scrutiny checklist</h2>
                        <select v-model="filter" class="form-select form-select-sm w-auto" aria-label="Filter checklist">
                            <option value="all">All items</option>
                            <option value="pending">Pending</option>
                            <option value="not_verified">Not verified</option>
                            <option value="mandatory">Mandatory</option>
                        </select>
                    </div>
                    <div v-for="g in groups" :key="g.section" class="mb-3">
                        <div class="small text-uppercase text-muted fw-semibold mb-1">{{ sectionTitle(g.section) }}</div>
                        <div class="vstack gap-2">
                            <ChecklistItemCard v-for="item in g.items" :key="item.checklist_item_id" :application-uuid="uuid" :item="item"
                                :documents="profile.documents" :read-only="!canAct" :can-open="canOpen" @saved="onItemSaved" />
                        </div>
                    </div>
                    <p v-if="!filtered.length" class="text-muted small">{{ checklist.length ? 'No items match this filter.' : 'No checklist is configured for this application.' }}</p>

                    <StageReportForm v-if="canAct && ctx.stage" class="mt-3" :application-uuid="uuid" :stage="ctx.stage" :draft="ctx.draft_review"
                        :pending-mandatory="pendingMandatory" :open-clarifications="openClarifications" @submitted="onSubmitted" />
                </div>

                <!-- Right: application and supporting panels -->
                <div class="col-xl-6">
                    <div class="ws-right">
                        <ul class="nav nav-tabs small flex-nowrap overflow-auto mb-2" role="tablist">
                            <li v-for="t in tabs" :key="t.key" class="nav-item">
                                <button type="button" class="nav-link text-nowrap" :class="{ active: tab === t.key }" role="tab" :aria-selected="tab === t.key" @click="tab = t.key">
                                    <i class="bi me-1" :class="t.icon"></i>{{ t.label }}
                                </button>
                            </li>
                        </ul>
                        <div class="ws-scroll">
                            <ApplicationSnapshot v-if="tab === 'application'" :snapshot="profile.snapshot" />

                            <div v-else-if="tab === 'documents'" class="card">
                                <DocumentTable :application-uuid="uuid" :documents="profile.documents" :can-download="canOpen" :can-verify="profile.can.verify" @verified="load()" />
                            </div>

                            <div v-else-if="tab === 'referees'" class="vstack gap-3">
                                <div v-if="profile.referees" class="card"><RefereeStatusTable :rows="profile.referees" /></div>
                                <div v-if="profile.recommendations" class="card section-card">
                                    <div class="card-header"><i class="bi bi-shield-lock me-1"></i>Recommendations received (confidential)</div>
                                    <ul class="list-group list-group-flush">
                                        <li v-for="r in profile.recommendations" :key="r.id" class="list-group-item d-flex align-items-center gap-2 small">
                                            <span class="flex-grow-1">Referee {{ r.position }} — {{ r.referee_name }} <span class="text-muted">({{ formatDateTime(r.submitted_at) }})</span></span>
                                            <button type="button" class="btn btn-sm btn-outline-primary" @click="openRec = r.id">Open</button>
                                        </li>
                                        <li v-if="!profile.recommendations.length" class="list-group-item small text-muted">None received yet.</li>
                                    </ul>
                                </div>
                            </div>

                            <div v-else-if="tab === 'eligibility'" class="card"><div class="card-body"><EligibilityTable :rows="profile.scrutiny.eligibility" /></div></div>

                            <ObservationsPanel v-else-if="tab === 'observations'" :application-uuid="uuid" :observations="profile.scrutiny.observations"
                                :sections="profile.section_keys" :can-act="canAct" @changed="load()" />

                            <ClarificationsPanel v-else-if="tab === 'clarifications'" :application-uuid="uuid" :clarifications="profile.clarifications"
                                :checklist="checklist" :can-correspond="ctx.can_correspond" :can-open="canOpen" @changed="load()" />

                            <div v-else-if="tab === 'reports'" class="vstack gap-2">
                                <div v-for="(r, i) in profile.scrutiny.reviews" :key="i" class="card section-card">
                                    <div class="card-header d-flex"><span class="flex-grow-1">{{ r.stage }}</span><span class="small fw-normal text-muted">{{ r.by }}, {{ formatDateTime(r.submitted_at) }}</span></div>
                                    <div class="card-body small">
                                        <div v-if="r.decision">Decision: <StatusBadge :value="r.decision" /></div>
                                        <div v-if="r.completeness">Completeness: <StatusBadge :value="r.completeness" /> · Recommendation: <strong>{{ humanize(r.recommendation) }}</strong></div>
                                        <div v-if="r.reasons" class="text-pre mt-1"><span class="text-muted">Reasons:</span> {{ r.reasons }}</div>
                                        <div v-if="r.summary" class="text-pre mt-1"><span class="text-muted">Summary:</span> {{ r.summary }}</div>
                                    </div>
                                </div>
                                <p v-if="!profile.scrutiny.reviews.length" class="text-muted small">No stage report has been submitted yet.</p>
                                <div class="card">
                                    <div class="card-header small fw-semibold">Assignments</div>
                                    <ul class="list-group list-group-flush small">
                                        <li v-for="a in profile.scrutiny.assignments" :key="a.id" class="list-group-item d-flex gap-2">
                                            <span class="flex-grow-1">{{ a.stage }} — {{ a.user }}</span><StatusBadge :value="a.status" />
                                        </li>
                                        <li v-if="!profile.scrutiny.assignments.length" class="list-group-item text-muted">Not yet assigned.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <RecommendationModal v-if="openRec" :application-uuid="uuid" :response-id="openRec" @close="openRec = null" />
        </template>
    </LoadingState>
</template>

<style scoped>
@media (min-width: 1200px) {
    .ws-right { position: sticky; top: 1rem; }
    .ws-scroll { max-height: calc(100vh - 6rem); overflow-y: auto; }
}
</style>
