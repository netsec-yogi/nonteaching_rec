<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { api, download, toApiError } from '@/api/client';
import { useToast } from '@/stores/toast';
import LoadingState from '@/components/LoadingState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { formatDate, formatDateTime } from '@/utils/format';
import ClarificationCard from './status/ClarificationCard.vue';
import WithdrawModal from './status/WithdrawModal.vue';
import { endorsementLabels, feeLabels, statusLabels, type StatusHistory, type StatusPayload } from './status/types';

const props = defineProps<{ uuid: string }>();
const toast = useToast();

const data = ref<StatusPayload | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const downloading = ref<string | null>(null);
const showWithdraw = ref(false);

const editable = computed(() => data.value?.status === 'draft' || data.value?.status === 'reopened');
const openClarifications = computed(() => (data.value?.clarifications ?? []).filter((c) => c.can_respond));
const sortedClarifications = computed(() => [...(data.value?.clarifications ?? [])]
    .sort((a, b) => Number(b.can_respond) - Number(a.can_respond) || new Date(b.created_at ?? 0).getTime() - new Date(a.created_at ?? 0).getTime()));
const timeline = computed(() => [...(data.value?.history ?? [])].sort((a, b) => new Date(b.at ?? 0).getTime() - new Date(a.at ?? 0).getTime()));
const versions = computed(() => [...(data.value?.versions ?? [])].sort((a, b) => b.version - a.version));
const refFile = computed(() => (data.value?.reference_no ?? props.uuid).replace(/\//g, '-'));

function historyLabel(h: StatusHistory): string {
    if (h.field === 'fee_status') return `Fee: ${feeLabels[h.to ?? ''] ?? h.to ?? '—'}`;
    return statusLabels[h.to ?? ''] ?? (h.to ? h.to.replace(/_/g, ' ') : '—');
}

function historyIcon(h: StatusHistory): string {
    if (h.field === 'fee_status') return 'bi-currency-rupee';
    return ({ submitted: 'bi-send-check', reopened: 'bi-arrow-counterclockwise', withdrawn: 'bi-x-circle', draft: 'bi-pencil' } as Record<string, string>)[h.to ?? ''] ?? 'bi-dot';
}

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const res = await api.get<StatusPayload>(`/applications/${props.uuid}/status`);
        data.value = res.data;
        document.title = `${res.data.reference_no ?? 'Application'} — Non-Teaching Recruitment — ABV-IIITM Gwalior`;
    } catch (e) {
        const ae = toApiError(e);
        error.value = ae.status === 404 || ae.status === 403 ? 'This application was not found in your account.' : ae.message;
    } finally {
        loading.value = false;
    }
}

async function get(path: string, name: string, key: string) {
    downloading.value = key;
    try {
        await download(path, name);
    } catch (e) {
        toast.error(toApiError(e).message);
    } finally {
        downloading.value = null;
    }
}

const getVersion = (v: number) => get(`/applications/${props.uuid}/pdf/${v}`, `Application-${refFile.value}-v${v}.pdf`, `v${v}`);
const getReceipt = () => get(`/applications/${props.uuid}/receipt`, `Acknowledgment-${refFile.value}.pdf`, 'receipt');

function onWithdrawn() {
    showWithdraw.value = false;
    load();
}

onMounted(load);
watch(() => props.uuid, load);
</script>

