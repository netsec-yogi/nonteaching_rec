<script setup lang="ts">
/** Staff dashboard: headline counts, breakdowns as tables with bars, open advertisements and unread alerts. */
import StatCard from '@/components/StatCard.vue';
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, toApiError } from '@/api/client';
import { useAuth } from '@/stores/auth';
import { useToast } from '@/stores/toast';
import LoadingState from '@/components/LoadingState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { formatDate, formatDateTime, humanize } from '@/utils/format';
import { loadAdOptions, type AdOption } from './applications/parts/options';

interface DashboardData {
    cards: Record<string, number | null>;
    by_scrutiny_status: Record<string, number> | [];
    by_post: Record<string, number> | [];
    daily: Record<string, number> | [];
    advertisements: { id: number; number: string; title: string; status: string; closes_at: string | null }[];
    notifications: { id: string; data: { title?: string; message?: string; link?: string | null }; created_at: string }[];
}

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const toast = useToast();

const ads = ref<AdOption[]>([]);
const data = ref<DashboardData | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const adId = computed(() => (typeof route.query.advertisement_id === 'string' ? route.query.advertisement_id : ''));

async function load() {
    loading.value = !data.value;
    error.value = null;
    try {
        data.value = (await api.get<DashboardData>('/admin/dashboard', { params: adId.value ? { advertisement_id: adId.value } : {} })).data;
    } catch (e) {
        error.value = toApiError(e).message;
    } finally {
        loading.value = false;
    }
}
watch(adId, load);
onMounted(() => {
    load();
    loadAdOptions().then((a) => (ads.value = a)).catch(() => undefined);
});

const setAd = (v: string) => router.replace({ query: v ? { advertisement_id: v } : {} });

/** Link to the application list with the dashboard's advertisement filter plus extras. */
const listLink = (extra: Record<string, string> = {}) => ({ path: '/admin/applications', query: { ...(adId.value ? { advertisement_id: adId.value } : {}), ...extra } });

