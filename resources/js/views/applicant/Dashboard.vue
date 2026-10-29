<script setup lang="ts">
import StatCard from '@/components/StatCard.vue';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, download, toApiError } from '@/api/client';
import { useAuth } from '@/stores/auth';
import { useToast } from '@/stores/toast';
import { useForm } from '@/composables/useForm';
import LoadingState from '@/components/LoadingState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import BaseModal from '@/components/BaseModal.vue';
import { formatDate, formatDateTime } from '@/utils/format';
import {
    feeLabels, refereeLabels, statusLabels,
    type AppNotification, type DashboardApplication, type DashboardPayload,
} from './status/types';

const auth = useAuth();
const toast = useToast();
const route = useRoute();
const router = useRouter();

const data = ref<DashboardPayload | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

// One-off notices carried in the query string (registration / email verification).
const justVerified = ref(route.query.verified === '1');
const justRegistered = ref(route.query.registered === '1');
if (justVerified.value || justRegistered.value) router.replace({ query: {} });

const resend = useForm();
const resent = ref(false);
const applying = ref<number | null>(null);
const applyErrors = reactive<Record<number, string>>({});
const downloading = ref<string | null>(null);
const discardTarget = ref<DashboardApplication | null>(null);
const discard = useForm();

const apps = computed(() => data.value?.applications ?? []);
const inProgress = computed(() => apps.value.filter((a) => a.status === 'draft' || a.status === 'reopened'));
const submitted = computed(() => apps.value.filter((a) => a.status !== 'draft' && a.status !== 'reopened'));
const withClarifications = computed(() => apps.value.filter((a) => a.open_clarifications > 0));
const clarificationCount = computed(() => withClarifications.value.reduce((n, a) => n + a.open_clarifications, 0));
const openPostCount = computed(() => (data.value?.open_advertisements ?? []).reduce((n, ad) => n + ad.posts.length, 0));
const emailVerified = computed(() => data.value?.email_verified ?? auth.user?.email_verified ?? true);

const THREE_DAYS = 3 * 86_400_000;
interface Deadline { app: DashboardApplication; at: string; label: string; urgent: boolean; passed: boolean }
const deadlines = computed<Deadline[]>(() => inProgress.value
    .map((a) => {
        const at = a.status === 'reopened' && a.reopened_until ? a.reopened_until : a.closes_at;
        const ms = new Date(at).getTime() - Date.now();
        return {
            app: a, at,
            label: a.status === 'reopened' && a.reopened_until ? 'Corrections due' : 'Advertisement closes',
            urgent: ms >= 0 && ms <= THREE_DAYS,
            passed: ms < 0,
        };
    })
    .sort((x, y) => new Date(x.at).getTime() - new Date(y.at).getTime()));

function timeLeft(iso: string): string {
    const ms = new Date(iso).getTime() - Date.now();
    if (ms < 0) return 'Closed';
    const hours = Math.floor(ms / 3_600_000);
    if (hours < 24) return hours <= 1 ? 'Less than an hour left' : `${hours} hours left`;
    const days = Math.floor(hours / 24);
    return `${days} day${days === 1 ? '' : 's'} left`;
}

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const res = await api.get<DashboardPayload>('/applicant/dashboard');
        data.value = res.data;
        if (auth.user && auth.user.email_verified !== res.data.email_verified) auth.user.email_verified = res.data.email_verified;
    } catch (e) {
        error.value = toApiError(e).message;
    } finally {
        loading.value = false;
    }
}

async function resendVerification() {
    const ok = await resend.run(() => api.post('/auth/email/resend'), 'Verification email sent.');
    if (ok) resent.value = true;
}

async function apply(postId: number) {
    delete applyErrors[postId];
    applying.value = postId;
    try {
        const res = await api.post<{ uuid: string }>('/applications', { post_id: postId });
        router.push(`/applications/${res.data.uuid}/edit/personal`);
    } catch (e) {
        const ae = toApiError(e);
        applyErrors[postId] = ae.errors.post_id?.[0] ?? ae.message;
    } finally {
        applying.value = null;
    }
}

