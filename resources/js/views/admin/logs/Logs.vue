<script setup lang="ts">
/** Paginated audit, email delivery, document access and export logs. Filters live in the URL query. */
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router';
import { api, toApiError } from '@/api/client';
import { useAuth } from '@/stores/auth';
import LoadingState from '@/components/LoadingState.vue';
import Pager from '@/components/Pager.vue';
import AuditDiff from '@/views/admin/applications/parts/AuditDiff.vue';
import EmailAccounts, { type MailAccountStat } from './parts/EmailAccounts.vue';
import type { AuditEntry } from '@/views/admin/applications/parts/profileTypes';
import { formatDateTime, humanize } from '@/utils/format';

type LogType = 'audit' | 'emails' | 'access' | 'exports';
const props = defineProps<{ type: string }>();

interface EmailAttempt {
    id: number; attempt: number; mail_account: string; sender_address: string | null; outcome: 'sent' | 'failed'; failure_type: string | null;
    smtp_code: number | null; response: string | null; job_id: string | null; started_at: string; finished_at: string | null;
}
interface EmailEntry {
    id: number; uuid: string; template_key: string | null; to_email: string; to_name: string | null; subject: string | null; related_type: string | null;
    related_id: number | null; application_id: number | null; status: 'queued' | 'processing' | 'sent' | 'retrying' | 'rate_limited' | 'failed' | string;
    attempts: number; error: string | null; mail_account: string | null; sender_address: string | null; smtp_response: string | null;
    queued_at: string | null; sent_at: string | null; failed_at: string | null; next_attempt_at: string | null; created_at: string;
    application?: { id: number; uuid: string; reference_no: string | null } | null;
    send_attempts?: EmailAttempt[];
}
interface PoolStats { enabled: boolean; strategy: string; accounts: MailAccountStat[] }
interface AccessEntry { id: number; user_id: number | null; resource_type: string; resource_id: number; application_id: number | null; action: string; ip_address: string | null; created_at: string; user?: { name: string; email: string } | null }
interface ExportEntry { id: number; report: string; format: string; filters: Record<string, unknown> | null; row_count: number; is_sensitive: boolean; ip_address: string | null; created_at: string; user?: { name: string; email: string } | null }
interface Paginated<T> { data: T[]; current_page: number; last_page: number; total: number; per_page: number }

const route = useRoute();
const router = useRouter();
const auth = useAuth();

const TYPES: { key: LogType; label: string; icon: string; permission: string }[] = [
    { key: 'audit', label: 'Audit trail', icon: 'journal-text', permission: 'audit.view' },
    { key: 'emails', label: 'Email delivery', icon: 'send-check', permission: 'email_logs.view' },
    { key: 'access', label: 'Document access', icon: 'eye', permission: 'audit.view' },
    { key: 'exports', label: 'Exports', icon: 'download', permission: 'audit.view' },
];
const visibleTypes = computed(() => TYPES.filter((t) => auth.can(t.permission)));
const logType = computed<LogType | null>(() => (TYPES.some((t) => t.key === props.type) ? (props.type as LogType) : null));
const meta = computed(() => TYPES.find((t) => t.key === logType.value));

const FILTER_KEYS: Record<LogType, string[]> = {
    audit: ['action', 'actor_type', 'date_from', 'date_to'],
    emails: ['status', 'mail_account', 'template_key', 'q', 'date_from', 'date_to', 'failed_attempts'],
    access: ['resource_type'],
    exports: [],
};
const q = (k: string) => (typeof route.query[k] === 'string' ? (route.query[k] as string) : '');
const page = computed(() => Math.max(1, Number(q('page')) || 1));
const f = reactive<Record<string, string>>({});
function syncForm() {
    for (const k of Object.keys(f)) delete f[k];
    for (const k of FILTER_KEYS[logType.value ?? 'exports']) f[k] = q(k);
}

const result = ref<Paginated<unknown> | null>(null);
const pool = ref<PoolStats | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const open = reactive(new Set<number>());

