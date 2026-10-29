<script setup lang="ts">
/**
 * Detailed staff view of one application. Each tab and action is shown
 * only when the server's `can` flags allow it.
 */
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, download, openInline, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import { useToast } from '@/stores/toast';
import BaseModal from '@/components/BaseModal.vue';
import FieldError from '@/components/FieldError.vue';
import LoadingState from '@/components/LoadingState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ApplicationSnapshot from '@/components/staff/ApplicationSnapshot.vue';
import DocumentTable from '@/components/staff/DocumentTable.vue';
import { formatDate, formatDateTime, formatMoney, humanize } from '@/utils/format';
import RefereesTab from './parts/RefereesTab.vue';
import ScrutinyTab from './parts/ScrutinyTab.vue';
import AuditDiff from './parts/AuditDiff.vue';
import { loadUserOptions, type UserOption } from './parts/options';
import type { AuditEntry, Profile, RefereeRow } from './parts/profileTypes';

const props = defineProps<{ uuid: string }>();
const route = useRoute();
const router = useRouter();
const toast = useToast();

const profile = ref<Profile | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const base = computed(() => `/admin/applications/${props.uuid}`);

async function load(quiet = false) {
    if (!quiet) loading.value = true;
    error.value = null;
    try {
        profile.value = (await api.get<Profile>(base.value)).data;
        document.title = `${profile.value.application.reference_no ?? 'Application'} — Non-Teaching Recruitment — ABV-IIITM Gwalior`;
    } catch (e) {
        error.value = toApiError(e).message;
    } finally {
        loading.value = false;
    }
}

const app = computed(() => profile.value?.application);
const can = computed(() => profile.value?.can);
const personal = computed(() => profile.value?.snapshot.personal);

// ------------------------------------------------------------ tabs
const tabs = computed(() => {
    const p = profile.value;
    if (!p) return [];
    return [
        { key: 'overview', label: 'Overview', icon: 'info-circle' },
        { key: 'application', label: 'Application', icon: 'file-earmark-text' },
        { key: 'documents', label: `Documents (${p.documents.length})`, icon: 'paperclip' },
        { key: 'fee', label: 'Fee', icon: 'currency-rupee' },
        ...(p.can.referee_status ? [{ key: 'referees', label: 'Referees', icon: 'person-lines-fill' }] : []),
        { key: 'scrutiny', label: 'Scrutiny', icon: 'clipboard-check' },
        { key: 'clarifications', label: `Clarifications (${p.clarifications.length})`, icon: 'chat-left-text' },
        { key: 'endorsement', label: 'Endorsement', icon: 'building-check' },
        { key: 'committee', label: 'Committee', icon: 'people' },
        ...(p.can.history ? [{ key: 'history', label: 'History', icon: 'clock-history' }] : []),
        ...(p.can.audit ? [{ key: 'audit', label: 'Audit', icon: 'journal-text' }] : []),
    ];
});
const tab = computed(() => {
    const t = typeof route.query.tab === 'string' ? route.query.tab : 'overview';
    return tabs.value.some((x) => x.key === t) ? t : 'overview';
});
const setTab = (key: string) => router.replace({ query: { ...route.query, tab: key === 'overview' ? undefined : key } });

// ------------------------------------------------------------ history and audit (lazy)
const statusHistory = ref<{ field: string; from: string | null; to: string | null; remarks: string | null; by: string | null; at: string | null }[] | null>(null);
const audit = ref<AuditEntry[] | null>(null);
const lazyError = ref<string | null>(null);
const auditOpen = reactive(new Set<number>());
watch(tab, async (t) => {
    lazyError.value = null;
    try {
        if (t === 'history' && !statusHistory.value) statusHistory.value = (await api.get(`${base.value}/history`)).data;
        if (t === 'audit' && !audit.value) audit.value = (await api.get(`${base.value}/audit`)).data;
    } catch (e) { lazyError.value = toApiError(e).message; }
}, { immediate: true });

