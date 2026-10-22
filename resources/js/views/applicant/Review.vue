<script setup lang="ts">
/**
 * Preview of the complete application with the remaining blocking issues,
 * the draft PDF, and final submission behind an explicit confirmation.
 */
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { api, openInline, toApiError } from '@/api/client';
import BaseModal from '@/components/BaseModal.vue';
import LoadingState from '@/components/LoadingState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ApplicationSnapshot from '@/components/staff/ApplicationSnapshot.vue';
import type { Snapshot } from '@/components/staff/types';
import { useToast } from '@/stores/toast';
import { formatDateTime } from '@/utils/format';
import type { ApplicationPayload, StepInfo } from './wizard/types';

const props = defineProps<{ uuid: string }>();
const router = useRouter();
const toast = useToast();

const payload = ref<ApplicationPayload | null>(null);
const snapshot = ref<Snapshot | null>(null);
const issues = ref<Record<string, string[]>>({});
const loading = ref(true);
const loadError = ref<string | null>(null);

const showConfirm = ref(false);
const confirmed = ref(false);
const submitting = ref(false);
const submitErrors = ref<string[]>([]);
const resending = ref(false);
const pdfBusy = ref(false);

const app = computed(() => payload.value?.application ?? null);
const blocking = computed(() => (payload.value?.steps ?? [])
    .map((s: StepInfo) => ({ step: s, issues: issues.value[s.key] ?? [] }))
    .filter((x) => x.issues.length));
const issueCount = computed(() => blocking.value.reduce((n, b) => n + b.issues.length, 0));
const isResubmission = computed(() => app.value?.status === 'reopened');
const closed = computed(() => !!app.value && !isResubmission.value && !app.value.advertisement.is_open);
const canSubmit = computed(() => !!app.value?.editable && issueCount.value === 0 && !!app.value.email_verified && !closed.value);
const deadline = computed(() => (isResubmission.value && app.value?.reopened_until) || app.value?.advertisement.closes_at || null);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const [p, pre] = await Promise.all([
            api.get<ApplicationPayload>(`/applications/${props.uuid}`),
            api.get<{ snapshot: Snapshot; issues: Record<string, string[]> }>(`/applications/${props.uuid}/preview`),
        ]);
        payload.value = p.data;
        snapshot.value = pre.data.snapshot;
        issues.value = pre.data.issues;
    } catch (e) {
        const ae = toApiError(e);
        loadError.value = ae.status === 404 ? 'Application not found.' : ae.message;
    } finally {
        loading.value = false;
    }
}
watch(() => props.uuid, load, { immediate: true });

async function openPdf() {
    pdfBusy.value = true;
    try {
        await openInline(`/applications/${props.uuid}/preview.pdf`);
    } catch (e) {
        toast.error(toApiError(e).message);
    } finally {
        pdfBusy.value = false;
    }
}

async function resend() {
    resending.value = true;
    try {
        const { data } = await api.post<{ message?: string }>('/auth/email/resend');
        toast.success(data?.message ?? 'A new verification link has been sent to your email address.');
    } catch (e) {
        toast.error(toApiError(e).message);
    } finally {
        resending.value = false;
    }
}

function openConfirm() {
    confirmed.value = false;
    submitErrors.value = [];
    showConfirm.value = true;
}