let seq = 0;
async function load() {
    if (!logType.value) return;
    const mine = ++seq;
    loading.value = true;
    error.value = null;
    const params: Record<string, string | number> = { page: page.value };
    for (const k of FILTER_KEYS[logType.value]) if (q(k)) params[k] = q(k);
    try {
        const [{ data }, accounts] = await Promise.all([
            api.get<Paginated<unknown>>(`/admin/logs/${logType.value}`, { params }),
            logType.value === 'emails' ? api.get<PoolStats>('/admin/logs/email-accounts') : Promise.resolve(null),
        ]);
        if (mine === seq) {
            result.value = data;
            pool.value = accounts?.data ?? null;
        }
    } catch (e) {
        if (mine === seq) { error.value = toApiError(e).message; result.value = null; }
    } finally {
        if (mine === seq) loading.value = false;
    }
}

watch(() => [props.type, route.query], () => {
    if (!route.path.startsWith('/admin/logs/')) return;
    open.clear();
    syncForm();
    load();
}, { immediate: true, deep: true });

function apply() {
    const next: LocationQueryRaw = {};
    for (const [k, v] of Object.entries(f)) if (v) next[k] = v;
    router.replace({ query: next });
}
function clear() {
    router.replace({ query: {} });
}
const goPage = (p: number) => router.replace({ query: { ...route.query, page: p > 1 ? String(p) : undefined } });

const audits = computed(() => (logType.value === 'audit' ? (result.value?.data as AuditEntry[] | undefined) ?? [] : []));
const emails = computed(() => (logType.value === 'emails' ? (result.value?.data as EmailEntry[] | undefined) ?? [] : []));
const accesses = computed(() => (logType.value === 'access' ? (result.value?.data as AccessEntry[] | undefined) ?? [] : []));
const exportsRows = computed(() => (logType.value === 'exports' ? (result.value?.data as ExportEntry[] | undefined) ?? [] : []));
const templateKeys = computed(() => [...new Set(emails.value.map((e) => e.template_key).filter((k): k is string => !!k))]);
const toggle = (id: number) => (open.has(id) ? open.delete(id) : open.add(id));
const hasDiff = (a: AuditEntry) => !!(a.old_values || a.new_values || a.meta);

const EMAIL_STATUS: Record<string, { cls: string; icon: string; label: string; title?: string }> = {
    queued: { cls: 'text-bg-info', icon: 'bi-hourglass-split', label: 'Queued' },
    processing: { cls: 'text-bg-info', icon: 'bi-arrow-repeat', label: 'Processing' },
    sent: { cls: 'text-bg-success', icon: 'bi-check-circle', label: 'Sent', title: 'Accepted by the mail server; delivery to the inbox is not confirmed' },
    retrying: { cls: 'text-bg-warning', icon: 'bi-arrow-clockwise', label: 'Retrying' },
    rate_limited: { cls: 'text-bg-warning', icon: 'bi-speedometer2', label: 'Rate limited' },
    failed: { cls: 'text-bg-danger', icon: 'bi-x-octagon', label: 'Failed' },
};
const emailBadge = (s: string) => EMAIL_STATUS[s] ?? { cls: 'text-bg-secondary', icon: 'bi-question-circle', label: humanize(s) };
const FAILURE_TYPES: Record<string, string> = {
    connection: 'server unreachable', ambiguous: 'outcome unknown', rate_limited: 'throttled', quota: 'quota reached',
    domain_rate_limited: 'domain rate limit', auth: 'login rejected', config: 'TLS / configuration', sender_rejected: 'From address refused',
    recipient: 'recipient refused', message: 'message refused', deferred: 'deferred', other: 'error',
};
const accountLabel = (name: string | null) => (name ? pool.value?.accounts.find((a) => a.name === name)?.label ?? humanize(name) : '—');
const RESOURCE_TYPES = ['application_document', 'application_pdf', 'referee_pdf', 'referee_response', 'scrutiny_report', 'receipt', 'advertisement_document'];
const filterText = (v: Record<string, unknown> | null) => (v && Object.keys(v).length ? Object.entries(v).map(([k, x]) => `${k}: ${Array.isArray(x) ? x.join(',') : String(x)}`).join(' · ') : 'No filters');
</script>

