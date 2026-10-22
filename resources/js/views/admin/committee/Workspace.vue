<script setup lang="ts">
/**
 * Screening Committee workspace (spec 11). The application, documents,
 * referee recommendations and scrutiny record are read-only; members add only
 * their own evaluation, and the chair (or an authorised officer) records the
 * committee decision.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import LoadingState from '@/components/LoadingState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import FieldError from '@/components/FieldError.vue';
import BaseModal from '@/components/BaseModal.vue';
import ApplicationSnapshot from '@/components/staff/ApplicationSnapshot.vue';
import DocumentTable from '@/components/staff/DocumentTable.vue';
import { formatDateTime, humanize } from '@/utils/format';
import { useAuth } from '@/stores/auth';
import EvaluationField, { type FieldValue, type FormField } from './parts/EvaluationField.vue';
import RecommendationModal from './parts/RecommendationModal.vue';
import ScrutinyReportPanel from './parts/ScrutinyReportPanel.vue';
import type { ApplicationProfile, ScrutinyReport } from '../scrutiny/parts/types';

interface Evaluation { responses: Record<string, FieldValue> | unknown[]; recommendation: string; reasons: string | null; status: 'draft' | 'submitted'; submitted_at: string | null }
interface CommitteeContext {
    committee: { id: number; name: string; is_chair: boolean; members: { id: number; name: string }[] };
    form: { mode: 'individual' | 'collective'; fields: FormField[]; recommendation_options: string[]; decisions: string[] };
    my_evaluation: Evaluation | null;
    evaluations: { member: string | null; responses: Record<string, FieldValue> | unknown[]; recommendation: string; reasons: string | null; submitted_at: string | null }[];
    evaluation_summary: Record<string, number> | unknown[];
    decision: { decision: string; reasons: string; responses: Record<string, FieldValue> | null; decided_at: string | null; recorder?: { id: number; name: string } | null } | null;
    can_decide: boolean;
}

const props = defineProps<{ uuid: string }>();
const auth = useAuth();

const loading = ref(true);
const error = ref<string | null>(null);
const profile = ref<ApplicationProfile | null>(null);
const ctx = ref<CommitteeContext | null>(null);
const report = ref<ScrutinyReport | null>(null);
const reportError = ref<string | null>(null);
const tab = ref<'application' | 'documents' | 'recommendations' | 'scrutiny'>('application');
const openRec = ref<number | null>(null);

const asRecord = (v: unknown): Record<string, FieldValue> => (v && !Array.isArray(v) && typeof v === 'object' ? (v as Record<string, FieldValue>) : {});

// Individual evaluation
const evalForm = useForm();
const evaluation = reactive({ responses: {} as Record<string, FieldValue>, recommendation: '', reasons: '' });
const confirmingEval = ref(false);

// Committee decision
const decForm = useForm();
const decision = reactive({ decision: '', reasons: '', responses: {} as Record<string, FieldValue> });
const confirmingDecision = ref(false);
const editingDecision = ref(false);

function fillForms() {
    const c = ctx.value;
    if (!c) return;
    const mine = c.my_evaluation;
    evaluation.responses = Object.fromEntries(c.form.fields.map((f) => [f.code, asRecord(mine?.responses)[f.code] ?? null]));
    evaluation.recommendation = mine?.recommendation ?? '';
    evaluation.reasons = mine?.reasons ?? '';
    decision.decision = c.decision?.decision ?? '';
    decision.reasons = c.decision?.reasons ?? '';
    decision.responses = Object.fromEntries(c.form.fields.map((f) => [f.code, asRecord(c.decision?.responses)[f.code] ?? null]));
    editingDecision.value = !c.decision;
}

async function load(initial = false) {
    if (initial) loading.value = true;
    error.value = null;
    try {
        const [p, c] = await Promise.all([
            api.get(`/admin/applications/${props.uuid}`),
            api.get(`/admin/applications/${props.uuid}/committee`),
        ]);
        profile.value = p.data;
        ctx.value = c.data;
        fillForms();
    } catch (e) {
        const ae = toApiError(e);
        error.value = ae.status === 403 ? (ae.message && ae.message !== 'This action is unauthorized.' ? ae.message : 'This application is not assigned to your committee.') : ae.message;
    } finally {
        loading.value = false;
    }
    api.get(`/admin/applications/${props.uuid}/scrutiny/report`)
        .then((r) => { report.value = r.data; reportError.value = null; })
        .catch((e) => { reportError.value = toApiError(e).message; });
}
onMounted(() => load(true));
watch(() => props.uuid, () => load(true));

const app = computed(() => profile.value?.application);
const mode = computed(() => ctx.value?.form.mode ?? 'individual');
const fields = computed(() => ctx.value?.form.fields ?? []);
const submitted = computed(() => ctx.value?.my_evaluation?.status === 'submitted');
/** Only members record individual evaluations (a super admin viewing the workspace is not one). */
const isMember = computed(() => !!ctx.value?.committee.members.some((m) => m.id === auth.user?.id));
const summary = computed(() => Object.entries(asRecord(ctx.value?.evaluation_summary)) as [string, number][]);