async function get(kind: 'pdf' | 'receipt', a: DashboardApplication) {
    const key = `${kind}-${a.uuid}`;
    downloading.value = key;
    const refNo = (a.reference_no ?? a.uuid).replace(/\//g, '-');
    try {
        await download(`/applications/${a.uuid}/${kind}`, kind === 'pdf' ? `Application-${refNo}.pdf` : `Acknowledgment-${refNo}.pdf`);
    } catch (e) {
        toast.error(toApiError(e).message);
    } finally {
        downloading.value = null;
    }
}

async function confirmDiscard() {
    const target = discardTarget.value;
    if (!target) return;
    const ok = await discard.run(() => api.delete(`/applications/${target.uuid}`), 'Draft discarded.');
    if (ok) {
        discardTarget.value = null;
        await load();
    }
}

async function markRead(n?: AppNotification) {
    try {
        await api.post('/notifications/read', n ? { id: n.id } : {});
        if (data.value) data.value.notifications = n ? data.value.notifications.filter((x) => x.id !== n.id) : [];
    } catch (e) {
        toast.error(toApiError(e).message);
    }
}

async function openNotification(n: AppNotification) {
    await markRead(n);
    if (n.data.link && n.data.link.startsWith('/')) router.push(n.data.link);
}

onMounted(load);
</script>

<template>
    <div>
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
            <div>
                <h1 class="page-title h3 mb-0">My applications</h1>
                <div class="text-muted small">Signed in as {{ auth.user?.name }} ({{ auth.user?.email }})</div>
            </div>
            <router-link to="/" class="btn btn-outline-primary btn-sm"><i class="bi bi-megaphone me-1" aria-hidden="true"></i>All advertisements</router-link>
        </div>

        <div v-if="justVerified" class="alert alert-success alert-dismissible" role="status">
            <i class="bi bi-patch-check me-1" aria-hidden="true"></i>Thank you — your email address has been verified.
            <button type="button" class="btn-close" aria-label="Close" @click="justVerified = false"></button>
        </div>
        <div v-if="justRegistered" class="alert alert-info alert-dismissible" role="status">
            <strong>Your account has been created.</strong> A verification link has been sent to <strong>{{ auth.user?.email }}</strong>. Please open it to verify your email address. You can start an application meanwhile.
            <button type="button" class="btn-close" aria-label="Close" @click="justRegistered = false"></button>
        </div>

        <LoadingState :loading="loading" :error="error">
            <template v-if="data">
                <div v-if="!emailVerified && !justRegistered" class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="alert">
                    <i class="bi bi-envelope-exclamation fs-5" aria-hidden="true"></i>
                    <span class="flex-grow-1">Your email address is not verified yet. Open the link in the verification email sent to <strong>{{ auth.user?.email }}</strong>.</span>
                    <span v-if="resent" class="small text-success"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>New link sent.</span>
                    <button v-else type="button" class="btn btn-sm btn-outline-dark" :disabled="resend.busy.value" @click="resendVerification">
                        <span v-if="resend.busy.value" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Resend email
                    </button>
                </div>
                <div v-else-if="!emailVerified && justRegistered" class="small text-muted mb-3">
                    Did not receive the verification email?
                    <span v-if="resent" class="text-success">A new link has been sent.</span>
                    <button v-else type="button" class="btn btn-link btn-sm p-0 align-baseline" :disabled="resend.busy.value" @click="resendVerification">Resend it</button>
                </div>

                <!-- Summary -->
                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3"><StatCard label="In progress" :value="inProgress.length" icon="pencil-square" tone="brand" class="h-100" /></div>
                    <div class="col-6 col-lg-3"><StatCard label="Submitted" :value="submitted.filter((a) => a.status !== 'withdrawn').length" icon="send-check" tone="success" class="h-100" /></div>
                    <div class="col-6 col-lg-3"><StatCard label="Clarifications pending" :value="clarificationCount" icon="chat-left-text" :tone="clarificationCount ? 'warning' : 'default'" class="h-100" /></div>
                    <div class="col-6 col-lg-3"><StatCard label="Posts open now" :value="openPostCount" icon="megaphone" tone="brand" class="h-100" /></div>
                </div>

                <!-- Clarifications -->
                <div v-if="withClarifications.length" class="alert alert-warning" role="alert">
                    <h2 class="h6 alert-heading"><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i>Clarification requested ({{ clarificationCount }})</h2>
                    <p class="small mb-2">The Recruitment Section has asked for clarification on the following application{{ withClarifications.length === 1 ? '' : 's' }}. Please respond before the due date shown on the application page.</p>
                    <ul class="mb-0 small">
                        <li v-for="a in withClarifications" :key="a.uuid">
                            <router-link :to="`/applications/${a.uuid}`" class="alert-link">{{ a.reference_no ?? a.post }}</router-link>
                            — {{ a.post }} ({{ a.open_clarifications }} open request{{ a.open_clarifications === 1 ? '' : 's' }})
                        </li>
                    </ul>
                </div>

                <div class="row g-3">
                    <div class="col-xl-8 vstack gap-3">
                        <!-- In progress -->
                        <section class="card section-card">
                            <h2 class="card-header h6 mb-0">Applications in progress</h2>
                            <div v-if="!inProgress.length" class="card-body text-muted small">You have no unsubmitted applications. Choose a post from the open advertisements to start one.</div>
                            <ul v-else class="list-group list-group-flush">
                                <li v-for="a in inProgress" :key="a.uuid" class="list-group-item py-3">
                                    <div class="d-flex flex-wrap justify-content-between gap-2">
                                        <div>
                                            <div class="fw-semibold">{{ a.post }} <span class="text-muted small">({{ a.post_code }})</span></div>
                                            <div class="small text-muted">Advt. No. {{ a.advertisement }} · Last saved {{ formatDateTime(a.updated_at) }}</div>
                                            <div class="small mt-1">
                                                <StatusBadge :value="a.status" :label="statusLabels[a.status]" />
                                                <span class="ms-1">{{ a.stage }}</span>
                                            </div>
                                            <div v-if="a.status === 'reopened' && a.reopened_until" class="small mt-1 text-warning-emphasis">
                                                <i class="bi bi-clock me-1" aria-hidden="true"></i>Resubmit corrections by {{ formatDateTime(a.reopened_until) }}
                                            </div>
                                            <div v-else class="small mt-1 text-muted">Last date: {{ formatDateTime(a.closes_at) }} ({{ timeLeft(a.closes_at) }})</div>
                                        </div>
                                        <div class="d-flex flex-wrap align-items-start gap-2">
                                            <router-link v-if="a.editable" :to="`/applications/${a.uuid}/edit`" class="btn btn-primary btn-sm">
                                                <i class="bi bi-pencil me-1" aria-hidden="true"></i>Continue
                                            </router-link>
                                            <span v-else class="small text-muted">Editing closed</span>
                                            <router-link v-if="a.status === 'reopened'" :to="`/applications/${a.uuid}`" class="btn btn-outline-secondary btn-sm">Status</router-link>
                                            <button v-if="a.status === 'draft' && !a.submitted_at" type="button" class="btn btn-outline-danger btn-sm" @click="discardTarget = a">
                                                <i class="bi bi-trash me-1" aria-hidden="true"></i>Discard
                                            </button>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </section>

                        <!-- Submitted -->
                        <section class="card section-card">
                            <h2 class="card-header h6 mb-0">Submitted applications</h2>
                            <div v-if="!submitted.length" class="card-body text-muted small">No applications submitted yet.</div>
                            <div v-else class="table-responsive">
                                <table class="table table-sm table-cards align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th scope="col">Reference / Post</th>
                                            <th scope="col">Submitted</th>
                                            <th scope="col">Status</th>
                                            <th scope="col">Fee</th>
                                            <th scope="col">Referees</th>
                                            <th scope="col" class="text-end">Documents</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="a in submitted" :key="a.uuid">
                                            <td>
                                                <router-link :to="`/applications/${a.uuid}`" class="fw-semibold">{{ a.reference_no ?? '—' }}</router-link>
                                                <div class="small">{{ a.post }}</div>
                                                <div class="small text-muted">Advt. No. {{ a.advertisement }}</div>
                                            </td>
                                            <td class="small text-nowrap">{{ formatDate(a.submitted_at) }}<div v-if="a.version > 1" class="text-muted">Version {{ a.version }}</div></td>
                                            <td class="small">
                                                <StatusBadge :value="a.status" :label="statusLabels[a.status]" />
                                                <div class="mt-1">{{ a.stage }}</div>
                                                <div v-if="a.open_clarifications" class="text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Clarification due</div>
                                            </td>
                                            <td class="small"><StatusBadge :value="a.fee_status" :label="feeLabels[a.fee_status]" /></td>
                                            <td class="small"><StatusBadge :value="a.referee_status" :label="refereeLabels[a.referee_status]" /></td>
                                            <td class="text-end text-nowrap">
                                                <div class="btn-group btn-group-sm" role="group" :aria-label="`Downloads for ${a.reference_no}`">
                                                    <button type="button" class="btn btn-outline-secondary" :disabled="!!downloading" title="Download application PDF" @click="get('pdf', a)">
                                                        <span v-if="downloading === `pdf-${a.uuid}`" class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                                        <i v-else class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Application
                                                    </button>
                                                    <button type="button" class="btn btn-outline-secondary" :disabled="!!downloading || !a.submitted_at" title="Download acknowledgment receipt" @click="get('receipt', a)">
                                                        <span v-if="downloading === `receipt-${a.uuid}`" class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                                        <i v-else class="bi bi-receipt" aria-hidden="true"></i> Receipt
                                                    </button>
                                                </div>
                                                <div><router-link :to="`/applications/${a.uuid}`" class="small">View status <i class="bi bi-chevron-right" aria-hidden="true"></i></router-link></div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div v-if="submitted.length" class="card-footer bg-white small text-muted">
                                Referee column shows only whether recommendations have been received. Their contents are confidential and are not shown to applicants.
                            </div>
                        </section>

                        <!-- Open advertisements -->
                        <section class="card section-card">
                            <h2 class="card-header h6 mb-0">Posts open for applications</h2>
                            <div v-if="!data.open_advertisements.length" class="card-body text-muted small">No advertisement is open at present.</div>
                            <div v-for="ad in data.open_advertisements" :key="ad.id" class="card-body border-bottom">
                                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                    <div>
                                        <router-link :to="`/advertisements/${ad.id}`" class="fw-semibold">{{ ad.title }}</router-link>
                                        <div class="small text-muted">Advt. No. {{ ad.number }}</div>
                                    </div>
                                    <div class="small text-end" :class="new Date(ad.closes_at).getTime() - Date.now() <= THREE_DAYS ? 'text-danger fw-semibold' : 'text-muted'">
                                        Closes {{ formatDateTime(ad.closes_at) }}<br>{{ timeLeft(ad.closes_at) }}
                                    </div>
                                </div>
                                <ul class="list-group">
                                    <li v-for="p in ad.posts" :key="p.id" class="list-group-item">
                                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                            <div>
                                                <div>{{ p.name }} <span class="text-muted small">({{ p.code }})</span></div>
                                                <div class="small text-muted">{{ p.pay_level ?? '' }}<span v-if="p.pay_level"> · </span>{{ p.total_vacancies }} vacanc{{ p.total_vacancies === 1 ? 'y' : 'ies' }}</div>
                                            </div>
                                            <span v-if="p.applied" class="badge text-bg-light border"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Applied</span>
                                            <div v-else class="d-flex gap-2">
                                                <router-link :to="`/advertisements/${ad.id}#post-${p.id}`" class="btn btn-sm btn-outline-secondary">Eligibility</router-link>
                                                <button type="button" class="btn btn-sm btn-primary" :disabled="applying !== null" @click="apply(p.id)">
                                                    <span v-if="applying === p.id" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Apply
                                                </button>
                                            </div>
                                        </div>
                                        <div v-if="applyErrors[p.id]" class="text-danger small mt-1" role="alert"><i class="bi bi-exclamation-circle me-1" aria-hidden="true"></i>{{ applyErrors[p.id] }}</div>
                                    </li>
                                </ul>
                            </div>
                        </section>
                    </div>

                    <div class="col-xl-4 vstack gap-3">
                        <!-- Deadlines -->
                        <section class="card section-card">
                            <h2 class="card-header h6 mb-0"><i class="bi bi-calendar-event me-1" aria-hidden="true"></i>Important deadlines</h2>
                            <div v-if="!deadlines.length" class="card-body small text-muted">No pending deadlines for your applications.</div>
                            <ul v-else class="list-group list-group-flush small">
                                <li v-for="d in deadlines" :key="d.app.uuid" class="list-group-item" :class="{ 'list-group-item-danger': d.urgent, 'list-group-item-secondary': d.passed }">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="fw-semibold">{{ d.app.post }}</span>
                                        <span v-if="d.urgent" class="badge text-bg-danger">Due soon</span>
                                    </div>
                                    <div>{{ d.label }}: <strong>{{ formatDateTime(d.at) }}</strong></div>
                                    <div :class="d.urgent ? 'text-danger' : 'text-muted'">{{ timeLeft(d.at) }}</div>
                                </li>
                            </ul>
                        </section>

                        <!-- Notifications -->
                        <section class="card section-card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h2 class="h6 mb-0"><i class="bi bi-bell me-1" aria-hidden="true"></i>Notifications</h2>
                                <button v-if="data.notifications.length" type="button" class="btn btn-link btn-sm p-0" @click="markRead()">Mark all read</button>
                            </div>
                            <div v-if="!data.notifications.length" class="card-body small text-muted">No new notifications.</div>
                            <ul v-else class="list-group list-group-flush small">
                                <li v-for="n in data.notifications" :key="n.id" class="list-group-item">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="fw-semibold">{{ n.data.title ?? 'Notification' }}</span>
                                        <button type="button" class="btn-close btn-sm" style="font-size: .6rem" aria-label="Mark as read" title="Mark as read" @click="markRead(n)"></button>
                                    </div>
                                    <div v-if="n.data.message">{{ n.data.message }}</div>
                                    <div class="d-flex justify-content-between text-muted mt-1">
                                        <span>{{ formatDateTime(n.created_at) }}</span>
                                        <button v-if="n.data.link" type="button" class="btn btn-link btn-sm p-0" @click="openNotification(n)">Open</button>
                                    </div>
                                </li>
                            </ul>
                        </section>

                        <section class="card section-card">
                            <h2 class="card-header h6 mb-0">Help</h2>
                            <div class="card-body small">
                                <ul class="mb-0 ps-3">
                                    <li>Your application is saved as you complete each step. You can return to it any time before the last date.</li>
                                    <li>After submission you will receive a reference number and an acknowledgment receipt.</li>
                                    <li>Keep your email and mobile reachable — clarification requests are sent there.</li>
                                </ul>
                            </div>
                        </section>
                    </div>
                </div>
            </template>
        </LoadingState>

        <BaseModal v-if="discardTarget" title="Discard draft application?" @close="discardTarget = null">
            <p>This will permanently delete your unsubmitted application for <strong>{{ discardTarget.post }}</strong> (Advt. No. {{ discardTarget.advertisement }}), including any documents you have uploaded to it.</p>
            <p class="mb-0 text-danger small">This cannot be undone. You may start a new application for the post later while the advertisement is open.</p>
            <div v-if="discard.message.value" class="alert alert-danger small mt-3 mb-0">{{ discard.message.value }}</div>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="discardTarget = null">Keep draft</button>
                <button type="button" class="btn btn-danger" :disabled="discard.busy.value" @click="confirmDiscard">
                    <span v-if="discard.busy.value" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Discard draft
                </button>
            </template>
        </BaseModal>
    </div>
</template>