<template>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h1 class="page-title h4 mb-0">{{ meta?.label ?? 'Logs' }}</h1>
        <button class="btn btn-sm btn-outline-secondary" :disabled="loading" title="Refresh" @click="load"><i class="bi bi-arrow-clockwise"></i></button>
    </div>

    <ul v-if="visibleTypes.length > 1" class="nav nav-tabs mb-3">
        <li v-for="t in visibleTypes" :key="t.key" class="nav-item">
            <router-link :to="`/admin/logs/${t.key}`" class="nav-link" :class="{ active: t.key === logType }"><i class="bi me-1" :class="`bi-${t.icon}`"></i>{{ t.label }}</router-link>
        </li>
    </ul>

    <div v-if="!logType" class="alert alert-warning">Unknown log type.</div>
    <template v-else>
        <EmailAccounts v-if="logType === 'emails' && pool" :accounts="pool.accounts" :enabled="pool.enabled" :strategy="pool.strategy" />

        <form v-if="FILTER_KEYS[logType].length" class="card mb-3" @submit.prevent="apply">
            <div class="card-body row g-2 align-items-end">
                <template v-if="logType === 'audit'">
                    <div class="col-md-4">
                        <label class="form-label small mb-1" for="lg-action">Action starts with</label>
                        <input id="lg-action" v-model.trim="f.action" class="form-control form-control-sm font-monospace" placeholder="e.g. referee. or application.submitted">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="lg-actor">Actor type</label>
                        <select id="lg-actor" v-model="f.actor_type" class="form-select form-select-sm">
                            <option value="">Any</option><option value="user">User</option><option value="referee">Referee</option><option value="system">System</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="lg-from">From</label>
                        <input id="lg-from" v-model="f.date_from" class="form-control form-control-sm" type="date">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="lg-to">To</label>
                        <input id="lg-to" v-model="f.date_to" class="form-control form-control-sm" type="date" :min="f.date_from || undefined">
                    </div>
                </template>
                <template v-if="logType === 'emails'">
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="lg-status">Status</label>
                        <select id="lg-status" v-model="f.status" class="form-select form-select-sm">
                            <option value="">Any</option>
                            <option v-for="(s, k) in EMAIL_STATUS" :key="k" :value="k">{{ s.label }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="lg-account">Sending account</label>
                        <select id="lg-account" v-model="f.mail_account" class="form-select form-select-sm">
                            <option value="">Any</option>
                            <option v-for="a in pool?.accounts ?? []" :key="a.name" :value="a.name">{{ a.label }}</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1" for="lg-tpl">Template key</label>
                        <input id="lg-tpl" v-model.trim="f.template_key" class="form-control form-control-sm font-monospace" list="lg-tpl-list" placeholder="e.g. referee_invitation">
                        <datalist id="lg-tpl-list"><option v-for="k in templateKeys" :key="k" :value="k" /></datalist>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small mb-1" for="lg-q">Recipient</label>
                        <input id="lg-q" v-model.trim="f.q" class="form-control form-control-sm" type="search" placeholder="Email address contains…">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="lg-efrom">Queued from</label>
                        <input id="lg-efrom" v-model="f.date_from" class="form-control form-control-sm" type="date">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="lg-eto">Queued to</label>
                        <input id="lg-eto" v-model="f.date_to" class="form-control form-control-sm" type="date" :min="f.date_from || undefined">
                    </div>
                    <div class="col-md-3">
                        <div class="form-check mb-1">
                            <input id="lg-efail" v-model="f.failed_attempts" class="form-check-input" type="checkbox" true-value="1" false-value="">
                            <label class="form-check-label small" for="lg-efail">Only with failed attempts</label>
                        </div>
                    </div>
                </template>
                <template v-if="logType === 'access'">
                    <div class="col-md-4">
                        <label class="form-label small mb-1" for="lg-res">Resource type</label>
                        <select id="lg-res" v-model="f.resource_type" class="form-select form-select-sm">
                            <option value="">Any</option>
                            <option v-for="r in RESOURCE_TYPES" :key="r" :value="r">{{ humanize(r) }}</option>
                        </select>
                    </div>
                </template>
                <div class="col-auto d-flex gap-2">
                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
                    <button class="btn btn-outline-secondary btn-sm" type="button" @click="clear">Clear</button>
                </div>
            </div>
        </form>

        <div class="card">
            <LoadingState :loading="loading && !result" :error="error">
                <div class="table-responsive" :class="{ 'opacity-50': loading }">
                    <!-- Audit -->
                    <table v-if="logType === 'audit'" class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>When</th><th>Action</th><th>Actor</th><th>Record</th><th>Application</th><th>IP</th><th></th></tr></thead>
                        <tbody>
                            <template v-for="a in audits" :key="a.id">
                                <tr :class="{ 'cursor-pointer': hasDiff(a) }" @click="hasDiff(a) && toggle(a.id)">
                                    <td class="small text-nowrap">{{ formatDateTime(a.created_at) }}</td>
                                    <td class="small font-monospace">{{ a.action }}</td>
                                    <td class="small">
                                        <span class="badge text-bg-light border me-1">{{ a.actor_type }}</span>{{ a.user?.name ?? a.actor_label ?? '—' }}
                                        <div v-if="a.user?.email" class="text-muted">{{ a.user.email }}</div>
                                    </td>
                                    <td class="small text-muted">{{ a.auditable_type ? `${a.auditable_type} #${a.auditable_id}` : '—' }}</td>
                                    <td class="small">{{ a.application_id ? `#${a.application_id}` : '—' }}</td>
                                    <td class="small text-muted">{{ a.ip_address ?? '' }}</td>
                                    <td class="text-end"><i v-if="hasDiff(a)" class="bi" :class="open.has(a.id) ? 'bi-chevron-up' : 'bi-chevron-down'"></i></td>
                                </tr>
                                <tr v-if="open.has(a.id)" class="table-light">
                                    <td colspan="7">
                                        <AuditDiff :old-values="a.old_values" :new-values="a.new_values" :meta="a.meta" />
                                        <div v-if="a.user_agent" class="small text-muted mt-1 text-break">User agent: {{ a.user_agent }}</div>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!audits.length"><td colspan="7" class="text-center text-muted py-3">No entries.</td></tr>
                        </tbody>
                    </table>

                    <!-- Emails -->
                    <table v-else-if="logType === 'emails'" class="table table-sm table-cards align-middle mb-0">
                        <thead class="table-light"><tr><th>Status</th><th>Recipient</th><th>Template / subject</th><th>Account</th><th>Application</th><th>Queued</th><th>Sent / failed</th><th class="text-center">Attempts</th><th><span class="visually-hidden">Details</span></th></tr></thead>
                        <tbody>
                            <template v-for="e in emails" :key="e.id">
                                <tr :class="{ 'table-danger': e.status === 'failed' }">
                                    <td><span class="badge" :class="emailBadge(e.status).cls" :title="emailBadge(e.status).title"><i class="bi me-1" :class="emailBadge(e.status).icon"></i>{{ emailBadge(e.status).label }}</span></td>
                                    <td class="small"><div class="text-break">{{ e.to_email }}</div><div v-if="e.to_name" class="text-muted">{{ e.to_name }}</div></td>
                                    <td class="small"><div class="font-monospace text-muted">{{ e.template_key ?? '—' }}</div><div>{{ e.subject }}</div></td>
                                    <td class="small text-nowrap" :title="e.sender_address ?? undefined">{{ accountLabel(e.mail_account) }}</td>
                                    <td class="small text-nowrap">
                                        <router-link v-if="e.application" :to="`/admin/applications/${e.application.uuid}`">{{ e.application.reference_no ?? 'Draft' }}</router-link>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td class="small text-nowrap">{{ formatDateTime(e.queued_at ?? e.created_at) }}</td>
                                    <td class="small text-nowrap">
                                        <span v-if="e.sent_at" class="text-success">{{ formatDateTime(e.sent_at) }}</span>
                                        <span v-else-if="e.failed_at" class="text-danger">{{ formatDateTime(e.failed_at) }}</span>
                                        <span v-else-if="e.next_attempt_at" class="text-muted">Next try {{ formatDateTime(e.next_attempt_at) }}</span>
                                        <span v-else class="text-muted">Waiting</span>
                                    </td>
                                    <td class="text-center" :class="{ 'fw-bold text-danger': e.attempts > 1 }">{{ e.attempts }}</td>
                                    <td class="text-end">
                                        <button v-if="e.send_attempts?.length" type="button" class="btn btn-sm btn-outline-secondary" :aria-expanded="open.has(e.id)"
                                            :aria-label="`${open.has(e.id) ? 'Hide' : 'Show'} sending attempts for ${e.to_email}`" @click="toggle(e.id)">
                                            <i class="bi" :class="open.has(e.id) ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="e.error" :class="e.status === 'failed' ? 'table-danger' : 'table-warning'">
                                    <td></td>
                                    <td colspan="8" class="small text-danger text-break"><i class="bi bi-exclamation-triangle me-1"></i><strong>{{ e.status === 'failed' ? 'Error' : 'Last error' }}:</strong> {{ e.error }}</td>
                                </tr>
                                <tr v-if="open.has(e.id)" class="table-light">
                                    <td colspan="9">
                                        <div class="small fw-semibold mb-1">Sending attempts</div>
                                        <ol class="list-unstyled small mb-0 vstack gap-1">
                                            <li v-for="t in e.send_attempts" :key="t.id" class="d-flex flex-wrap gap-2 align-items-baseline">
                                                <span class="text-muted">#{{ t.attempt }}</span>
                                                <span class="badge" :class="t.outcome === 'sent' ? 'text-bg-success' : 'text-bg-danger'">{{ t.outcome === 'sent' ? 'Accepted' : `Failed · ${FAILURE_TYPES[t.failure_type ?? ''] ?? humanize(t.failure_type ?? 'error')}` }}</span>
                                                <span>{{ accountLabel(t.mail_account) }}<span v-if="t.sender_address" class="text-muted"> ({{ t.sender_address }})</span></span>
                                                <span class="text-muted text-nowrap">{{ formatDateTime(t.started_at) }}</span>
                                                <span v-if="t.response" class="font-monospace text-break">{{ t.response }}</span>
                                                <span v-if="t.job_id" class="text-muted font-monospace" title="Queue job">job {{ t.job_id.slice(0, 8) }}</span>
                                            </li>
                                        </ol>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!emails.length"><td colspan="9" class="text-center text-muted py-3">No emails.</td></tr>
                        </tbody>
                    </table>

                    <!-- Document access -->
                    <table v-else-if="logType === 'access'" class="table table-sm table-cards align-middle mb-0">
                        <thead class="table-light"><tr><th>When</th><th>User</th><th>Action</th><th>Resource</th><th>Application</th><th>IP</th></tr></thead>
                        <tbody>
                            <tr v-for="a in accesses" :key="a.id">
                                <td class="small text-nowrap">{{ formatDateTime(a.created_at) }}</td>
                                <td class="small">{{ a.user?.name ?? '—' }}<div v-if="a.user" class="text-muted">{{ a.user.email }}</div></td>
                                <td><span class="badge" :class="a.action === 'download' ? 'text-bg-primary' : 'text-bg-light border'"><i class="bi me-1" :class="a.action === 'download' ? 'bi-download' : 'bi-eye'"></i>{{ humanize(a.action) }}</span></td>
                                <td class="small">{{ humanize(a.resource_type) }} <span class="text-muted">#{{ a.resource_id }}</span></td>
                                <td class="small">{{ a.application_id ? `#${a.application_id}` : '—' }}</td>
                                <td class="small text-muted">{{ a.ip_address ?? '' }}</td>
                            </tr>
                            <tr v-if="!accesses.length"><td colspan="6" class="text-center text-muted py-3">No entries.</td></tr>
                        </tbody>
                    </table>

                    <!-- Exports -->
                    <table v-else class="table table-sm table-cards align-middle mb-0">
                        <thead class="table-light"><tr><th>When</th><th>User</th><th>Report</th><th>Format</th><th>Filters</th><th class="text-end">Rows</th><th>IP</th></tr></thead>
                        <tbody>
                            <tr v-for="x in exportsRows" :key="x.id">
                                <td class="small text-nowrap">{{ formatDateTime(x.created_at) }}</td>
                                <td class="small">{{ x.user?.name ?? '—' }}<div v-if="x.user" class="text-muted">{{ x.user.email }}</div></td>
                                <td class="small">{{ humanize(x.report) }} <span v-if="x.is_sensitive" class="badge text-bg-danger ms-1"><i class="bi bi-shield-lock-fill"></i> Sensitive</span></td>
                                <td class="small text-uppercase">{{ x.format }}</td>
                                <td class="small text-muted">{{ filterText(x.filters) }}</td>
                                <td class="small text-end">{{ x.row_count }}</td>
                                <td class="small text-muted">{{ x.ip_address ?? '' }}</td>
                            </tr>
                            <tr v-if="!exportsRows.length"><td colspan="7" class="text-center text-muted py-3">No exports.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="result" class="card-body py-2">
                    <Pager :page="result.current_page" :last-page="result.last_page" :total="result.total" @change="goPage" />
                    <div v-if="result.last_page <= 1" class="small text-muted">{{ result.total }} record{{ result.total === 1 ? '' : 's' }}</div>
                </div>
            </LoadingState>
        </div>
    </template>
</template>