const fieldLabel = (code: string) => fields.value.find((f) => f.code === code)?.label ?? humanize(code);
function displayValue(code: string, v: FieldValue): string {
    if (v === null || v === undefined || v === '') return '—';
    const f = fields.value.find((x) => x.code === code);
    if (f?.type === 'yes_no') return v === 'yes' ? 'Yes' : 'No';
    if (f?.type === 'rating') {
        const lbl = f.options?.labels?.[String(v)];
        return `${v} / ${f.options?.max ?? 5}${lbl ? ' (' + lbl + ')' : ''}`;
    }
    return String(v);
}

const missingRequired = computed(() => fields.value.filter((f) => f.is_required && (evaluation.responses[f.code] === null || evaluation.responses[f.code] === '')));
const missingDecisionFields = computed(() => mode.value === 'collective'
    ? fields.value.filter((f) => f.is_required && (decision.responses[f.code] === null || decision.responses[f.code] === ''))
    : []);

async function saveEvaluation(submit: boolean) {
    const res = await evalForm.run(() => api.post(`/admin/applications/${props.uuid}/committee/evaluation`, {
        responses: evaluation.responses,
        recommendation: evaluation.recommendation || null,
        reasons: evaluation.reasons.trim() || null,
        submit,
    }), submit ? 'Evaluation submitted.' : 'Draft saved.');
    confirmingEval.value = false;
    if (res) await load();
}

async function saveDecision() {
    const res = await decForm.run(() => api.post(`/admin/applications/${props.uuid}/committee/decision`, {
        decision: decision.decision,
        reasons: decision.reasons.trim(),
        responses: mode.value === 'collective' ? decision.responses : null,
    }), 'Committee decision recorded.');
    confirmingDecision.value = false;
    if (res) await load();
}

const decisionLabel = (d: string) => ({ recommended: 'Recommended', not_recommended: 'Not recommended', further_review: 'Requires further review' }[d] ?? humanize(d));
</script>

