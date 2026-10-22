<script setup lang="ts">
/**
 * Referee status (spec 7.7–7.9): email delivery and recommendation
 * completion are shown separately. Recommendation content is confidential
 * and only opened on demand (each view is logged by the server).
 */
import { computed, reactive, ref } from 'vue';
import { api, download, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import { useToast } from '@/stores/toast';
import BaseModal from '@/components/BaseModal.vue';
import FieldError from '@/components/FieldError.vue';
import LoadingState from '@/components/LoadingState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { formatDateTime, humanize } from '@/utils/format';
import type { Profile, Recommendation, RefereeRow } from './profileTypes';

const props = defineProps<{ uuid: string; profile: Profile }>();
const emit = defineEmits<{ (e: 'referees', rows: RefereeRow[]): void; (e: 'reload'): void }>();
const toast = useToast();

const can = computed(() => props.profile.can);
const app = computed(() => props.profile.application);
const rows = computed(() => props.profile.referees ?? []);
const base = computed(() => `/admin/applications/${props.uuid}`);
const expanded = reactive(new Set<number>());

// ------------------------------------------------------------ badges
const DELIVERY: Record<string, { label: string; cls: string; icon: string }> = {
    not_sent: { label: 'Not sent', cls: 'text-bg-secondary', icon: 'bi-envelope-dash' },
    queued: { label: 'Queued', cls: 'text-bg-info', icon: 'bi-hourglass-split' },
    sent: { label: 'Email sent', cls: 'text-bg-success', icon: 'bi-envelope-check' },
    failed: { label: 'Delivery failed', cls: 'text-bg-danger', icon: 'bi-envelope-x' },
};
const RECOMMENDATION: Record<string, { label: string; cls: string; icon: string }> = {
    not_invited: { label: 'Not invited', cls: 'text-bg-secondary', icon: 'bi-dash-circle' },
    pending: { label: 'Pending', cls: 'text-bg-warning', icon: 'bi-clock' },
    completed: { label: 'Completed', cls: 'text-bg-success', icon: 'bi-check-circle' },
    expired: { label: 'Expired', cls: 'text-bg-dark', icon: 'bi-calendar-x' },
    revoked: { label: 'Revoked', cls: 'text-bg-dark', icon: 'bi-slash-circle' },
    replaced: { label: 'Replaced', cls: 'text-bg-dark', icon: 'bi-arrow-left-right' },
};
const badge = (map: typeof DELIVERY, v: string) => map[v] ?? { label: humanize(v), cls: 'text-bg-secondary', icon: 'bi-question-circle' };

const isActive = (r: RefereeRow) => !!r.invitation_uuid && ['pending', 'expired'].includes(r.recommendation_status);
const canReplace = (r: RefereeRow) => !!r.invitation_uuid && r.recommendation_status !== 'completed';
const canInvite = (r: RefereeRow) => r.recommendation_status === 'not_invited' && !r.invitation_uuid && !!app.value.submitted_at;

// ------------------------------------------------------------ invitation actions
type Kind = 'resend' | 'remind' | 'extend' | 'revoke' | 'replace' | 'invite';
const action = ref<{ kind: Kind; row: RefereeRow } | null>(null);
const actionForm = reactive({ until: '', reason: '', email: '' });
const act = useForm();
const TITLES: Record<Kind, string> = {
    resend: 'Resend invitation', remind: 'Send reminder', extend: 'Extend deadline', revoke: 'Revoke invitation', replace: 'Issue replacement invitation', invite: 'Invite referee',
};
const tomorrow = computed(() => { const d = new Date(); d.setDate(d.getDate() + 1); return d.toISOString().slice(0, 10); });

function startAction(kind: Kind, row: RefereeRow) {
    document.querySelectorAll('details[open]').forEach((d) => d.removeAttribute('open'));
    act.clear();
    actionForm.until = row.expires_at ? row.expires_at.slice(0, 10) : '';
    if (actionForm.until && actionForm.until < tomorrow.value) actionForm.until = tomorrow.value;
    actionForm.reason = '';
    actionForm.email = '';
    action.value = { kind, row };
}

async function submitAction() {
    if (!action.value) return;
    const { kind, row } = action.value;
    const url = kind === 'invite' ? `${base.value}/referees/${row.position}/invite` : `${base.value}/invitations/${row.invitation_uuid}/${kind}`;
    const body: Record<string, unknown> = {};
    if (kind === 'extend') body.until = actionForm.until;
    if (kind === 'revoke' || kind === 'replace') body.reason = actionForm.reason;
    if (kind === 'replace' && actionForm.email.trim()) body.email = actionForm.email.trim();
    const res = await act.run(() => api.post<{ referees: RefereeRow[] }>(url, body), `${TITLES[kind]}: done for referee ${row.position}.`);
    if (res) {
        emit('referees', res.data.referees);
        action.value = null;
    }
}

// ------------------------------------------------------------ recommendation viewer
const rec = ref<Recommendation | null>(null);
const recLoading = ref(false);
const recError = ref<string | null>(null);
const recOpen = ref(false);

async function openRecommendation(id: number) {
    recOpen.value = true;
    recLoading.value = true;
    recError.value = null;
    rec.value = null;
    amending.value = false;
    try { rec.value = (await api.get<Recommendation>(`${base.value}/recommendations/${id}`)).data; }
    catch (e) { recError.value = toApiError(e).message; }
    finally { recLoading.value = false; }
}

function downloadPdf(id: number, reference?: string | null) {
    download(`${base.value}/recommendations/${id}/pdf`, `Referee-Recommendation-${(reference ?? String(id)).replace(/\//g, '-')}.pdf`)
        .catch((e) => toast.error(toApiError(e).message));
}

const CANDIDATE_FIELDS: [string, string][] = [
    ['name', 'referee_name'], ['designation', 'referee_designation'], ['organization', 'referee_organization'],
    ['email', 'referee_email'], ['phone', 'referee_phone'], ['relationship', 'relationship'],
];
const candidateDiffs = computed(() => {
    const r = rec.value;
    if (!r) return [];
    return CANDIDATE_FIELDS.map(([ck, rk]) => ({
        label: humanize(ck),
        candidate: r.candidate_entered?.[ck] ?? null,
        referee: (r as unknown as Record<string, unknown>)[rk] as string | null,
    })).filter((d) => d.candidate !== null && d.candidate !== undefined && String(d.candidate).trim().toLowerCase() !== String(d.referee ?? '').trim().toLowerCase());
});
function answerDisplay(q: Recommendation['answers'][number]): string {
    const base = answerText(q.answer);
    const opts = q.options as { labels?: Record<string, string>; max?: number } | null;
    if (q.type === 'rating' && q.answer !== null && q.answer !== undefined && opts) {
        const label = opts.labels?.[String(q.answer)];
        return `${base}${opts.max ? ` / ${opts.max}` : ''}${label ? ` — ${label}` : ''}`;
    }
    return base;
}
const answerText = (a: unknown): string => {
    if (a === null || a === undefined || a === '') return '—';
    if (Array.isArray(a)) return a.join(', ');
    if (typeof a === 'object') return JSON.stringify(a);
    if (typeof a === 'boolean') return a ? 'Yes' : 'No';
    return String(a);
};

// ------------------------------------------------------------ amendments
const AMENDABLE = ['referee_name', 'referee_designation', 'referee_organization', 'referee_email', 'referee_phone', 'relationship', 'known_duration', 'overall_recommendation', 'comments'];
const amending = ref(false);
const amendForm = reactive({ field: 'referee_email', amended_value: '', reason: '' });
const amend = useForm();
const originalValue = computed(() => (rec.value ? String((rec.value as unknown as Record<string, unknown>)[amendForm.field] ?? '') : ''));

function startAmend() {
    amend.clear();
    amendForm.field = 'referee_email';
    amendForm.amended_value = '';
    amendForm.reason = '';
    amending.value = true;
}
async function submitAmend() {
    if (!rec.value) return;
    const id = rec.value.id;
    const res = await amend.run(() => api.post(`${base.value}/recommendations/${id}/amend`, { ...amendForm }), 'Amendment recorded. The original recommendation is unchanged.');
    if (res) {
        amending.value = false;
        await openRecommendation(id);
    }
}

// ------------------------------------------------------------ exceptions (email exception / waiver)
const exc = ref<'email' | 'waiver' | null>(null);
const excForm = reactive({ allow: true, note: '' });
const excRun = useForm();
function startException(kind: 'email' | 'waiver') {
    excRun.clear();
    excForm.allow = kind === 'email' ? !app.value.referee_email_exception : !app.value.referee_requirement_waived;
    excForm.note = kind === 'waiver' ? (app.value.referee_waiver_reason ?? '') : '';
    exc.value = kind;
}
async function submitException() {
    const kind = exc.value;
    const res = await excRun.run(
        () => (kind === 'email'
            ? api.post<unknown>(`${base.value}/referee-email-exception`, { allow: excForm.allow, note: excForm.note || null } as Record<string, unknown>)
            : api.post<unknown>(`${base.value}/referee-waiver`, { waive: excForm.allow, reason: excForm.note || null } as Record<string, unknown>)),
        'Saved.',
    );
    if (res) { exc.value = null; emit('reload'); }
}
</script>

<template>
    <div class="vstack gap-3">
        <div v-if="!can.referee_status" class="alert alert-secondary">You do not have access to referee status for this application.</div>
        <template v-else>
            <div class="card section-card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span>Referees and recommendations</span>
                    <span class="small fw-normal">
                        Overall: <StatusBadge :value="app.referee_status" />
                        <span class="text-muted ms-2">{{ app.advertisement.referees_required }} required{{ app.advertisement.referee_recommendations_mandatory ? ', mandatory' : '' }}</span>
                    </span>
                </div>
                <div v-if="app.referee_requirement_waived || app.referee_email_exception" class="card-body py-2 border-bottom small">
                    <div v-if="app.referee_requirement_waived" class="text-info"><i class="bi bi-shield-check me-1"></i>Referee requirement waived<span v-if="app.referee_waiver_reason">: {{ app.referee_waiver_reason }}</span></div>
                    <div v-if="app.referee_email_exception" class="text-info"><i class="bi bi-envelope-exclamation me-1"></i>Referee email exception allowed (referees may share an email address).</div>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light"><tr><th>Referee</th><th>Invitation status</th><th>Recommendation status</th><th>Submitted at</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            <template v-for="r in rows" :key="r.position">
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ r.position }}. {{ r.name }}</div>
                                        <div class="small text-muted">{{ r.designation }}</div>
                                        <div class="small text-break">{{ r.email }}</div>
                                    </td>
                                    <td>
                                        <span class="badge" :class="badge(DELIVERY, r.delivery_status).cls"><i class="bi me-1" :class="badge(DELIVERY, r.delivery_status).icon"></i>{{ badge(DELIVERY, r.delivery_status).label }}</span>
                                        <div v-if="r.sent_at" class="small text-muted">Sent {{ formatDateTime(r.sent_at) }}</div>
                                        <div v-if="r.opened" class="small text-success"><i class="bi bi-eye"></i> Opened by referee</div>
                                        <div v-if="r.reminder_count" class="small text-muted">{{ r.reminder_count }} reminder{{ r.reminder_count === 1 ? '' : 's' }}</div>
                                        <div v-if="r.delivery_status === 'failed' && r.delivery_error" class="small text-danger text-break">{{ r.delivery_error }}</div>
                                    </td>
                                    <td>
                                        <span class="badge" :class="badge(RECOMMENDATION, r.recommendation_status).cls"><i class="bi me-1" :class="badge(RECOMMENDATION, r.recommendation_status).icon"></i>{{ badge(RECOMMENDATION, r.recommendation_status).label }}</span>
                                        <div v-if="r.expires_at && r.recommendation_status !== 'completed'" class="small" :class="r.recommendation_status === 'expired' ? 'text-danger' : 'text-muted'">Deadline {{ formatDateTime(r.expires_at) }}</div>
                                        <div v-if="r.response_reference" class="small text-muted">{{ r.response_reference }}</div>
                                    </td>
                                    <td class="small text-nowrap">{{ formatDateTime(r.submitted_at) }}</td>
                                    <td class="text-end">
                                        <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                                            <template v-if="r.recommendation_status === 'completed' && r.response_id && can.recommendations">
                                                <button class="btn btn-sm btn-outline-primary" @click="openRecommendation(r.response_id)"><i class="bi bi-file-lock me-1"></i>View</button>
                                                <button class="btn btn-sm btn-outline-secondary" title="Download PDF" @click="downloadPdf(r.response_id, r.response_reference)"><i class="bi bi-download"></i></button>
                                            </template>
                                            <template v-if="can.manage_referees">
                                                <button v-if="canInvite(r)" class="btn btn-sm btn-primary" @click="startAction('invite', r)"><i class="bi bi-send me-1"></i>Invite</button>
                                                <div v-if="isActive(r) || canReplace(r)" class="dropdown">
                                                    <details class="position-relative">
                                                        <summary class="btn btn-sm btn-outline-secondary list-unstyled">Manage <i class="bi bi-caret-down-fill small"></i></summary>
                                                        <div class="dropdown-menu show end-0 shadow-sm" style="right: 0; left: auto">
                                                            <template v-if="isActive(r)">
                                                                <button class="dropdown-item" @click="startAction('resend', r)"><i class="bi bi-arrow-repeat me-2"></i>Resend invitation</button>
                                                                <button class="dropdown-item" @click="startAction('remind', r)"><i class="bi bi-bell me-2"></i>Send reminder</button>
                                                                <button class="dropdown-item" @click="startAction('extend', r)"><i class="bi bi-calendar-plus me-2"></i>Extend deadline</button>
                                                                <button class="dropdown-item text-danger" @click="startAction('revoke', r)"><i class="bi bi-slash-circle me-2"></i>Revoke</button>
                                                            </template>
                                                            <button v-if="canReplace(r)" class="dropdown-item" @click="startAction('replace', r)"><i class="bi bi-arrow-left-right me-2"></i>Issue replacement</button>
                                                        </div>
                                                    </details>
                                                </div>
                                            </template>
                                            <button v-if="r.history.length" class="btn btn-sm btn-link" :aria-expanded="expanded.has(r.position)" @click="expanded.has(r.position) ? expanded.delete(r.position) : expanded.add(r.position)">
                                                History ({{ r.history.length }}) <i class="bi" :class="expanded.has(r.position) ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="expanded.has(r.position)" class="table-light">
                                    <td colspan="5" class="small">
                                        <table class="table table-sm mb-0 bg-white">
                                            <thead><tr><th>Issued</th><th>Email</th><th>State</th><th>Delivery</th><th>Expires</th><th>Revocation / replacement reason</th></tr></thead>
                                            <tbody>
                                                <tr v-for="h in r.history" :key="h.uuid">
                                                    <td class="text-nowrap">{{ formatDateTime(h.created_at) }}</td>
                                                    <td class="text-break">{{ h.email }}</td>
                                                    <td><StatusBadge :value="h.state" /></td>
                                                    <td><span class="badge" :class="badge(DELIVERY, h.delivery_status).cls">{{ badge(DELIVERY, h.delivery_status).label }}</span></td>
                                                    <td class="text-nowrap">{{ formatDateTime(h.expires_at) }}</td>
                                                    <td>{{ h.revocation_reason ?? '—' }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!rows.length"><td colspan="5" class="text-muted small">No referees entered.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer small text-muted">
                    Email delivery and recommendation completion are tracked separately: an invitation can be delivered and still be pending, or fail delivery and need resending.
                </div>
            </div>

            <div v-if="can.referee_exceptions" class="card section-card">
                <div class="card-header">Referee exceptions</div>
                <div class="card-body d-flex flex-wrap gap-2">
                    <button class="btn btn-sm btn-outline-secondary" @click="startException('email')">
                        <i class="bi bi-envelope-exclamation me-1"></i>{{ app.referee_email_exception ? 'Withdraw email exception' : 'Allow referee email exception' }}
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" @click="startException('waiver')">
                        <i class="bi bi-shield-check me-1"></i>{{ app.referee_requirement_waived ? 'Withdraw referee waiver' : 'Waive referee requirement' }}
                    </button>
                </div>
            </div>
        </template>
    </div>

    <!-- Invitation actions -->
    <BaseModal v-if="action" :title="TITLES[action.kind]" @close="action = null">
        <p class="small">Referee {{ action.row.position }}: <strong>{{ action.row.name }}</strong> &lt;{{ action.row.email }}&gt;</p>
        <div v-if="act.message.value && Object.keys(act.errors).length" class="alert alert-danger small">{{ act.message.value }}</div>
        <FieldError :errors="act.errors" name="invitation" />
        <p v-if="action.kind === 'resend'" class="small text-muted">The same invitation link is emailed again. The deadline is unchanged.</p>
        <p v-if="action.kind === 'remind'" class="small text-muted">A reminder email with the existing link is sent and the reminder count increases.</p>
        <p v-if="action.kind === 'invite'" class="small text-muted">A new invitation with a unique link is emailed to the referee.</p>
        <div v-if="action.kind === 'extend'">
            <label class="form-label required" for="ra-until">New deadline</label>
            <input id="ra-until" v-model="actionForm.until" class="form-control" type="date" :min="tomorrow" :class="{ 'is-invalid': act.errors.until }">
            <FieldError :errors="act.errors" name="until" />
        </div>
        <template v-if="action.kind === 'revoke' || action.kind === 'replace'">
            <p v-if="action.kind === 'revoke'" class="small text-muted">The link stops working immediately. You can issue a replacement later.</p>
            <p v-else class="small text-muted">Any live invitation is withdrawn and a new link is issued. Optionally correct the referee's email address.</p>
            <label class="form-label required" for="ra-reason">Reason</label>
            <textarea id="ra-reason" v-model="actionForm.reason" class="form-control" rows="3" maxlength="500" :class="{ 'is-invalid': act.errors.reason }"></textarea>
            <FieldError :errors="act.errors" name="reason" />
            <div v-if="action.kind === 'replace'" class="mt-3">
                <label class="form-label" for="ra-email">Corrected email (optional)</label>
                <input id="ra-email" v-model="actionForm.email" class="form-control" type="email" maxlength="255" :placeholder="action.row.email" :class="{ 'is-invalid': act.errors.email }">
                <FieldError :errors="act.errors" name="email" />
            </div>
        </template>
        <template #footer>
            <button class="btn btn-secondary" @click="action = null">Cancel</button>
            <button class="btn" :class="action.kind === 'revoke' ? 'btn-danger' : 'btn-primary'"
                :disabled="act.busy.value || (action.kind === 'extend' && !actionForm.until) || ((action.kind === 'revoke' || action.kind === 'replace') && !actionForm.reason.trim())"
                @click="submitAction">{{ TITLES[action.kind] }}</button>
        </template>
    </BaseModal>

    <!-- Recommendation viewer -->
    <BaseModal v-if="recOpen" title="Referee recommendation" size="xl" @close="recOpen = false">
        <div class="confidential-banner rounded p-2 mb-3 small fw-semibold"><i class="bi bi-lock-fill me-1"></i>CONFIDENTIAL — not visible to the applicant. Your access is logged.</div>
        <LoadingState :loading="recLoading" :error="recError">
            <template v-if="rec">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                    <div><strong>{{ rec.reference_no }}</strong> · Referee {{ rec.position }} · submitted {{ formatDateTime(rec.submitted_at) }}</div>
                    <button class="btn btn-sm btn-outline-secondary" @click="downloadPdf(rec.id, rec.reference_no)"><i class="bi bi-download me-1"></i>Download PDF</button>
                </div>
                <h6 class="text-uppercase small text-muted mt-3">Section A · Referee information</h6>
                <table class="table table-sm kv-table">
                    <tbody>
                        <tr><th>Name</th><td>{{ rec.referee_name }}</td></tr>
                        <tr><th>Designation / Organisation</th><td>{{ rec.referee_designation }}, {{ rec.referee_organization }}</td></tr>
                        <tr><th>Email / Phone</th><td>{{ rec.referee_email }} / {{ rec.referee_phone ?? '—' }}</td></tr>
                        <tr><th>Relationship with candidate</th><td>{{ rec.relationship }}</td></tr>
                        <tr><th>Known for</th><td>{{ rec.known_duration }}</td></tr>
                        <tr v-if="rec.nature_of_association"><th>Nature of association</th><td class="text-pre">{{ rec.nature_of_association }}</td></tr>
                    </tbody>
                </table>
                <div v-if="candidateDiffs.length" class="alert alert-warning small">
                    <div class="fw-semibold mb-1">Differs from what the candidate entered:</div>
                    <table class="table table-sm mb-0 bg-white">
                        <thead><tr><th>Field</th><th>Candidate entered</th><th>Referee confirmed</th></tr></thead>
                        <tbody><tr v-for="d in candidateDiffs" :key="d.label"><td>{{ d.label }}</td><td>{{ d.candidate }}</td><td>{{ d.referee ?? '—' }}</td></tr></tbody>
                    </table>
                </div>
                <h6 class="text-uppercase small text-muted mt-3">Assessment</h6>
                <table class="table table-sm">
                    <tbody>
                        <tr v-for="(a, i) in rec.answers" :key="a.code"><td class="text-muted small" style="width: 2rem">{{ i + 1 }}</td><td style="width: 45%">{{ a.label }}</td><td class="fw-semibold text-pre">{{ answerDisplay(a) }}</td></tr>
                    </tbody>
                </table>
                <table class="table table-sm kv-table">
                    <tbody>
                        <tr><th>Overall recommendation</th><td class="fw-bold">{{ rec.overall_recommendation }}</td></tr>
                        <tr><th>Comments</th><td class="text-pre">{{ rec.comments || '—' }}</td></tr>
                        <tr><th>Content hash (SHA-256)</th><td class="font-monospace small text-break">{{ rec.content_hash }}</td></tr>
                    </tbody>
                </table>
                <h6 class="text-uppercase small text-muted mt-3">Amendments</h6>
                <table v-if="rec.amendments.length" class="table table-sm small">
                    <thead class="table-light"><tr><th>Field</th><th>Original</th><th>Amended</th><th>Reason</th><th>By / when</th></tr></thead>
                    <tbody>
                        <tr v-for="(m, i) in rec.amendments" :key="i">
                            <td>{{ humanize(m.field) }}</td><td class="text-decoration-line-through text-muted">{{ m.original ?? '—' }}</td><td class="fw-semibold">{{ m.amended }}</td>
                            <td>{{ m.reason }}</td><td class="text-nowrap">{{ m.by }}<br>{{ formatDateTime(m.at) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="small text-muted">None.</p>

                <template v-if="can.referee_exceptions">
                    <button v-if="!amending" class="btn btn-sm btn-outline-warning" @click="startAmend"><i class="bi bi-pencil-square me-1"></i>Amend recommendation</button>
                    <div v-else class="card border-warning">
                        <div class="card-body">
                            <p class="small text-muted">The referee's original submission stays immutable. An amendment is recorded alongside it with your name and reason, and appears in the PDF.</p>
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label small required" for="am-field">Field</label>
                                    <select id="am-field" v-model="amendForm.field" class="form-select form-select-sm">
                                        <option v-for="f in AMENDABLE" :key="f" :value="f">{{ humanize(f) }}</option>
                                    </select>
                                    <div class="form-text text-break">Current: {{ originalValue || '—' }}</div>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label small required" for="am-value">Amended value</label>
                                    <textarea id="am-value" v-model="amendForm.amended_value" class="form-control form-control-sm" rows="2" maxlength="5000" :class="{ 'is-invalid': amend.errors.amended_value }"></textarea>
                                    <FieldError :errors="amend.errors" name="amended_value" />
                                </div>
                                <div class="col-12">
                                    <label class="form-label small required" for="am-reason">Reason</label>
                                    <textarea id="am-reason" v-model="amendForm.reason" class="form-control form-control-sm" rows="2" maxlength="2000" :class="{ 'is-invalid': amend.errors.reason }"></textarea>
                                    <FieldError :errors="amend.errors" name="reason" />
                                </div>
                            </div>
                            <div class="mt-2 d-flex gap-2">
                                <button class="btn btn-sm btn-warning" :disabled="amend.busy.value || !amendForm.amended_value.trim() || !amendForm.reason.trim()" @click="submitAmend">Record amendment</button>
                                <button class="btn btn-sm btn-secondary" @click="amending = false">Cancel</button>
                            </div>
                        </div>
                    </div>
                </template>
            </template>
        </LoadingState>
    </BaseModal>

    <!-- Exceptions -->
    <BaseModal v-if="exc" :title="exc === 'email' ? 'Referee email exception' : 'Referee requirement waiver'" @close="exc = null">
        <div class="form-check form-switch mb-3">
            <input id="exc-allow" v-model="excForm.allow" class="form-check-input" type="checkbox">
            <label class="form-check-label" for="exc-allow">{{ exc === 'email' ? 'Allow two or more referees to share the same email address' : 'Waive the referee recommendation requirement' }}</label>
        </div>
        <label class="form-label" :class="{ required: excForm.allow }" for="exc-note">{{ exc === 'email' ? 'Note' : 'Reason' }}</label>
        <textarea id="exc-note" v-model="excForm.note" class="form-control" rows="3" maxlength="500" :class="{ 'is-invalid': excRun.errors.note || excRun.errors.reason }"></textarea>
        <FieldError :errors="excRun.errors" name="note" />
        <FieldError :errors="excRun.errors" name="reason" />
        <p class="small text-muted mt-2 mb-0">This decision is recorded in the audit trail.</p>
        <template #footer>
            <button class="btn btn-secondary" @click="exc = null">Cancel</button>
            <button class="btn btn-primary" :disabled="excRun.busy.value || (excForm.allow && !excForm.note.trim())" @click="submitException">Save</button>
        </template>
    </BaseModal>
</template>

<style scoped>
details > summary { list-style: none; }
details > summary::-webkit-details-marker { display: none; }
details .dropdown-menu { position: absolute; top: 100%; z-index: 20; min-width: 12rem; }
</style>
