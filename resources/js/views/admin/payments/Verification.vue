<script setup lang="ts">
/**
 * Application fee verification: every application the user can see with the fee details the
 * candidate entered. Verify / reject is recorded per payment (reason required to reject), with the
 * reviewer and time kept in the payment history and the audit trail.
 */
import { onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import BaseModal from '@/components/BaseModal.vue';
import FieldError from '@/components/FieldError.vue';
import LoadingState from '@/components/LoadingState.vue';
import Pager from '@/components/Pager.vue';
import StatCard from '@/components/StatCard.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { useAuth } from '@/stores/auth';
import { formatDate, formatDateTime, formatMoney } from '@/utils/format';
import { PAYMENT_METHOD_LABELS } from '@/views/applicant/wizard/context';
import { loadAdOptions, type AdOption } from '../applications/parts/options';

interface Row {
    uuid: string; reference_no: string | null; name: string | null; advertisement: string; post: string; post_code: string;
    application_status: string; submitted_at: string | null; fee_status: string; amount_due: number | null; amount: number | null;
    discrepancy: boolean; method: string | null; utr_number: string | null; transaction_date: string | null; bank_name: string | null;
    config_version: number | null; remarks: string | null; verified_by: string | null; verified_at: string | null;
    receipts: { uuid: string; name: string }[];
}

const STATUS_LABELS: Record<string, string> = {
    pending_verification: 'Pending verification', details_submitted: 'Details submitted (draft)', pending_payment: 'Pending payment',
    verified: 'Verified', rejected: 'Rejected', exempt: 'Exempted', not_required: 'Not required',
};
const CARDS = ['pending_verification', 'verified', 'rejected', 'details_submitted', 'pending_payment', 'exempt'];
const route = useRoute();
const router = useRouter();
const auth = useAuth();
const loading = ref(true);
const error = ref<string | null>(null);
const rows = ref<Row[]>([]);
const counts = ref<Record<string, number>>({});
const meta = ref({ current_page: 1, last_page: 1, total: 0 });
const ads = ref<AdOption[]>([]);
const fromQuery = () => ({
    status: typeof route.query.status === 'string' ? route.query.status : 'pending_verification',
    advertisement_id: typeof route.query.advertisement_id === 'string' ? route.query.advertisement_id : '',
    q: typeof route.query.q === 'string' ? route.query.q : '',
    page: Number(route.query.page ?? 1),
});
const q = reactive(fromQuery());

async function load() {
    loading.value = true; error.value = null;
    try {
        const { data } = await api.get('/admin/payments', { params: { status: q.status || 'all', advertisement_id: q.advertisement_id || undefined, q: q.q || undefined, page: q.page } });
        rows.value = data.data; counts.value = data.counts; meta.value = data.meta;
    } catch (e) { error.value = toApiError(e).message; } finally { loading.value = false; }
}
function apply(page = 1) {
    router.replace({ query: { status: q.status || 'all', advertisement_id: q.advertisement_id || undefined, q: q.q || undefined, page: page > 1 ? String(page) : undefined } });
}
watch(() => route.query, () => { Object.assign(q, fromQuery()); if (q.status === 'all') q.status = ''; load(); });
onMounted(async () => { if (q.status === 'all') q.status = ''; load(); try { ads.value = await loadAdOptions(); } catch { /* the filter is optional */ } });

// ------------------------------------------------------------------ verify / reject
const target = ref<Row | null>(null);
const vf = reactive({ status: 'verified', remarks: '' });
const form = useForm();
function openVerify(r: Row, status: 'verified' | 'rejected') { target.value = r; vf.status = status; vf.remarks = ''; form.clear(); }
async function record() {
    const r = target.value!;
    const res = await form.run(() => api.post(`/admin/applications/${r.uuid}/payment`, { status: vf.status, remarks: vf.remarks || null }), vf.status === 'verified' ? 'Payment verified.' : 'Payment rejected.');
    if (res) { target.value = null; load(); }
}
</script>

<template>
    <div>
        <div class="mb-3">
            <h1 class="page-title h4 mb-0">Fee verification</h1>
            <p class="page-subtitle mb-0">Check each payment against the Institute's bank statement before verifying it. Payment details entered by a candidate, or a QR code being shown, are never treated as proof of payment.</p>
        </div>

        <div class="row g-3 mb-3">
            <div v-for="s in CARDS" :key="s" class="col-6 col-md-4 col-xl-2">
                <StatCard :label="STATUS_LABELS[s]" :value="counts[s]" :tone="s === 'pending_verification' ? 'warning' : s === 'verified' ? 'success' : s === 'rejected' ? 'danger' : 'default'"
                    :to="{ query: { ...route.query, status: s, page: undefined } }" />
            </div>
        </div>

        <form class="card mb-3" @submit.prevent="apply()">
            <div class="card-body row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small" for="pv-status">Fee status</label>
                    <select id="pv-status" v-model="q.status" class="form-select form-select-sm" @change="apply()">
                        <option value="">All</option>
                        <option v-for="(label, key) in STATUS_LABELS" :key="key" :value="key">{{ label }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small" for="pv-ad">Advertisement</label>
                    <select id="pv-ad" v-model="q.advertisement_id" class="form-select form-select-sm" @change="apply()">
                        <option value="">All advertisements</option>
                        <option v-for="a in ads" :key="a.id" :value="String(a.id)">{{ a.number }}</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="pv-q">Name, reference, email or mobile</label>
                    <input id="pv-q" v-model.trim="q.q" type="search" class="form-control form-control-sm">
                </div>
                <div class="col-md-2"><button type="submit" class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-search me-1"></i>Search</button></div>
            </div>
        </form>

        <LoadingState :loading="loading" :error="error">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 table-cards">
                        <thead class="table-light">
                            <tr><th>Applicant</th><th>Advt. / post</th><th>Fee / paid</th><th>Method &amp; reference</th><th>Date</th><th>Receipt</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in rows" :key="r.uuid">
                                <td>
                                    <router-link :to="{ path: `/admin/applications/${r.uuid}`, query: { tab: 'fee' } }" class="fw-semibold small">{{ r.name ?? '—' }}</router-link>
                                    <div class="small text-muted">{{ r.reference_no ?? 'Draft — not submitted' }}</div>
                                </td>
                                <td class="small">{{ r.advertisement }}<div class="text-muted">{{ r.post_code }} · {{ r.post }}</div></td>
                                <td class="small text-nowrap">
                                    {{ r.amount_due !== null ? formatMoney(r.amount_due) : '—' }}
                                    <div v-if="r.amount !== null" :class="r.discrepancy ? 'text-danger fw-semibold' : 'text-muted'">Paid {{ formatMoney(r.amount) }}<span v-if="r.discrepancy"> · mismatch</span></div>
                                </td>
                                <td class="small">
                                    {{ r.method ? (PAYMENT_METHOD_LABELS[r.method] ?? r.method) : '—' }}
                                    <div v-if="r.utr_number" class="font-monospace">{{ r.utr_number }}</div>
                                    <div v-if="r.bank_name" class="text-muted">{{ r.bank_name }}</div>
                                </td>
                                <td class="small text-nowrap">{{ formatDate(r.transaction_date) }}</td>
                                <td class="small">
                                    <template v-if="r.receipts.length && auth.can('documents.download')">
                                        <a v-for="d in r.receipts" :key="d.uuid" :href="`/api/applications/${r.uuid}/documents/${d.uuid}`" target="_blank" rel="noopener" class="d-block text-truncate" style="max-width: 10rem"><i class="bi bi-paperclip" aria-hidden="true"></i> {{ d.name }}</a>
                                    </template>
                                    <span v-else-if="r.receipts.length" class="text-muted">{{ r.receipts.length }} file(s)</span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td>
                                    <StatusBadge :value="r.fee_status" :label="STATUS_LABELS[r.fee_status]" />
                                    <div v-if="r.verified_by" class="small text-muted">{{ r.verified_by }} · {{ formatDateTime(r.verified_at) }}</div>
                                    <div v-if="r.remarks" class="small">{{ r.remarks }}</div>
                                </td>
                                <td class="text-end text-nowrap">
                                    <template v-if="r.submitted_at && ['pending_verification', 'verified', 'rejected'].includes(r.fee_status)">
                                        <button v-if="r.fee_status !== 'verified'" type="button" class="btn btn-sm btn-outline-success me-1" @click="openVerify(r, 'verified')">Verify</button>
                                        <button v-if="r.fee_status !== 'rejected'" type="button" class="btn btn-sm btn-outline-danger" @click="openVerify(r, 'rejected')">Reject</button>
                                    </template>
                                </td>
                            </tr>
                            <tr v-if="!rows.length"><td colspan="8" class="text-center text-muted small py-4">No applications in this view.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <Pager :page="meta.current_page" :last-page="meta.last_page" :total="meta.total" @change="apply" />
        </LoadingState>

        <BaseModal v-if="target" :title="vf.status === 'verified' ? 'Verify payment' : 'Reject payment'" @close="target = null">
            <table class="table table-sm kv-table small mb-3">
                <tbody>
                    <tr><th scope="row">Applicant</th><td>{{ target.name }} <span class="text-muted">({{ target.reference_no }})</span></td></tr>
                    <tr><th scope="row">Fee payable</th><td>{{ formatMoney(target.amount_due) }}</td></tr>
                    <tr><th scope="row">Amount claimed</th><td :class="{ 'text-danger fw-semibold': target.discrepancy }">{{ formatMoney(target.amount) }}</td></tr>
                    <tr><th scope="row">Reference</th><td class="font-monospace">{{ target.utr_number }} · {{ formatDate(target.transaction_date) }}</td></tr>
                </tbody>
            </table>
            <label class="form-label" :class="{ required: vf.status === 'rejected' }" for="pv-remarks">{{ vf.status === 'rejected' ? 'Reason for rejection' : 'Remarks' }}</label>
            <textarea id="pv-remarks" v-model="vf.remarks" class="form-control" rows="3" maxlength="500" :class="{ 'is-invalid': form.errors.remarks }"></textarea>
            <FieldError :errors="form.errors" name="remarks" />
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="target = null">Cancel</button>
                <button type="button" class="btn" :class="vf.status === 'verified' ? 'btn-success' : 'btn-danger'" :disabled="form.busy.value || (vf.status === 'rejected' && !vf.remarks.trim())" @click="record">
                    {{ vf.status === 'verified' ? 'Verify payment' : 'Reject payment' }}
                </button>
            </template>
        </BaseModal>
    </div>
</template>