<template>
    <LoadingState :loading="loading" :error="error">
        <template v-if="profile && ctx && app">
            <div class="d-flex flex-wrap align-items-start gap-2 mb-3">
                <div class="flex-grow-1">
                    <router-link to="/committee" class="small"><i class="bi bi-arrow-left me-1"></i>Screening Committee</router-link>
                    <h1 class="page-title h4 mb-0 mt-1">{{ profile.snapshot.personal.full_name }}</h1>
                    <div class="small text-muted">{{ app.reference_no }} · {{ app.post.name }} · Advt. {{ app.advertisement.number }}<span v-if="profile.snapshot.personal.category"> · {{ profile.snapshot.personal.category }}</span></div>
                </div>
                <div class="text-end small">
                    <div class="fw-semibold">{{ ctx.committee.name }}</div>
                    <div class="text-muted">{{ mode === 'collective' ? 'Collective decision' : 'Individual evaluations' }}<span v-if="ctx.committee.is_chair"> · You are the chair</span></div>
                    <div class="mt-1">Scrutiny <StatusBadge :value="app.outcome ?? app.scrutiny_status" /> · Committee <StatusBadge :value="app.committee_status ?? 'pending'" /></div>
                    <router-link :to="`/committee/comparison/${ctx.committee.id}`" class="btn btn-sm btn-outline-secondary mt-1"><i class="bi bi-table me-1"></i>Comparison</router-link>
                </div>
            </div>

            <div class="alert alert-light border small py-2"><i class="bi bi-eye me-1"></i>Read-only: committee members cannot modify the application, its documents or the referee recommendations. You record only your own evaluation.</div>

            <div class="row g-3">
                <div class="col-lg-7">
                    <ul class="nav nav-tabs small mb-2">
                        <li class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'application' }" @click="tab = 'application'">Application</button></li>
                        <li class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'documents' }" @click="tab = 'documents'">Documents ({{ profile.documents.length }})</button></li>
                        <li v-if="profile.recommendations" class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'recommendations' }" @click="tab = 'recommendations'">Referee recommendations ({{ profile.recommendations.length }})</button></li>
                        <li class="nav-item"><button type="button" class="nav-link" :class="{ active: tab === 'scrutiny' }" @click="tab = 'scrutiny'">Scrutiny report</button></li>
                    </ul>

                    <ApplicationSnapshot v-if="tab === 'application'" :snapshot="profile.snapshot" />

                    <div v-else-if="tab === 'documents'" class="card">
                        <DocumentTable :application-uuid="uuid" :documents="profile.documents" :can-download="!!profile.can.download" :can-verify="false" />
                        <div v-if="!profile.can.download" class="card-footer small text-muted">Your role does not include opening documents.</div>
                    </div>

                    <div v-else-if="tab === 'recommendations' && profile.recommendations" class="card section-card">
                        <div class="card-header confidential-banner"><i class="bi bi-shield-lock me-1"></i>CONFIDENTIAL — completed referee recommendations</div>
                        <ul class="list-group list-group-flush">
                            <li v-for="r in profile.recommendations" :key="r.id" class="list-group-item d-flex flex-wrap align-items-center gap-2">
                                <div class="flex-grow-1 small">
                                    <div class="fw-semibold">Referee {{ r.position }} — {{ r.referee_name }}</div>
                                    <div class="text-muted">{{ r.reference_no }} · {{ formatDateTime(r.submitted_at) }}</div>
                                </div>
                                <span class="badge text-bg-light border">{{ r.overall_recommendation }}</span>
                                <button type="button" class="btn btn-sm btn-outline-primary" @click="openRec = r.id"><i class="bi bi-eye me-1"></i>Open</button>
                            </li>
                            <li v-if="!profile.recommendations.length" class="list-group-item small text-muted">No recommendation has been received.</li>
                        </ul>
                        <div v-if="profile.referees" class="card-footer small text-muted">
                            {{ profile.referees.filter((r) => r.recommendation_status === 'completed').length }} of {{ profile.referees.length }} referees have responded.
                        </div>
                    </div>

                    <template v-else-if="tab === 'scrutiny'">
                        <ScrutinyReportPanel v-if="report" :report="report" />
                        <div v-else-if="reportError" class="alert alert-warning small">{{ reportError }}</div>
                        <LoadingState v-else :loading="true" />
                    </template>
                </div>

                <div class="col-lg-5">
                    <!-- Individual evaluation -->
                    <div v-if="mode === 'individual' && isMember" class="card section-card mb-3">
                        <div class="card-header d-flex align-items-center">
                            <span class="flex-grow-1">My evaluation</span>
                            <StatusBadge :value="ctx.my_evaluation?.status ?? 'not_started'" />
                        </div>
                        <div class="card-body">
                            <div v-if="submitted" class="alert alert-success small py-2"><i class="bi bi-lock me-1"></i>Submitted {{ formatDateTime(ctx.my_evaluation?.submitted_at) }}. Submitted evaluations are locked.</div>
                            <EvaluationField v-for="f in fields" :key="f.code" v-model="evaluation.responses[f.code]" :field="f" :disabled="submitted || evalForm.busy.value"
                                :error="evalForm.errors[`responses.${f.code}`]?.[0]" id-prefix="ev" />
                            <p v-if="!fields.length" class="small text-muted">No evaluation criteria are configured; record your recommendation and reasons.</p>

                            <label class="form-label small fw-semibold required mb-1 d-block">Recommendation</label>
                            <div class="vstack gap-1 mb-1">
                                <div v-for="(o, i) in ctx.form.recommendation_options" :key="o" class="form-check">
                                    <input :id="`ev-rec-${i}`" v-model="evaluation.recommendation" class="form-check-input" type="radio" name="ev-rec" :value="o" :disabled="submitted">
                                    <label class="form-check-label small" :for="`ev-rec-${i}`">{{ o }}</label>
                                </div>
                            </div>
                            <FieldError :errors="evalForm.errors" name="recommendation" />

                            <label class="form-label small fw-semibold mt-2 mb-1" for="ev-reasons">Reasons / remarks</label>
                            <textarea id="ev-reasons" v-model="evaluation.reasons" class="form-control form-control-sm" rows="4" maxlength="10000" :disabled="submitted"></textarea>
                            <FieldError :errors="evalForm.errors" name="reasons" />
                            <FieldError :errors="evalForm.errors" name="evaluation" />
                            <FieldError :errors="evalForm.errors" name="mode" />

                            <div v-if="!submitted" class="d-flex gap-2 justify-content-end mt-3">
                                <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="evalForm.busy.value" @click="saveEvaluation(false)">Save draft</button>
                                <button type="button" class="btn btn-primary btn-sm" :disabled="evalForm.busy.value || !evaluation.recommendation || missingRequired.length > 0" @click="confirmingEval = true">Submit evaluation…</button>
                            </div>
                            <div v-if="!submitted && missingRequired.length" class="small text-muted text-end mt-1">{{ missingRequired.length }} required criterion/criteria unanswered.</div>
                        </div>
                    </div>

                    <!-- Evaluations by members -->
                    <div v-if="mode === 'individual'" class="card section-card mb-3">
                        <div class="card-header">Committee evaluations</div>
                        <div class="card-body small">
                            <div v-if="summary.length" class="d-flex flex-wrap gap-2 mb-2">
                                <span v-for="[rec, n] in summary" :key="rec" class="badge text-bg-light border">{{ rec }}: {{ n }}</span>
                            </div>
                            <p v-else class="text-muted">No evaluation has been submitted yet.</p>
                            <template v-if="ctx.evaluations.length">
                                <details v-for="(e, i) in ctx.evaluations" :key="i" class="border rounded p-2 mb-2">
                                    <summary><strong>{{ e.member ?? 'Member' }}</strong> — {{ e.recommendation }} <span class="text-muted">({{ formatDateTime(e.submitted_at) }})</span></summary>
                                    <table class="table table-sm mt-2 mb-1">
                                        <tbody><tr v-for="(v, code) in asRecord(e.responses)" :key="code"><td>{{ fieldLabel(String(code)) }}</td><td class="fw-semibold">{{ displayValue(String(code), v) }}</td></tr></tbody>
                                    </table>
                                    <div v-if="e.reasons" class="text-pre">{{ e.reasons }}</div>
                                </details>
                            </template>
                            <p v-else-if="summary.length" class="text-muted mb-0">Individual assessments become visible once you submit your own evaluation.</p>
                        </div>
                    </div>

                    <!-- Committee decision -->
                    <div v-if="mode === 'collective' || ctx.can_decide || ctx.decision" class="card section-card">
                        <div class="card-header">Committee decision</div>
                        <div class="card-body">
                            <div v-if="ctx.decision" class="mb-3">
                                <div class="d-flex align-items-center gap-2"><StatusBadge :value="ctx.decision.decision" :label="decisionLabel(ctx.decision.decision)" /></div>
                                <div class="small text-pre mt-1">{{ ctx.decision.reasons }}</div>
                                <div class="small text-muted mt-1">Recorded by {{ ctx.decision.recorder?.name ?? '—' }}, {{ formatDateTime(ctx.decision.decided_at) }}</div>
                                <table v-if="ctx.decision.responses && Object.keys(ctx.decision.responses).length" class="table table-sm small mt-2 mb-0">
                                    <tbody><tr v-for="(v, code) in ctx.decision.responses" :key="code"><td>{{ fieldLabel(String(code)) }}</td><td class="fw-semibold">{{ displayValue(String(code), v) }}</td></tr></tbody>
                                </table>
                                <button v-if="ctx.can_decide && !editingDecision" type="button" class="btn btn-sm btn-link px-0" @click="editingDecision = true">Revise decision</button>
                            </div>
                            <p v-else-if="!ctx.can_decide" class="small text-muted mb-0">The decision is recorded by the committee chair.</p>

                            <template v-if="ctx.can_decide && editingDecision">
                                <template v-if="mode === 'collective'">
                                    <EvaluationField v-for="f in fields" :key="f.code" v-model="decision.responses[f.code]" :field="f" :disabled="decForm.busy.value"
                                        :error="decForm.errors[`responses.${f.code}`]?.[0]" id-prefix="dc" />
                                </template>
                                <label class="form-label small fw-semibold required d-block mb-1">Decision</label>
                                <div class="btn-group btn-group-sm flex-wrap mb-1" role="radiogroup">
                                    <template v-for="d in ctx.form.decisions" :key="d">
                                        <input :id="`dc-${d}`" v-model="decision.decision" class="btn-check" type="radio" name="dc-decision" :value="d">
                                        <label class="btn" :class="d === 'recommended' ? 'btn-outline-success' : d === 'not_recommended' ? 'btn-outline-danger' : 'btn-outline-warning'" :for="`dc-${d}`">{{ decisionLabel(d) }}</label>
                                    </template>
                                </div>
                                <FieldError :errors="decForm.errors" name="decision" />
                                <label class="form-label small fw-semibold required mt-2 mb-1" for="dc-reasons">Reasons</label>
                                <textarea id="dc-reasons" v-model="decision.reasons" class="form-control form-control-sm" :class="{ 'is-invalid': decForm.errors.reasons }" rows="4" maxlength="10000"></textarea>
                                <FieldError :errors="decForm.errors" name="reasons" />
                                <div class="d-flex gap-2 justify-content-end mt-3">
                                    <button v-if="ctx.decision" type="button" class="btn btn-sm btn-secondary" @click="fillForms">Cancel</button>
                                    <button type="button" class="btn btn-sm btn-primary" :disabled="decForm.busy.value || !decision.decision || !decision.reasons.trim() || missingDecisionFields.length > 0" @click="confirmingDecision = true">Record decision…</button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <RecommendationModal v-if="openRec" :application-uuid="uuid" :response-id="openRec" @close="openRec = null" />

            <BaseModal v-if="confirmingEval" title="Submit evaluation" @close="confirmingEval = false">
                <p class="small">Once submitted, your evaluation is locked and cannot be changed.</p>
                <table class="table table-sm small">
                    <tbody>
                        <tr v-for="f in fields" :key="f.code"><td>{{ f.label }}</td><td class="fw-semibold">{{ displayValue(f.code, evaluation.responses[f.code]) }}</td></tr>
                        <tr><td>Recommendation</td><td class="fw-semibold">{{ evaluation.recommendation }}</td></tr>
                    </tbody>
                </table>
                <template #footer>
                    <button type="button" class="btn btn-secondary" @click="confirmingEval = false">Cancel</button>
                    <button type="button" class="btn btn-primary" :disabled="evalForm.busy.value" @click="saveEvaluation(true)">Confirm and submit</button>
                </template>
            </BaseModal>

            <BaseModal v-if="confirmingDecision" title="Record committee decision" @close="confirmingDecision = false">
                <p class="small">Record <strong>{{ decisionLabel(decision.decision) }}</strong> as the decision of {{ ctx.committee.name }} for {{ app.reference_no }}? {{ ctx.decision ? 'The previous decision will be superseded (the change is audited).' : '' }}</p>
                <div class="small text-pre border rounded p-2">{{ decision.reasons }}</div>
                <template #footer>
                    <button type="button" class="btn btn-secondary" @click="confirmingDecision = false">Cancel</button>
                    <button type="button" class="btn btn-primary" :disabled="decForm.busy.value" @click="saveDecision">Confirm</button>
                </template>
            </BaseModal>
        </template>
    </LoadingState>
</template>