<template>
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small">
                <li class="breadcrumb-item"><router-link to="/dashboard">My applications</router-link></li>
                <li class="breadcrumb-item active" aria-current="page">{{ data?.reference_no ?? 'Application' }}</li>
            </ol>
        </nav>

        <LoadingState :loading="loading" :error="error">
            <template v-if="data">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h1 class="page-title h3 mb-1">{{ data.post }}</h1>
                        <div class="text-muted">
                            <span v-if="data.reference_no">Reference No. <strong class="text-body">{{ data.reference_no }}</strong> · </span>Advt. No. {{ data.advertisement }}
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button v-if="data.submitted_at" type="button" class="btn btn-outline-secondary btn-sm" :disabled="!!downloading" @click="getReceipt">
                            <span v-if="downloading === 'receipt'" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                            <i v-else class="bi bi-receipt me-1" aria-hidden="true"></i>Acknowledgment receipt
                        </button>
                        <button v-if="versions.length" type="button" class="btn btn-outline-secondary btn-sm" :disabled="!!downloading" @click="getVersion(versions[0].version)">
                            <span v-if="downloading === `v${versions[0].version}`" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                            <i v-else class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Application PDF
                        </button>
                    </div>
                </div>

                <!-- Continue editing -->
                <div v-if="editable" class="alert d-flex flex-wrap align-items-center gap-3" :class="data.status === 'reopened' ? 'alert-warning' : 'alert-info'" role="status">
                    <i class="bi fs-4" :class="data.status === 'reopened' ? 'bi-arrow-counterclockwise' : 'bi-pencil-square'" aria-hidden="true"></i>
                    <div class="flex-grow-1">
                        <strong v-if="data.status === 'reopened'">Your application has been reopened for correction.</strong>
                        <strong v-else>This application has not been submitted yet.</strong>
                        <div class="small">
                            <template v-if="data.status === 'reopened'">Make the required corrections and submit it again. A new version will be recorded; earlier versions remain on file.</template>
                            <template v-else>Complete all steps and submit before the last date.</template>
                        </div>
                    </div>
                    <router-link :to="`/applications/${data.uuid}/edit`" class="btn btn-primary">
                        Continue application <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </router-link>
                </div>

                <div v-if="openClarifications.length" class="alert alert-warning" role="alert">
                    <i class="bi bi-chat-left-text me-1" aria-hidden="true"></i>
                    <strong>{{ openClarifications.length }} clarification request{{ openClarifications.length === 1 ? '' : 's' }}</strong> awaiting your response.
                    <a href="#clarifications" class="alert-link ms-1">Respond below</a>
                </div>

                <div class="row g-3">
                    <div class="col-lg-7 vstack gap-3">
                        <!-- Summary -->
                        <section class="card section-card">
                            <h2 class="card-header h6 mb-0">Application status</h2>
                            <div class="card-body">
                                <table class="table table-sm kv-table mb-0">
                                    <tbody>
                                        <tr><th scope="row">Current stage</th><td><StatusBadge :value="data.status" :label="statusLabels[data.status]" /> <span class="ms-1 fw-semibold">{{ data.stage }}</span></td></tr>
                                        <tr><th scope="row">Reference number</th><td>{{ data.reference_no ?? 'Issued on submission' }}</td></tr>
                                        <tr><th scope="row">Post</th><td>{{ data.post }}</td></tr>
                                        <tr><th scope="row">Advertisement</th><td>{{ data.advertisement }}</td></tr>
                                        <tr><th scope="row">Submitted on</th><td>{{ data.submitted_at ? formatDateTime(data.submitted_at) : 'Not submitted' }}</td></tr>
                                        <tr><th scope="row">Application fee</th><td><StatusBadge :value="data.fee_status" :label="feeLabels[data.fee_status]" /><div v-if="data.fee_remarks" class="small text-danger mt-1">{{ data.fee_remarks }}</div></td></tr>
                                        <tr><th scope="row">Employer endorsement</th><td><StatusBadge :value="data.endorsement_status" :label="endorsementLabels[data.endorsement_status]" /><div v-if="data.endorsement_remarks" class="small text-danger mt-1">{{ data.endorsement_remarks }}</div></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <!-- Referees -->
                        <section v-if="data.submitted_at" class="card section-card">
                            <h2 class="card-header h6 mb-0">Referee recommendations</h2>
                            <div v-if="!data.referees.length" class="card-body small text-muted">No referees are recorded for this application.</div>
                            <div v-else class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead class="table-light"><tr><th scope="col">#</th><th scope="col">Referee</th><th scope="col">Recommendation</th></tr></thead>
                                    <tbody>
                                        <tr v-for="r in data.referees" :key="r.position">
                                            <td>{{ r.position }}</td>
                                            <td>{{ r.name }}</td>
                                            <td>
                                                <span v-if="r.received" class="text-success"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>{{ r.status }}</span>
                                                <span v-else class="text-muted"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>{{ r.status }}</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="card-footer bg-white small text-muted">
                                Recommendations are confidential and go directly to the Institute. This table shows only whether each one has been received.
                            </div>
                        </section>

                        <!-- Clarifications -->
                        <section v-if="sortedClarifications.length" id="clarifications" aria-labelledby="clar-heading">
                            <h2 id="clar-heading" class="h5 page-title">Clarification requests</h2>
                            <div class="vstack gap-3">
                                <ClarificationCard v-for="c in sortedClarifications" :key="c.id" :uuid="data.uuid" :clarification="c" @responded="load" />
                            </div>
                        </section>

                        <!-- Withdraw -->
                        <section v-if="data.can_withdraw && data.submitted_at" class="card border-danger-subtle">
                            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <div class="small">
                                    <strong>Withdraw this application</strong>
                                    <div class="text-muted">If you no longer wish to be considered for this post you may withdraw. This cannot be undone.</div>
                                </div>
                                <button type="button" class="btn btn-outline-danger btn-sm" @click="showWithdraw = true">Withdraw…</button>
                            </div>
                        </section>
                    </div>

                    <div class="col-lg-5 vstack gap-3">
                        <!-- Versions -->
                        <section class="card section-card">
                            <h2 class="card-header h6 mb-0">Submitted versions</h2>
                            <div v-if="!versions.length" class="card-body small text-muted">No version has been submitted yet.</div>
                            <ul v-else class="list-group list-group-flush small">
                                <li v-for="v in versions" :key="v.version" class="list-group-item d-flex justify-content-between align-items-center gap-2">
                                    <div>
                                        <div class="fw-semibold">Version {{ v.version }} <span v-if="v.version === versions[0].version" class="badge text-bg-light border ms-1">latest</span></div>
                                        <div class="text-muted">{{ formatDateTime(v.created_at) }}<span v-if="v.reason"> · {{ v.reason }}</span></div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="!!downloading" :aria-label="`Download version ${v.version} PDF`" @click="getVersion(v.version)">
                                        <span v-if="downloading === `v${v.version}`" class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                        <i v-else class="bi bi-download" aria-hidden="true"></i> PDF
                                    </button>
                                </li>
                            </ul>
                        </section>

                        <!-- Timeline -->
                        <section class="card section-card">
                            <h2 class="card-header h6 mb-0">Status history</h2>
                            <div v-if="!timeline.length" class="card-body small text-muted">No status changes recorded yet.</div>
                            <ol v-else class="list-group list-group-flush small">
                                <li v-for="(h, i) in timeline" :key="i" class="list-group-item d-flex gap-2">
                                    <i class="bi mt-1" :class="historyIcon(h)" aria-hidden="true"></i>
                                    <div>
                                        <div class="fw-semibold">{{ historyLabel(h) }}</div>
                                        <div class="text-muted">{{ formatDateTime(h.at) }}</div>
                                        <div v-if="h.remarks" class="text-pre mt-1">{{ h.remarks }}</div>
                                    </div>
                                </li>
                            </ol>
                        </section>

                        <p v-if="data.submitted_at" class="small text-muted mb-0">
                            Submitted on {{ formatDate(data.submitted_at) }}. Keep your reference number for any correspondence with the Recruitment Section.
                        </p>
                    </div>
                </div>
            </template>
        </LoadingState>

        <WithdrawModal v-if="showWithdraw && data" :uuid="data.uuid" :reference-no="data.reference_no" :post="data.post" @close="showWithdraw = false" @withdrawn="onWithdrawn" />
    </div>
</template>