async function submit() {
    if (!confirmed.value) return;
    submitting.value = true;
    submitErrors.value = [];
    try {
        const { data } = await api.post<{ reference_no: string; submitted_at: string; version: number }>(`/applications/${props.uuid}/submit`, { confirm: true });
        toast.success(`Application submitted. Your reference number is ${data.reference_no}.`);
        showConfirm.value = false;
        router.push(`/applications/${props.uuid}`);
    } catch (e) {
        const ae = toApiError(e);
        const errs = [...(ae.errors.application ?? []), ...(ae.errors.email ?? []), ...(ae.errors.confirm ?? [])];
        submitErrors.value = errs.length ? errs : [ae.message];
        if (ae.errors.application) load();
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <LoadingState :loading="loading" :error="loadError">
        <div v-if="app && payload && snapshot">
            <nav aria-label="breadcrumb" class="small">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><router-link to="/dashboard">My applications</router-link></li>
                    <li v-if="app.editable" class="breadcrumb-item"><router-link :to="`/applications/${uuid}/edit`">Application form</router-link></li>
                    <li class="breadcrumb-item active" aria-current="page">Preview &amp; submit</li>
                </ol>
            </nav>
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                <div>
                    <h1 class="page-title h3 mb-1">{{ app.editable ? 'Preview and submit' : 'Application preview' }}</h1>
                    <div class="text-muted small">{{ app.post.name }} ({{ app.post.code }}) · Advt. No. {{ app.advertisement.number }}</div>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <StatusBadge :value="app.status" />
                    <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="pdfBusy" @click="openPdf">
                        <span v-if="pdfBusy" class="spinner-border spinner-border-sm me-1"></span><i v-else class="bi bi-file-earmark-pdf me-1"></i>Draft PDF
                    </button>
                    <router-link v-if="app.editable" :to="`/applications/${uuid}/edit`" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Edit</router-link>
                </div>
            </div>

            <div v-if="!app.editable" class="alert alert-secondary d-flex flex-wrap align-items-center gap-2">
                <i class="bi bi-lock-fill"></i>
                <span class="flex-grow-1">This application has already been submitted and can no longer be edited.</span>
                <router-link :to="`/applications/${uuid}`" class="btn btn-sm btn-outline-dark">View status</router-link>
            </div>

            <template v-else>
                <div class="card section-card mb-3">
                    <div class="card-body">
                        <div class="row g-3 align-items-center">
                            <div class="col-md-7">
                                <div class="small text-muted">{{ isResubmission ? 'Resubmit corrections by' : 'Last date for submission' }}</div>
                                <div class="fs-5 fw-semibold" :class="{ 'text-danger': closed }">{{ formatDateTime(deadline) }}</div>
                                <div v-if="closed" class="small text-danger">The last date has passed; this application can no longer be submitted.</div>
                                <div v-else class="small text-muted">After final submission the application is locked and a reference number is issued. Your referees are then emailed.</div>
                            </div>
                            <div class="col-md-5 text-md-end">
                                <button type="button" class="btn btn-primary btn-lg" :disabled="!canSubmit" @click="openConfirm">
                                    <i class="bi bi-send-check me-1"></i>{{ isResubmission ? 'Resubmit application' : 'Submit application' }}
                                </button>
                                <div v-if="!canSubmit && !closed" class="small text-muted mt-1">
                                    <template v-if="issueCount">Complete the {{ issueCount }} item(s) below to enable submission.</template>
                                    <template v-else-if="!app.email_verified">Verify your email address to enable submission.</template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="!app.email_verified" class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
                    <i class="bi bi-envelope-exclamation"></i>
                    <span class="flex-grow-1">Your email address is not verified. You must verify it before you can submit. Check your inbox for the verification link.</span>
                    <button type="button" class="btn btn-sm btn-warning" :disabled="resending" @click="resend">
                        <span v-if="resending" class="spinner-border spinner-border-sm me-1"></span>Resend verification email
                    </button>
                </div>

                <div v-if="blocking.length" class="card border-warning mb-3">
                    <div class="card-header bg-warning-subtle fw-semibold"><i class="bi bi-exclamation-triangle me-1"></i>Items to complete before submission ({{ issueCount }})</div>
                    <ul class="list-group list-group-flush">
                        <li v-for="b in blocking" :key="b.step.key" class="list-group-item">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <div>
                                    <div class="fw-semibold">{{ b.step.form_reference }} · {{ b.step.title }}</div>
                                    <ul class="small mb-0 ps-3"><li v-for="(i, n) in b.issues" :key="n">{{ i }}</li></ul>
                                </div>
                                <router-link :to="`/applications/${uuid}/edit/${b.step.key}`" class="btn btn-sm btn-outline-primary align-self-start">Fix <i class="bi bi-chevron-right"></i></router-link>
                            </div>
                        </li>
                    </ul>
                </div>
                <div v-else class="alert alert-success"><i class="bi bi-check-circle me-1"></i>All sections are complete. Check the preview below carefully before submitting.</div>
            </template>

            <ApplicationSnapshot :snapshot="snapshot" />

            <BaseModal v-if="showConfirm" :title="isResubmission ? 'Confirm resubmission' : 'Confirm final submission'" @close="showConfirm = false">
                <p>You are about to submit your application for <strong>{{ app.post.name }}</strong> under Advt. No. {{ app.advertisement.number }}.</p>
                <ul class="small">
                    <li>After submission you <strong>cannot edit</strong> the application unless the Institute reopens it for correction.</li>
                    <li>Your {{ payload.data.referees.required_count }} referees will be emailed a secure link to submit their confidential recommendation.</li>
                    <li>Payment details, where entered, will be verified by the Recruitment Section.</li>
                </ul>
                <div class="form-check">
                    <input id="confirm-submit" v-model="confirmed" class="form-check-input" type="checkbox">
                    <label class="form-check-label" for="confirm-submit">
                        I have reviewed the application and confirm that the information is complete and correct. I wish to submit it now.
                    </label>
                </div>
                <div v-if="submitErrors.length" class="alert alert-danger small mt-3 mb-0" role="alert">
                    <ul class="mb-0 ps-3"><li v-for="(m, i) in submitErrors" :key="i">{{ m }}</li></ul>
                </div>
                <template #footer>
                    <button type="button" class="btn btn-outline-secondary" @click="showConfirm = false">Cancel</button>
                    <button type="button" class="btn btn-primary" :disabled="!confirmed || submitting" @click="submit">
                        <span v-if="submitting" class="spinner-border spinner-border-sm me-1"></span>{{ isResubmission ? 'Resubmit' : 'Submit application' }}
                    </button>
                </template>
            </BaseModal>
        </div>
    </LoadingState>
</template>