// ------------------------------------------------------------ downloads
const fail = (e: unknown) => toast.error(toApiError(e).message);
const refSlug = computed(() => (app.value?.reference_no ?? props.uuid).replace(/\//g, '-'));
const versionPdf = (v: number) => download(`${base.value}/versions/${v}/pdf`, `Application-${refSlug.value}-v${v}.pdf`).catch(fail);
const versionView = (v: number) => openInline(`${base.value}/versions/${v}/pdf`).catch(fail);

// ------------------------------------------------------------ admin actions
const action = ref<'received' | 'assign' | 'reopen' | null>(null);
const form = useForm();
const assignForm = reactive({ stage_id: '', user_id: '' });
const officers = ref<UserOption[]>([]);
const reopenForm = reactive({ reason: '', until: '', sections: [] as string[] });

const canMarkReceived = computed(() => !!can.value?.assign && app.value?.scrutiny_status === 'submitted');
const canReopen = computed(() => !!can.value?.reopen && app.value?.status === 'submitted');
const assignStage = computed(() => profile.value?.scrutiny.stages.find((s) => String(s.id) === assignForm.stage_id) ?? null);
watch(assignStage, async (s) => {
    officers.value = [];
    assignForm.user_id = '';
    if (s) {
        try { officers.value = await loadUserOptions([s.role]); } catch (e) { fail(e); }
    }
});

function openAction(kind: 'received' | 'assign' | 'reopen') {
    form.clear();
    if (kind === 'assign') {
        const stages = profile.value?.scrutiny.stages ?? [];
        const next = stages.find((s) => !profile.value?.scrutiny.assignments.some((a) => a.stage_id === s.id)) ?? stages[0];
        assignForm.stage_id = next ? String(next.id) : '';
    }
    if (kind === 'reopen') { reopenForm.reason = ''; reopenForm.until = ''; reopenForm.sections = []; }
    action.value = kind;
}

async function submitAction() {
    let res: unknown;
    if (action.value === 'received') res = await form.run(() => api.post(`${base.value}/received`), 'Marked as received by the Recruitment Section.');
    if (action.value === 'assign') res = await form.run(() => api.post(`${base.value}/assign`, { user_id: Number(assignForm.user_id), stage_id: Number(assignForm.stage_id) }), 'Assigned for scrutiny.');
    if (action.value === 'reopen') {
        res = await form.run(() => api.post(`${base.value}/reopen`, {
            reason: reopenForm.reason, until: reopenForm.until || null, sections: reopenForm.sections.length ? reopenForm.sections : null,
        }), 'Application reopened for correction. The candidate has been notified.');
    }
    if (res) { action.value = null; load(true); }
}

// ------------------------------------------------------------ fee and endorsement verification
const feeForm = reactive({ status: 'verified', remarks: '' });
const fee = useForm();
async function verifyFee() {
    const res = await fee.run(() => api.post(`${base.value}/payment`, { status: feeForm.status, remarks: feeForm.remarks || null }), 'Fee verification recorded.');
    if (res) { feeForm.remarks = ''; load(true); }
}
const endForm = reactive({ status: 'verified', remarks: '' });
const end = useForm();
async function verifyEndorsement() {
    const res = await end.run(() => api.post(`${base.value}/endorsement`, { status: endForm.status, remarks: endForm.remarks || null }), 'Endorsement verification recorded.');
    if (res) { endForm.remarks = ''; load(true); }
}

function onReferees(rows: RefereeRow[]) {
    if (profile.value) profile.value.referees = rows;
    load(true);
}

const minUntil = computed(() => { const d = new Date(Date.now() + 3600_000); d.setSeconds(0, 0); return d.toISOString().slice(0, 16); });
watch(() => props.uuid, () => { statusHistory.value = null; audit.value = null; load(); }, { immediate: true });
const auditActor = (a: AuditEntry) => a.user?.name ?? a.actor_label ?? humanize(a.actor_type);
</script>

<template>
    <LoadingState :loading="loading" :error="error">
        <template v-if="profile && app && can">
            <nav aria-label="breadcrumb" class="small mb-1">
                <router-link to="/admin/applications"><i class="bi bi-arrow-left me-1"></i>Applications</router-link>
            </nav>
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                <div>
                    <h1 class="page-title h4 mb-1">{{ personal?.full_name ?? 'Applicant' }}</h1>
                    <div class="small text-muted">
                        <strong class="text-body">{{ app.reference_no ?? 'Not yet submitted' }}</strong> ·
                        {{ app.post.code }} — {{ app.post.name }}<template v-if="app.post.department">, {{ app.post.department }}</template> ·
                        {{ app.advertisement.number }}
                    </div>
                    <div class="d-flex flex-wrap gap-1 mt-2">
                        <StatusBadge :value="app.status" />
                        <StatusBadge v-if="app.scrutiny_status" :value="app.scrutiny_status" :label="`Scrutiny: ${humanize(app.scrutiny_status)}`" />
                        <StatusBadge v-if="app.outcome" :value="app.outcome" :label="`Outcome: ${humanize(app.outcome)}`" />
                        <StatusBadge v-if="app.committee_status" :value="app.committee_status" :label="`Committee: ${humanize(app.committee_status)}`" />
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button v-if="canMarkReceived" class="btn btn-sm btn-primary" @click="openAction('received')"><i class="bi bi-inbox me-1"></i>Mark received by Recruitment Section</button>
                    <button v-if="can.assign && app.submitted_at" class="btn btn-sm btn-outline-primary" @click="openAction('assign')"><i class="bi bi-person-check me-1"></i>Assign for scrutiny</button>
                    <button v-if="canReopen" class="btn btn-sm btn-outline-warning" @click="openAction('reopen')"><i class="bi bi-unlock me-1"></i>Reopen for correction</button>
                    <button v-if="profile.versions.length" class="btn btn-sm btn-outline-secondary" @click="versionPdf(profile.versions[0].version)"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
                </div>
            </div>

            <div v-if="profile.live_differs" class="alert alert-warning d-flex gap-2 small">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div>This application is <strong>reopened for correction</strong><template v-if="app.reopened_until"> until {{ formatDateTime(app.reopened_until) }}</template>. The candidate's live draft may differ from the submitted version shown here (v{{ app.version }}).</div>
            </div>
            <div v-if="app.status === 'withdrawn'" class="alert alert-dark small">Withdrawn by the candidate<span v-if="app.withdrawal_reason">: {{ app.withdrawal_reason }}</span></div>

            <ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto" role="tablist">
                <li v-for="t in tabs" :key="t.key" class="nav-item" role="presentation">
                    <button class="nav-link text-nowrap" :class="{ active: tab === t.key }" role="tab" :aria-selected="tab === t.key" @click="setTab(t.key)">
                        <i class="bi me-1" :class="`bi-${t.icon}`"></i>{{ t.label }}
                    </button>
                </li>
            </ul>

            <!-- Overview -->
            <div v-if="tab === 'overview'" class="row g-3">
                <div class="col-lg-6">
                    <div class="card section-card h-100">
                        <div class="card-header">Status summary</div>
                        <table class="table table-sm kv-table mb-0">
                            <tbody>
                                <tr><th>Application</th><td><StatusBadge :value="app.status" /> <span class="small text-muted">v{{ app.version }}</span></td></tr>
                                <tr><th>Scrutiny</th><td><StatusBadge :value="app.scrutiny_status" /> <span v-if="app.current_stage" class="small text-muted">{{ app.current_stage.name }}</span></td></tr>
                                <tr><th>Fee</th><td><StatusBadge :value="app.fee_status" /></td></tr>
                                <tr><th>Documents</th><td><StatusBadge :value="app.document_status" /></td></tr>
                                <tr><th>Referees</th><td><StatusBadge :value="app.referee_status" /></td></tr>
                                <tr><th>Employer endorsement</th><td><StatusBadge :value="app.endorsement_status" /></td></tr>
                                <tr><th>Scrutiny outcome</th><td><StatusBadge v-if="app.outcome" :value="app.outcome" /><span v-else class="text-muted">—</span></td></tr>
                                <tr><th>Screening Committee</th><td><StatusBadge v-if="app.committee_status" :value="app.committee_status" /><span v-else class="text-muted">—</span></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card section-card h-100">
                        <div class="card-header">Candidate</div>
                        <table class="table table-sm kv-table mb-0">
                            <tbody>
                                <tr><th>Name</th><td class="fw-semibold">{{ personal?.full_name ?? '—' }}</td></tr>
                                <tr><th>Email</th><td>{{ personal?.email ?? '—' }} <i v-if="app.candidate_email_verified" class="bi bi-patch-check-fill text-success" title="Email verified"></i><span v-else class="badge text-bg-warning">Unverified</span></td></tr>
                                <tr><th>Mobile</th><td>{{ personal?.mobile ?? '—' }}</td></tr>
                                <tr><th>Date of birth</th><td>{{ formatDate(personal?.date_of_birth as string | null) }}</td></tr>
                                <tr><th>Category / Gender</th><td>{{ personal?.category ?? '—' }} / {{ humanize(personal?.gender as string | null) }}</td></tr>
                                <tr><th>PwD / Ex-serviceman</th><td>{{ personal?.is_pwd ?? '—' }} / {{ personal?.is_ex_serviceman ?? '—' }}</td></tr>
                                <tr><th>In government service</th><td>{{ app.is_in_service ? 'Yes' : 'No' }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card section-card h-100">
                        <div class="card-header">Application</div>
                        <table class="table table-sm kv-table mb-0">
                            <tbody>
                                <tr><th>Advertisement</th><td>{{ app.advertisement.number }}<div class="small text-muted">{{ app.advertisement.title }}</div></td></tr>
                                <tr><th>Post</th><td>{{ app.post.code }} — {{ app.post.name }}</td></tr>
                                <tr><th>First submitted</th><td>{{ formatDateTime(app.first_submitted_at) }}</td></tr>
                                <tr><th>Last submitted</th><td>{{ formatDateTime(app.submitted_at) }}</td></tr>
                                <tr v-if="profile.payment"><th>Fee</th><td>{{ profile.payment.method === 'exempt' ? 'Exempt' : formatMoney(profile.payment.amount) }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card section-card h-100">
                        <div class="card-header">Submitted versions</div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>v</th><th>Reason</th><th>Created</th><th class="text-end">PDF</th></tr></thead>
                                <tbody>
                                    <tr v-for="v in profile.versions" :key="v.version">
                                        <td>{{ v.version }}</td>
                                        <td class="small">{{ v.reason ? humanize(v.reason) : '—' }}</td>
                                        <td class="small">{{ formatDateTime(v.created_at) }}<div v-if="v.by" class="text-muted">{{ v.by }}</div></td>
                                        <td class="text-end text-nowrap">
                                            <button class="btn btn-sm btn-outline-secondary me-1" title="Open" @click="versionView(v.version)"><i class="bi bi-eye"></i></button>
                                            <button class="btn btn-sm btn-outline-secondary" title="Download" @click="versionPdf(v.version)"><i class="bi bi-download"></i></button>
                                            <div v-if="v.pdf_sha256" class="font-monospace text-muted" style="font-size: .65rem" :title="`SHA-256 ${v.pdf_sha256}`">{{ v.pdf_sha256.slice(0, 12) }}…</div>
                                        </td>
                                    </tr>
                                    <tr v-if="!profile.versions.length"><td colspan="4" class="small text-muted">No submitted version yet.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div v-if="profile.reopenings.length" class="col-12">
                    <div class="card section-card">
                        <div class="card-header">Reopenings for correction</div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>When</th><th>By</th><th>Reason</th><th>Sections</th><th>Until</th><th>Resubmitted</th></tr></thead>
                                <tbody>
                                    <tr v-for="(r, i) in profile.reopenings" :key="i">
                                        <td class="small text-nowrap">{{ formatDateTime(r.at) }}</td><td class="small">{{ r.by }}</td><td class="small text-pre">{{ r.reason }}</td>
                                        <td class="small">{{ r.sections?.length ? r.sections.map((k) => profile!.section_keys.find((s) => s.key === k)?.title ?? k).join(', ') : 'All' }}</td>
                                        <td class="small text-nowrap">{{ formatDateTime(r.until) }}</td><td class="small text-nowrap">{{ formatDateTime(r.resubmitted_at) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Application -->
            <div v-else-if="tab === 'application'">
                <div v-if="profile.live_differs" class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>Showing the last submitted version. The candidate is currently correcting the application, so the live draft may differ.</div>
                <ApplicationSnapshot :snapshot="profile.snapshot" />
            </div>

            <!-- Documents -->
            <div v-else-if="tab === 'documents'" class="card section-card">
                <div class="card-header d-flex justify-content-between"><span>Uploaded documents</span><span class="fw-normal">Overall: <StatusBadge :value="app.document_status" /></span></div>
                <DocumentTable :application-uuid="uuid" :documents="profile.documents" :can-download="can.download" :can-verify="can.verify" @verified="load(true)" />
            </div>

            <!-- Fee -->
            <div v-else-if="tab === 'fee'" class="row g-3">
                <div class="col-lg-7">
                    <div class="card section-card">
                        <div class="card-header d-flex justify-content-between"><span>Payment details</span><StatusBadge :value="app.fee_status" /></div>
                        <table v-if="profile.payment" class="table table-sm kv-table mb-0">
                            <tbody>
                                <tr><th>Method</th><td>{{ humanize(profile.payment.method) }}</td></tr>
                                <tr v-if="profile.payment.exemption_basis"><th>Exemption basis</th><td>{{ profile.payment.exemption_basis }}</td></tr>
                                <tr><th>UTR / DD number</th><td class="font-monospace">{{ profile.payment.utr_number ?? '—' }}</td></tr>
                                <tr><th>Transaction date</th><td>{{ formatDate(profile.payment.transaction_date) }}</td></tr>
                                <tr><th>Amount paid / due</th><td>{{ formatMoney(profile.payment.amount) }} / {{ formatMoney(profile.payment.amount_due) }}
                                    <span v-if="profile.payment.method !== 'exempt' && profile.payment.amount < profile.payment.amount_due" class="badge text-bg-danger ms-1">Short by {{ formatMoney(profile.payment.amount_due - profile.payment.amount) }}</span></td></tr>
                                <tr><th>Bank</th><td>{{ profile.payment.bank_name ?? '—' }}</td></tr>
                                <tr v-if="profile.payment.paid_to"><th>Paid to (as shown to the candidate)</th><td>{{ profile.payment.paid_to.account_holder }}, {{ profile.payment.paid_to.bank_name }}
                                    <div class="small text-muted"><span v-if="profile.payment.paid_to.account_number_last4">A/c ending {{ profile.payment.paid_to.account_number_last4 }} · </span>IFSC {{ profile.payment.paid_to.ifsc ?? '—' }}<span v-if="profile.payment.paid_to.upi_id"> · UPI {{ profile.payment.paid_to.upi_id }}</span> · configuration v{{ profile.payment.config_version }}</div></td></tr>
                                <tr><th>Verification</th><td><StatusBadge :value="profile.payment.status" /> <span v-if="profile.payment.verified_at" class="small text-muted">{{ formatDateTime(profile.payment.verified_at) }}</span><div v-if="profile.payment.remarks" class="small">{{ profile.payment.remarks }}</div></td></tr>
                            </tbody>
                        </table>
                        <div v-else class="card-body text-muted small">No payment details entered.</div>
                    </div>
                    <div v-if="profile.payment?.history.length" class="card section-card mt-3">
                        <div class="card-header">Verification history</div>
                        <table class="table table-sm mb-0">
                            <thead class="table-light"><tr><th>When</th><th>By</th><th>Verdict</th><th>Remarks</th></tr></thead>
                            <tbody><tr v-for="(h, i) in profile.payment.history" :key="i"><td class="small text-nowrap">{{ formatDateTime(h.at) }}</td><td class="small">{{ h.by }}</td><td><StatusBadge :value="h.status" /></td><td class="small">{{ h.remarks ?? '—' }}</td></tr></tbody>
                        </table>
                    </div>
                </div>
                <div v-if="can.verify && profile.payment && profile.payment.status !== 'exempt'" class="col-lg-5">
                    <form class="card section-card" @submit.prevent="verifyFee">
                        <div class="card-header">Record fee verification</div>
                        <div class="card-body">
                            <p class="small text-muted">Check the UTR / DD against the bank statement before recording.</p>
                            <div class="btn-group mb-3" role="group">
                                <input id="fee-v" v-model="feeForm.status" class="btn-check" type="radio" value="verified"><label class="btn btn-outline-success btn-sm" for="fee-v">Verified</label>
                                <input id="fee-r" v-model="feeForm.status" class="btn-check" type="radio" value="rejected"><label class="btn btn-outline-danger btn-sm" for="fee-r">Rejected</label>
                                <input id="fee-p" v-model="feeForm.status" class="btn-check" type="radio" value="pending_verification"><label class="btn btn-outline-secondary btn-sm" for="fee-p">Pending</label>
                            </div>
                            <label class="form-label small" :class="{ required: feeForm.status === 'rejected' }" for="fee-remarks">Remarks</label>
                            <textarea id="fee-remarks" v-model="feeForm.remarks" class="form-control" rows="3" maxlength="500" :class="{ 'is-invalid': fee.errors.remarks }"></textarea>
                            <FieldError :errors="fee.errors" name="remarks" />
                            <FieldError :errors="fee.errors" name="status" />
                        </div>
                        <div class="card-footer text-end">
                            <button class="btn btn-primary btn-sm" :disabled="fee.busy.value || (feeForm.status === 'rejected' && !feeForm.remarks.trim())">Save verification</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Referees -->
            <RefereesTab v-else-if="tab === 'referees'" :uuid="uuid" :profile="profile" @referees="onReferees" @reload="load(true)" />

            <!-- Scrutiny -->
            <ScrutinyTab v-else-if="tab === 'scrutiny'" :uuid="uuid" :profile="profile" />

            <!-- Clarifications -->
            <div v-else-if="tab === 'clarifications'" class="vstack gap-3">
                <div v-for="c in profile.clarifications" :key="c.id" class="card section-card">
                    <div class="card-header d-flex flex-wrap justify-content-between gap-2">
                        <span>{{ c.subject }}</span>
                        <span class="fw-normal small"><StatusBadge :value="c.status" /> <span class="text-muted">due {{ formatDateTime(c.due_at) }}</span></span>
                    </div>
                    <div class="card-body">
                        <div class="small text-muted mb-1">Requested by {{ c.by ?? '—' }} on {{ formatDateTime(c.created_at) }}</div>
                        <div class="text-pre mb-3">{{ c.message }}</div>
                        <div v-for="(r, i) in c.responses" :key="i" class="border-start border-3 border-info ps-3 mb-3">
                            <div class="small text-muted">Candidate response · {{ formatDateTime(r.at) }}</div>
                            <div class="text-pre">{{ r.message || '—' }}</div>
                            <DocumentTable v-if="r.documents.length" class="mt-2" :application-uuid="uuid" :documents="r.documents" :can-download="can.download" />
                        </div>
                        <p v-if="!c.responses.length" class="small text-muted">No response yet.</p>
                        <div v-if="c.resolution_remarks" class="small"><strong>Resolution:</strong> {{ c.resolution_remarks }}</div>
                    </div>
                </div>
                <p v-if="!profile.clarifications.length" class="text-muted">No clarifications have been requested.</p>
                <p v-if="can.scrutinize" class="small text-muted">Clarifications are requested and resolved from the <router-link :to="`/scrutiny/${uuid}`">scrutiny workspace</router-link>.</p>
            </div>

            <!-- Endorsement -->
            <div v-else-if="tab === 'endorsement'" class="row g-3">
                <div class="col-lg-7">
                    <div class="card section-card">
                        <div class="card-header d-flex justify-content-between"><span>Employer endorsement (Section 32)</span><StatusBadge :value="app.endorsement_status" /></div>
                        <div class="card-body">
                            <template v-if="profile.snapshot.endorsement">
                                <ApplicationSnapshot :snapshot="profile.snapshot" :sections="['endorsement']" />
                                <div v-if="profile.endorsement" class="small mt-2">
                                    Verification: <StatusBadge :value="profile.endorsement.verification_status" />
                                    <span v-if="profile.endorsement.verified_by" class="text-muted"> by {{ profile.endorsement.verified_by }}, {{ formatDateTime(profile.endorsement.verified_at) }}</span>
                                    <div v-if="profile.endorsement.remarks">{{ profile.endorsement.remarks }}</div>
                                </div>
                            </template>
                            <p v-else class="text-muted small mb-0">{{ app.endorsement_status === 'not_applicable' ? 'Not applicable — the candidate is not in service.' : 'No employer endorsement has been submitted.' }}</p>
                        </div>
                    </div>
                </div>
                <div v-if="can.verify && profile.endorsement" class="col-lg-5">
                    <form class="card section-card" @submit.prevent="verifyEndorsement">
                        <div class="card-header">Record endorsement verification</div>
                        <div class="card-body">
                            <div class="btn-group mb-3" role="group">
                                <input id="end-v" v-model="endForm.status" class="btn-check" type="radio" value="verified"><label class="btn btn-outline-success btn-sm" for="end-v">Verified</label>
                                <input id="end-r" v-model="endForm.status" class="btn-check" type="radio" value="rejected"><label class="btn btn-outline-danger btn-sm" for="end-r">Rejected</label>
                                <input id="end-p" v-model="endForm.status" class="btn-check" type="radio" value="pending"><label class="btn btn-outline-secondary btn-sm" for="end-p">Pending</label>
                            </div>
                            <label class="form-label small" :class="{ required: endForm.status === 'rejected' }" for="end-remarks">Remarks</label>
                            <textarea id="end-remarks" v-model="endForm.remarks" class="form-control" rows="3" maxlength="500" :class="{ 'is-invalid': end.errors.remarks }"></textarea>
                            <FieldError :errors="end.errors" name="remarks" />
                        </div>
                        <div class="card-footer text-end">
                            <button class="btn btn-primary btn-sm" :disabled="end.busy.value || (endForm.status === 'rejected' && !endForm.remarks.trim())">Save verification</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Committee -->
            <div v-else-if="tab === 'committee'" class="row g-3">
                <div class="col-lg-5">
                    <div class="card section-card">
                        <div class="card-header">Forwarded to</div>
                        <ul class="list-group list-group-flush">
                            <li v-for="(a, i) in profile.committee.assignments" :key="i" class="list-group-item small d-flex justify-content-between"><span>{{ a.committee }}</span><span class="text-muted">{{ formatDateTime(a.at) }}</span></li>
                            <li v-if="!profile.committee.assignments.length" class="list-group-item small text-muted">Not forwarded to a Screening Committee.</li>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="card section-card">
                        <div class="card-header">Decisions</div>
                        <div class="card-body">
                            <div v-for="(d, i) in profile.committee.decisions" :key="i" class="border rounded p-2 mb-2">
                                <div class="d-flex justify-content-between"><StatusBadge :value="d.decision" /><span class="small text-muted">{{ d.committee }} · {{ d.by }} · {{ formatDateTime(d.at) }}</span></div>
                                <div v-if="d.reasons" class="small text-pre mt-1">{{ d.reasons }}</div>
                            </div>
                            <p v-if="!profile.committee.decisions.length" class="small text-muted mb-0">No decision recorded.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- History -->
            <div v-else-if="tab === 'history'" class="card section-card">
                <div class="card-header">Status history</div>
                <LoadingState :loading="!statusHistory && !lazyError" :error="lazyError">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light"><tr><th>When</th><th>Field</th><th>From</th><th>To</th><th>By</th><th>Remarks</th></tr></thead>
                            <tbody>
                                <tr v-for="(h, i) in statusHistory ?? []" :key="i">
                                    <td class="small text-nowrap">{{ formatDateTime(h.at) }}</td><td class="small">{{ humanize(h.field) }}</td>
                                    <td><StatusBadge v-if="h.from" :value="h.from" /><span v-else class="text-muted">—</span></td><td><StatusBadge :value="h.to" /></td>
                                    <td class="small">{{ h.by ?? 'System' }}</td><td class="small">{{ h.remarks ?? '' }}</td>
                                </tr>
                                <tr v-if="statusHistory && !statusHistory.length"><td colspan="6" class="small text-muted">No status changes recorded.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </LoadingState>
            </div>

            <!-- Audit -->
            <div v-else-if="tab === 'audit'" class="card section-card">
                <div class="card-header">Audit trail <span class="small fw-normal text-muted">(latest 500 entries)</span></div>
                <LoadingState :loading="!audit && !lazyError" :error="lazyError">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light"><tr><th>When</th><th>Action</th><th>Actor</th><th>Record</th><th>IP</th><th></th></tr></thead>
                            <tbody>
                                <template v-for="a in audit ?? []" :key="a.id">
                                    <tr class="cursor-pointer" @click="auditOpen.has(a.id) ? auditOpen.delete(a.id) : auditOpen.add(a.id)">
                                        <td class="small text-nowrap">{{ formatDateTime(a.created_at) }}</td>
                                        <td class="small font-monospace">{{ a.action }}</td>
                                        <td class="small">{{ auditActor(a) }}</td>
                                        <td class="small text-muted">{{ a.auditable_type ? `${a.auditable_type} #${a.auditable_id}` : '—' }}</td>
                                        <td class="small text-muted">{{ a.ip_address ?? '' }}</td>
                                        <td class="text-end"><i class="bi" :class="auditOpen.has(a.id) ? 'bi-chevron-up' : 'bi-chevron-down'"></i></td>
                                    </tr>
                                    <tr v-if="auditOpen.has(a.id)" class="table-light"><td colspan="6"><AuditDiff :old-values="a.old_values" :new-values="a.new_values" :meta="a.meta" /></td></tr>
                                </template>
                                <tr v-if="audit && !audit.length"><td colspan="6" class="small text-muted">No audit entries.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </LoadingState>
            </div>

            <!-- Action modals -->
            <BaseModal v-if="action === 'received'" title="Mark received by Recruitment Section" @close="action = null">
                <p>Record that the Recruitment Section has received application <strong>{{ app.reference_no }}</strong>. Scrutiny status becomes <em>Received</em> and it can then be assigned.</p>
                <template #footer>
                    <button class="btn btn-secondary" @click="action = null">Cancel</button>
                    <button class="btn btn-primary" :disabled="form.busy.value" @click="submitAction">Mark received</button>
                </template>
            </BaseModal>

            <BaseModal v-if="action === 'assign'" title="Assign for scrutiny" @close="action = null">
                <div v-if="form.message.value && Object.keys(form.errors).length" class="alert alert-danger small">{{ form.message.value }}</div>
                <div class="mb-3">
                    <label class="form-label required" for="as-stage">Stage</label>
                    <select id="as-stage" v-model="assignForm.stage_id" class="form-select" :class="{ 'is-invalid': form.errors.stage_id }">
                        <option value="" disabled>Choose a stage</option>
                        <option v-for="s in profile.scrutiny.stages" :key="s.id" :value="String(s.id)">{{ s.sequence }}. {{ s.name }} ({{ humanize(s.role) }})</option>
                    </select>
                    <FieldError :errors="form.errors" name="stage_id" />
                </div>
                <div>
                    <label class="form-label required" for="as-user">Officer</label>
                    <select id="as-user" v-model="assignForm.user_id" class="form-select" :disabled="!assignStage" :class="{ 'is-invalid': form.errors.user_id }">
                        <option value="" disabled>Choose an officer</option>
                        <option v-for="u in officers" :key="u.id" :value="String(u.id)">{{ u.name }}{{ u.designation ? ` — ${u.designation}` : '' }}</option>
                    </select>
                    <FieldError :errors="form.errors" name="user_id" />
                    <div v-if="profile.scrutiny.assignments.length" class="form-text">Existing: {{ profile.scrutiny.assignments.map((a) => `${a.stage}: ${a.user}`).join('; ') }}</div>
                </div>
                <template #footer>
                    <button class="btn btn-secondary" @click="action = null">Cancel</button>
                    <button class="btn btn-primary" :disabled="form.busy.value || !assignForm.stage_id || !assignForm.user_id" @click="submitAction">Assign</button>
                </template>
            </BaseModal>

            <BaseModal v-if="action === 'reopen'" title="Reopen for correction" size="lg" @close="action = null">
                <p class="small text-muted">The candidate can edit and must resubmit. The current submission is kept as version {{ app.version }}, and a new version is created on resubmission.</p>
                <div v-if="form.message.value && Object.keys(form.errors).length" class="alert alert-danger small">{{ form.message.value }}</div>
                <div class="mb-3">
                    <label class="form-label required" for="ro-reason">Reason (sent to the candidate)</label>
                    <textarea id="ro-reason" v-model="reopenForm.reason" class="form-control" rows="3" maxlength="2000" :class="{ 'is-invalid': form.errors.reason }"></textarea>
                    <FieldError :errors="form.errors" name="reason" />
                </div>
                <div class="mb-3">
                    <label class="form-label" for="ro-until">Correction window ends (optional)</label>
                    <input id="ro-until" v-model="reopenForm.until" class="form-control w-auto" type="datetime-local" :min="minUntil" :class="{ 'is-invalid': form.errors.until }">
                    <FieldError :errors="form.errors" name="until" />
                </div>
                <fieldset>
                    <legend class="form-label fs-6">Sections the candidate may change <span class="small text-muted">(none selected = all)</span></legend>
                    <div class="row row-cols-1 row-cols-md-2 g-1">
                        <div v-for="s in profile.section_keys" :key="s.key" class="col">
                            <div class="form-check">
                                <input :id="`ro-s-${s.key}`" v-model="reopenForm.sections" class="form-check-input" type="checkbox" :value="s.key">
                                <label class="form-check-label small" :for="`ro-s-${s.key}`">{{ s.title }} <span v-if="s.form_reference" class="text-muted">({{ s.form_reference }})</span></label>
                            </div>
                        </div>
                    </div>
                    <FieldError :errors="form.errors" name="sections" />
                </fieldset>
                <template #footer>
                    <button class="btn btn-secondary" @click="action = null">Cancel</button>
                    <button class="btn btn-warning" :disabled="form.busy.value || !reopenForm.reason.trim()" @click="submitAction">Reopen application</button>
                </template>
            </BaseModal>
        </template>
    </LoadingState>
</template>