const greeting = (() => { const h = new Date().getHours(); return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening'; })();
const firstName = (auth.user?.name ?? '').split(/\s+/).find((p) => !/^(dr|prof|shri|smt|mr|mrs|ms)\.?$/i.test(p)) ?? '';
const today = new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

const CARDS: { key: string; label: string; icon: string; link?: Record<string, string>; tone?: 'success' | 'warning' | 'danger' }[] = [
    { key: 'submitted', label: 'Submitted', icon: 'inbox', link: { submitted_only: '1' } },
    { key: 'submitted_today', label: 'Submitted today', icon: 'calendar-check' },
    { key: 'drafts', label: 'Drafts in progress', icon: 'pencil', link: { status: 'draft' } },
    { key: 'fee_pending', label: 'Fee verification pending', icon: 'currency-rupee', link: { fee_status: 'pending_verification' }, tone: 'warning' },
    { key: 'scrutiny_pending', label: 'Scrutiny not decided', icon: 'clipboard', link: { submitted_only: '1' } },
    { key: 'clarification_open', label: 'Open clarifications', icon: 'chat-left-text', link: { scrutiny_status: 'clarification_required' }, tone: 'warning' },
    { key: 'referee_pending', label: 'Referees pending', icon: 'hourglass-split', link: { referee_status: 'partial' } },
    { key: 'referee_complete', label: 'All referees complete', icon: 'person-check', link: { referee_status: 'complete' }, tone: 'success' },
    { key: 'referee_delivery_failed', label: 'Referee emails failed', icon: 'envelope-x', tone: 'danger' },
    { key: 'recommended', label: 'Recommended by scrutiny', icon: 'hand-thumbs-up', link: { outcome: 'recommended' }, tone: 'success' },
    { key: 'with_committee', label: 'With Screening Committee', icon: 'people' },
    { key: 'emails_failed', label: 'Failed emails (7 days)', icon: 'exclamation-octagon', tone: 'danger' },
];
const visibleCards = computed(() => CARDS.filter((c) => data.value && data.value.cards[c.key] !== null && data.value.cards[c.key] !== undefined));

const entries = (o: Record<string, number> | [] | undefined) => (o && !Array.isArray(o) ? Object.entries(o) : []);
const scrutiny = computed(() => entries(data.value?.by_scrutiny_status));
const posts = computed(() => entries(data.value?.by_post));
const daily = computed(() => entries(data.value?.daily).sort(([a], [b]) => (a < b ? 1 : -1)));
const sum = (rows: [string, number][]) => rows.reduce((t, [, n]) => t + Number(n), 0);
const max = (rows: [string, number][]) => Math.max(1, ...rows.map(([, n]) => Number(n)));
const pct = (n: number, of: number) => Math.round((Number(n) / Math.max(1, of)) * 100);

function daysLeft(iso: string | null): string {
    if (!iso) return '';
    const d = Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000);
    return d < 0 ? 'closed' : d === 0 ? 'closes today' : `${d} day${d === 1 ? '' : 's'} left`;
}

async function markRead(id?: string) {
    try {
        await api.post('/notifications/read', id ? { id } : {});
        if (data.value) data.value.notifications = id ? data.value.notifications.filter((n) => n.id !== id) : [];
    } catch (e) { toast.error(toApiError(e).message); }
}
function openNotification(n: DashboardData['notifications'][number]) {
    markRead(n.id);
    if (n.data.link?.startsWith('/')) router.push(n.data.link);
}
</script>

<template>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h2 class="page-title mb-0">{{ greeting }}, {{ firstName }}</h2>
            <div class="page-subtitle">{{ today }}</div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <label for="db-ad" class="small text-muted">Advertisement</label>
            <select id="db-ad" class="form-select form-select-sm" style="max-width: 22rem" :value="adId" @change="setAd(($event.target as HTMLSelectElement).value)">
                <option value="">All advertisements</option>
                <option v-for="a in ads" :key="a.id" :value="String(a.id)">{{ a.number }} — {{ a.title }}</option>
            </select>
            <button class="btn btn-sm btn-outline-secondary" title="Refresh" @click="load"><i class="bi bi-arrow-clockwise"></i></button>
        </div>
    </div>

    <LoadingState :loading="loading" :error="error">
        <template v-if="data">
            <div class="row g-3 mb-3">
                <div v-for="c in visibleCards" :key="c.key" class="col-6 col-md-4 col-xl-3">
                    <StatCard :label="c.label" :value="data.cards[c.key]" :icon="c.icon" :tone="c.tone ?? 'brand'" :to="c.link ? listLink(c.link) : undefined" class="h-100" />
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="card section-card h-100">
                        <div class="card-header d-flex justify-content-between"><span>By scrutiny status</span><span class="small fw-normal text-muted">{{ sum(scrutiny) }} submitted</span></div>
                        <table class="table table-sm mb-0">
                            <tbody>
                                <tr v-for="[k, n] in scrutiny" :key="k">
                                    <td style="width: 40%"><router-link :to="listLink({ scrutiny_status: k })"><StatusBadge :value="k" /></router-link></td>
                                    <td>
                                        <div class="progress" style="height: .6rem" role="progressbar" :aria-valuenow="pct(n, sum(scrutiny))" aria-valuemin="0" aria-valuemax="100" :aria-label="humanize(k)">
                                            <div class="progress-bar" :style="{ width: pct(n, sum(scrutiny)) + '%' }"></div>
                                        </div>
                                    </td>
                                    <td class="text-end fw-semibold" style="width: 4rem">{{ n }}</td>
                                    <td class="text-end text-muted small" style="width: 3.5rem">{{ pct(n, sum(scrutiny)) }}%</td>
                                </tr>
                                <tr v-if="!scrutiny.length"><td class="text-muted small">No submitted applications.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card section-card h-100">
                        <div class="card-header d-flex justify-content-between"><span>By post <span class="small fw-normal text-muted">(top 10)</span></span><span class="small fw-normal text-muted">{{ sum(posts) }}</span></div>
                        <table class="table table-sm mb-0">
                            <tbody>
                                <tr v-for="[k, n] in posts" :key="k">
                                    <td style="width: 45%" class="small">{{ k }}</td>
                                    <td><div class="progress" style="height: .6rem" role="progressbar" :aria-valuenow="pct(n, max(posts))" aria-valuemin="0" aria-valuemax="100" :aria-label="k"><div class="progress-bar bg-info" :style="{ width: pct(n, max(posts)) + '%' }"></div></div></td>
                                    <td class="text-end fw-semibold" style="width: 4rem">{{ n }}</td>
                                </tr>
                                <tr v-if="!posts.length"><td class="text-muted small">No submitted applications.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card section-card h-100">
                        <div class="card-header">Submissions per day <span class="small fw-normal text-muted">(last 30 days)</span></div>
                        <div class="overflow-auto" style="max-height: 22rem">
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>Date</th><th></th><th class="text-end">Count</th></tr></thead>
                                <tbody>
                                    <tr v-for="[d, n] in daily" :key="d">
                                        <td class="small text-nowrap">{{ formatDate(d) }}</td>
                                        <td style="width: 45%"><div class="progress" style="height: .5rem" role="progressbar" :aria-valuenow="pct(n, max(daily))" aria-valuemin="0" aria-valuemax="100" :aria-label="d"><div class="progress-bar bg-success" :style="{ width: pct(n, max(daily)) + '%' }"></div></div></td>
                                        <td class="text-end fw-semibold">{{ n }}</td>
                                    </tr>
                                    <tr v-if="!daily.length"><td colspan="3" class="text-muted small">No submissions in the last 30 days.</td></tr>
                                </tbody>
                                <tfoot v-if="daily.length"><tr><th colspan="2">Total</th><th class="text-end">{{ sum(daily) }}</th></tr></tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card section-card h-100">
                        <div class="card-header">Open advertisements</div>
                        <ul class="list-group list-group-flush">
                            <li v-for="a in data.advertisements" :key="a.id" class="list-group-item small">
                                <div class="d-flex justify-content-between gap-2">
                                    <router-link :to="listLink({ advertisement_id: String(a.id) })" class="fw-semibold">{{ a.number }}</router-link>
                                    <StatusBadge :value="a.status" />
                                </div>
                                <div class="text-muted">{{ a.title }}</div>
                                <div>Closes {{ formatDateTime(a.closes_at) }} <span class="badge text-bg-light border ms-1">{{ daysLeft(a.closes_at) }}</span></div>
                            </li>
                            <li v-if="!data.advertisements.length" class="list-group-item small text-muted">No advertisement is open.</li>
                        </ul>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card section-card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>Unread notifications</span>
                            <button v-if="data.notifications.length" class="btn btn-link btn-sm p-0" @click="markRead()">Mark all read</button>
                        </div>
                        <ul class="list-group list-group-flush">
                            <li v-for="n in data.notifications" :key="n.id" class="list-group-item small d-flex gap-2">
                                <i class="bi bi-bell text-primary mt-1"></i>
                                <div class="flex-grow-1">
                                    <a v-if="n.data.link" href="#" class="fw-semibold" @click.prevent="openNotification(n)">{{ n.data.title ?? 'Notification' }}</a>
                                    <div v-else class="fw-semibold">{{ n.data.title ?? 'Notification' }}</div>
                                    <div>{{ n.data.message }}</div>
                                    <div class="text-muted">{{ formatDateTime(n.created_at) }}</div>
                                </div>
                                <button class="btn btn-sm btn-link text-muted p-0 align-self-start" title="Mark read" @click="markRead(n.id)"><i class="bi bi-check2"></i></button>
                            </li>
                            <li v-if="!data.notifications.length" class="list-group-item small text-muted">You're all caught up.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </template>
    </LoadingState>
</template>
