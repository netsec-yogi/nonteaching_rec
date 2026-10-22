<script setup lang="ts">
/**
 * The referee's tokenised recommendation form (spec 7.3–7.5). Public, no
 * login, no portal navigation. Shows only the applicant's name, post and
 * advertisement number — never any other part of the application.
 *
 * Flow: (verify email if required) → details and assessment → review →
 * good-faith confirmation and submit → thank-you with reference number.
 * Unsaved input is kept in sessionStorage (keyed by a hash of the token,
 * never the raw token, never localStorage) and the browser warns on unload.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import type { AxiosError } from 'axios';
import { api, ensureCsrf, toApiError, type ValidationErrors } from '@/api/client';
import { formatDateTime } from '@/utils/format';
import RefereeQuestion, { type AnswerValue, type RefereeQuestionDef } from './parts/RefereeQuestion.vue';
import StatusCard from './parts/StatusCard.vue';

interface RefereeView {
    status: 'valid';
    institute: string; institute_address: string | null; contact_email: string | null;
    advertisement_number: string; post_name: string; applicant_name: string | null; deadline: string;
    prefill: Record<string, string | null>;
    form: { introduction: string | null; good_faith_statement: string | null; recommendation_options: string[]; questions: RefereeQuestionDef[] };
    requires_verification: boolean; verified: boolean;
}
type Phase = 'loading' | 'status' | 'verify' | 'form' | 'review' | 'done';

const props = defineProps<{ token: string }>();

const phase = ref<Phase>('loading');
const view = ref<RefereeView | null>(null);
const terminal = ref<{ status: string; message: string; contact?: string | null }>({ status: 'error', message: '' });
const done = ref<{ reference_no: string; submitted_at: string; message: string } | null>(null);
const banner = ref<string | null>(null);
const busy = ref(false);
const errors = reactive<ValidationErrors>({});
const restored = ref(false);
const dirty = ref(false);

const info = reactive({
    referee_name: '', referee_designation: '', referee_organization: '', referee_email: '', referee_phone: '',
    relationship: '', known_duration: '', nature_of_association: '',
});
const answers = reactive<Record<string, AnswerValue>>({});
const overall = ref('');
const comments = ref('');
const confirmGoodFaith = ref(false);

const infoFields: { key: keyof typeof info; label: string; required: boolean; type?: string; max: number; hint?: string; textarea?: boolean }[] = [
    { key: 'referee_name', label: 'Name', required: true, max: 200 },
    { key: 'referee_designation', label: 'Designation', required: true, max: 200 },
    { key: 'referee_organization', label: 'Organisation / Institution', required: true, max: 250 },
    { key: 'referee_email', label: 'Email address', required: true, type: 'email', max: 255 },
    { key: 'referee_phone', label: 'Telephone / Mobile', required: false, type: 'tel', max: 20 },
    { key: 'relationship', label: 'Relationship with the applicant', required: true, max: 300, hint: 'e.g. supervisor, head of office, teacher' },
    { key: 'known_duration', label: 'Period for which you have known the applicant', required: true, max: 100, hint: 'e.g. 6 years' },
    { key: 'nature_of_association', label: 'Nature of association', required: false, max: 2000, textarea: true },
];

// ------------------------------------------------------------ draft storage

/** FNV-1a hash of the token so the raw token is never written to storage. */
function hashToken(s: string): string {
    let h1 = 0x811c9dc5, h2 = 0x01000193;
    for (let i = 0; i < s.length; i++) {
        h1 = Math.imul(h1 ^ s.charCodeAt(i), 0x01000193) >>> 0;
        h2 = Math.imul(h2 ^ s.charCodeAt(s.length - 1 - i), 0x5bd1e995) >>> 0;
    }
    return h1.toString(16) + h2.toString(16);
}
const draftKey = computed(() => `referee-draft:${hashToken(props.token)}`);

function saveDraft() {
    try {
        sessionStorage.setItem(draftKey.value, JSON.stringify({ info, answers, overall: overall.value, comments: comments.value }));
    } catch { /* storage unavailable — the unload warning still protects the input */ }
}
function clearDraft() {
    try { sessionStorage.removeItem(draftKey.value); } catch { /* ignore */ }
}
function restoreDraft(): boolean {
    try {
        const raw = sessionStorage.getItem(draftKey.value);
        if (!raw) return false;
        const d = JSON.parse(raw) as { info?: Record<string, string>; answers?: Record<string, AnswerValue>; overall?: string; comments?: string };
        Object.keys(info).forEach((k) => { if (typeof d.info?.[k] === 'string') info[k as keyof typeof info] = d.info[k]; });
        Object.keys(answers).forEach((k) => { if (d.answers && k in d.answers) answers[k] = d.answers[k]; });
        if (d.overall && view.value?.form.recommendation_options.includes(d.overall)) overall.value = d.overall;
        if (typeof d.comments === 'string') comments.value = d.comments;
        return true;
    } catch {
        return false;
    }
}

let tracking = false;
let saveTimer: ReturnType<typeof setTimeout> | undefined;
watch([info, answers, overall, comments], () => {
    if (!tracking) return;
    dirty.value = true;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveDraft, 400);
}, { deep: true });

function onBeforeUnload(e: BeforeUnloadEvent) {
    if (dirty.value && (phase.value === 'form' || phase.value === 'review')) {
        e.preventDefault();
        e.returnValue = '';
    }
}
onMounted(() => window.addEventListener('beforeunload', onBeforeUnload));
onBeforeUnmount(() => { window.removeEventListener('beforeunload', onBeforeUnload); clearTimeout(saveTimer); });

// ------------------------------------------------------------ loading

function showTerminal(status: string, message: string) {
    terminal.value = { status, message, contact: view.value?.contact_email ?? null };
    phase.value = 'status';
    dirty.value = false;
    if (status === 'completed' || status === 'revoked' || status === 'expired') clearDraft();
}

async function load() {
    phase.value = 'loading';
    try {
        const { data } = await api.get<RefereeView>(`/referee/${encodeURIComponent(props.token)}`);
        view.value = data;
        tracking = false;
        const p = data.prefill ?? {};
        (Object.keys(info) as (keyof typeof info)[]).forEach((k) => { info[k] = p[k] ?? ''; });
        Object.keys(answers).forEach((k) => delete answers[k]);
        data.form.questions.forEach((q) => { answers[q.code] = null; });
        restored.value = restoreDraft();
        dirty.value = restored.value;
        await nextTick();
        tracking = true;
        phase.value = data.requires_verification && !data.verified ? 'verify' : 'form';
    } catch (e) {
        const err = e as AxiosError<{ status?: string; message?: string }>;
        const d = err.response?.data;
        if (d?.status && d.message) showTerminal(d.status, d.message);
        else showTerminal('error', toApiError(e).message);
    }
}
onMounted(load);
watch(() => props.token, load);

// ------------------------------------------------------------ email verification

const code = ref('');
const codeSent = ref(false);
const codeValid = computed(() => /^\d{6}$/.test(code.value.trim()));
const verifyMessage = ref<string | null>(null);

async function requestCode() {
    busy.value = true;
    verifyMessage.value = null;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        await ensureCsrf();
        const { data } = await api.post(`/referee/${encodeURIComponent(props.token)}/code`);
        codeSent.value = true;
        verifyMessage.value = data.message;
    } catch (e) {
        handleActionError(e);
    } finally {
        busy.value = false;
    }
}

async function verifyCode() {
    busy.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        await ensureCsrf();
        await api.post(`/referee/${encodeURIComponent(props.token)}/verify`, { code: code.value.trim() });
        await load();
    } catch (e) {
        handleActionError(e);
    } finally {
        busy.value = false;
    }
}

// ------------------------------------------------------------ validation and progress

const questions = computed(() => view.value?.form.questions ?? []);
const isBlank = (v: unknown) => v === null || v === undefined || (typeof v === 'string' && v.trim() === '');

function clientErrors(): ValidationErrors {
    const out: ValidationErrors = {};
    for (const f of infoFields) {
        const v = info[f.key].trim();
        if (f.required && !v) out[f.key] = [`Enter your ${f.label.toLowerCase()}.`];
        else if (v.length > f.max) out[f.key] = [`Must not exceed ${f.max} characters.`];
    }
    if (!out.referee_email && info.referee_email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(info.referee_email.trim())) out.referee_email = ['Enter a valid email address.'];
    for (const q of questions.value) if (q.is_required && isBlank(answers[q.code])) out[`answers.${q.code}`] = ['Please answer this question.'];
    if (!overall.value) out.overall_recommendation = ['Choose your overall recommendation.'];
    return out;
}

const requiredTotal = computed(() => infoFields.filter((f) => f.required).length + questions.value.filter((q) => q.is_required).length + 1);
const requiredDone = computed(() => infoFields.filter((f) => f.required && info[f.key].trim()).length
    + questions.value.filter((q) => q.is_required && !isBlank(answers[q.code])).length + (overall.value ? 1 : 0));
const progress = computed(() => (requiredTotal.value ? Math.round((requiredDone.value / requiredTotal.value) * 100) : 100));
const sectionAComplete = computed(() => infoFields.every((f) => !f.required || info[f.key].trim()));
const sectionBComplete = computed(() => questions.value.every((q) => !q.is_required || !isBlank(answers[q.code])) && !!overall.value);

function setErrors(map: ValidationErrors) {
    Object.keys(errors).forEach((k) => delete errors[k]);
    Object.assign(errors, map);
}

async function scrollToFirstError() {
    await nextTick();
    const first = document.querySelector('.is-invalid, .invalid-feedback.d-block');
    first?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function toReview() {
    const errs = clientErrors();
    setErrors(errs);
    banner.value = null;
    if (Object.keys(errs).length) {
        banner.value = 'Please complete the highlighted fields.';
        scrollToFirstError();
        return;
    }
    phase.value = 'review';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function backToForm() {
    phase.value = 'form';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ------------------------------------------------------------ submission

function handleActionError(e: unknown) {
    const err = e as AxiosError<{ status?: string; message?: string; errors?: ValidationErrors }>;
    const status = err.response?.status ?? 0;
    const data = err.response?.data;
    const ae = toApiError(e);
    if ((status === 409 || status === 410 || status === 404) && data?.status && data.message) {
        showTerminal(data.status, data.message);
        return;
    }
    if (status === 410) { showTerminal('expired', ae.message); return; }
    if (status === 403) {
        banner.value = ae.message || 'Verify your email address before submitting.';
        phase.value = 'verify';
        return;
    }
    if (status === 422) {
        setErrors(ae.errors);
        if (ae.errors.form) {
            showTerminal('completed', ae.errors.form[0]);
            return;
        }
        const onlyConfirm = Object.keys(ae.errors).every((k) => k === 'confirm_good_faith' || k === 'code');
        banner.value = onlyConfirm ? null : 'Some answers need attention. Please correct the highlighted fields and review again.';
        if (!onlyConfirm && phase.value === 'review') { phase.value = 'form'; }
        scrollToFirstError();
        return;
    }
    banner.value = status === 429 ? 'Too many attempts. Please wait a minute and try again — your answers are kept on this page.' : ae.message;
}

async function submit() {
    if (!confirmGoodFaith.value) {
        setErrors({ confirm_good_faith: ['Please confirm that the information is accurate and submitted in good faith.'] });
        return;
    }
    busy.value = true;
    banner.value = null;
    try {
        await ensureCsrf();
        const payload = {
            referee_name: info.referee_name.trim(),
            referee_designation: info.referee_designation.trim(),
            referee_organization: info.referee_organization.trim(),
            referee_email: info.referee_email.trim(),
            referee_phone: info.referee_phone.trim() || null,
            relationship: info.relationship.trim(),
            known_duration: info.known_duration.trim(),
            nature_of_association: info.nature_of_association.trim() || null,
            answers: Object.fromEntries(questions.value.map((q) => {
                const v = answers[q.code];
                return [q.code, typeof v === 'string' ? (v.trim() || null) : v];
            })),
            overall_recommendation: overall.value,
            comments: comments.value.trim() || null,
            confirm_good_faith: true,
        };
        const { data } = await api.post(`/referee/${encodeURIComponent(props.token)}/submit`, payload);
        done.value = data;
        dirty.value = false;
        clearDraft();
        phase.value = 'done';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
        handleActionError(e);
    } finally {
        busy.value = false;
    }
}

// ------------------------------------------------------------ display helpers

function answerText(q: RefereeQuestionDef): string {
    const v = answers[q.code];
    if (isBlank(v)) return '—';
    if (q.type === 'yes_no') return v === 'yes' ? 'Yes' : 'No';
    if (q.type === 'rating') {
        const lbl = q.options?.labels?.[String(v)];
        return `${v} of ${q.options?.max ?? 5}${lbl ? ' — ' + lbl : ''}`;
    }
    return String(v);
}

const daysLeft = computed(() => {
    if (!view.value) return null;
    const ms = new Date(view.value.deadline).getTime() - Date.now();
    return Number.isNaN(ms) ? null : Math.max(0, Math.ceil(ms / 86_400_000));
});
const steps = [
    { key: 'form', label: 'Details and assessment' },
    { key: 'review', label: 'Review' },
    { key: 'done', label: 'Submitted' },
];
const stepIndex = computed(() => Math.max(0, steps.findIndex((s) => s.key === phase.value)));
</script>

<template>
    <div class="referee-form">
        <div v-if="phase === 'loading'" class="text-center text-muted py-5">
            <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading the recommendation form…
        </div>

        <StatusCard v-else-if="phase === 'status'" :status="terminal.status" :message="terminal.message" :contact-email="terminal.contact" />

        <StatusCard v-else-if="phase === 'done' && done" status="completed" :message="done.message" :reference="done.reference_no"
            :submitted-at="`Submitted on ${formatDateTime(done.submitted_at)}`" :contact-email="view?.contact_email">
            <p class="small text-muted mt-3 mb-0">Please quote the reference number in any correspondence. You may now close this window.</p>
        </StatusCard>

        <template v-else-if="view">
            <!-- Header: who and what -->
            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <h1 class="h4 page-title mb-1">Confidential Referee Recommendation</h1>
                    <p class="small text-muted mb-3">{{ view.institute }}<span v-if="view.institute_address">, {{ view.institute_address }}</span></p>
                    <table class="table table-sm kv-table mb-3">
                        <tbody>
                            <tr><th>Applicant</th><td class="fw-semibold">{{ view.applicant_name ?? '—' }}</td></tr>
                            <tr><th>Post applied for</th><td>{{ view.post_name }}</td></tr>
                            <tr><th>Advertisement No.</th><td>{{ view.advertisement_number }}</td></tr>
                            <tr>
                                <th>Please submit by</th>
                                <td><strong>{{ formatDateTime(view.deadline) }}</strong>
                                    <span v-if="daysLeft !== null" class="badge ms-1" :class="daysLeft <= 2 ? 'text-bg-warning' : 'text-bg-light border'">{{ daysLeft === 0 ? 'today' : `${daysLeft} day${daysLeft === 1 ? '' : 's'} left` }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="confidential-banner rounded p-3 small">
                        <div class="fw-semibold mb-1"><i class="bi bi-shield-lock me-1"></i>Confidential</div>
                        <p v-if="view.form.introduction" class="mb-1">{{ view.form.introduction }}</p>
                        <p class="mb-0">Your recommendation will be used only for this recruitment and will not be shared with the applicant.</p>
                    </div>
                </div>
            </div>

            <!-- Email verification -->
            <div v-if="phase === 'verify'" class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h5">Confirm your email address</h2>
                    <p class="small">Before you can submit, please confirm that you have access to the email address this invitation was sent to. We will send a 6-digit code to that address.</p>
                    <div v-if="banner" class="alert alert-warning small py-2">{{ banner }}</div>
                    <div v-if="verifyMessage" class="alert alert-info small py-2">{{ verifyMessage }}</div>
                    <button v-if="!codeSent" type="button" class="btn btn-primary" :disabled="busy" @click="requestCode">
                        <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>Send verification code
                    </button>
                    <form v-else class="row g-2 align-items-end" @submit.prevent="verifyCode">
                        <div class="col-sm-5">
                            <label class="form-label small" for="ref-code">Verification code</label>
                            <input id="ref-code" v-model="code" class="form-control font-monospace" :class="{ 'is-invalid': errors.code }" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" placeholder="000000">
                            <div v-if="errors.code" class="invalid-feedback">{{ errors.code[0] }}</div>
                        </div>
                        <div class="col-sm-7 d-flex gap-2">
                            <button type="submit" class="btn btn-primary" :disabled="busy || !codeValid">Verify</button>
                            <button type="button" class="btn btn-link" :disabled="busy" @click="requestCode">Send a new code</button>
                        </div>
                    </form>
                </div>
            </div>

            <template v-else>
                <!-- Steps and progress -->
                <ol class="list-unstyled d-flex gap-3 small mb-2" aria-label="Progress">
                    <li v-for="(s, i) in steps" :key="s.key" :class="i === stepIndex ? 'fw-semibold text-primary' : i < stepIndex ? 'text-success' : 'text-muted'" :aria-current="i === stepIndex ? 'step' : undefined">
                        <i class="bi me-1" :class="i < stepIndex ? 'bi-check-circle-fill' : i === stepIndex ? 'bi-record-circle' : 'bi-circle'"></i>{{ i + 1 }}. {{ s.label }}
                    </li>
                </ol>
                <div v-if="phase === 'form'" class="mb-3">
                    <div class="progress" style="height: 6px" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" aria-label="Required fields completed">
                        <div class="progress-bar" :class="progress === 100 ? 'bg-success' : ''" :style="{ width: progress + '%' }"></div>
                    </div>
                    <div class="small text-muted mt-1">{{ requiredDone }} of {{ requiredTotal }} required answers completed</div>
                </div>

                <div v-if="restored && phase === 'form'" class="alert alert-info small py-2 d-flex align-items-center">
                    <span class="flex-grow-1"><i class="bi bi-arrow-counterclockwise me-1"></i>Your unsaved answers from this browser session have been restored.</span>
                    <button type="button" class="btn-close" aria-label="Dismiss" @click="restored = false"></button>
                </div>
                <div v-if="banner" class="alert alert-warning small py-2" role="alert">{{ banner }}</div>

                <!-- Form -->
                <form v-if="phase === 'form'" novalidate @submit.prevent="toReview">
                    <div class="card shadow-sm mb-3">
                        <div class="card-header bg-white d-flex align-items-center">
                            <span class="fw-semibold flex-grow-1">Section A · Referee information</span>
                            <i v-if="sectionAComplete" class="bi bi-check-circle-fill text-success" title="Complete"></i>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted">These details were provided by the applicant. Please correct them if necessary.</p>
                            <div class="row g-3">
                                <div v-for="f in infoFields" :key="f.key" :class="f.textarea ? 'col-12' : 'col-md-6'">
                                    <label class="form-label small" :class="{ required: f.required }" :for="`ref-${f.key}`">{{ f.label }}</label>
                                    <textarea v-if="f.textarea" :id="`ref-${f.key}`" v-model="info[f.key]" class="form-control" :class="{ 'is-invalid': errors[f.key] }" rows="3" :maxlength="f.max"></textarea>
                                    <input v-else :id="`ref-${f.key}`" v-model="info[f.key]" :type="f.type ?? 'text'" class="form-control" :class="{ 'is-invalid': errors[f.key] }" :maxlength="f.max"
                                        :autocomplete="f.key === 'referee_email' ? 'email' : f.key === 'referee_name' ? 'name' : f.key === 'referee_phone' ? 'tel' : 'off'">
                                    <div v-if="f.hint && !errors[f.key]" class="form-text">{{ f.hint }}</div>
                                    <div v-if="errors[f.key]" class="invalid-feedback">{{ errors[f.key][0] }}</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm mb-3">
                        <div class="card-header bg-white d-flex align-items-center">
                            <span class="fw-semibold flex-grow-1">Section B · Assessment of the applicant</span>
                            <i v-if="sectionBComplete" class="bi bi-check-circle-fill text-success" title="Complete"></i>
                        </div>
                        <div class="card-body pt-0">
                            <RefereeQuestion v-for="(q, i) in questions" :key="q.code" v-model="answers[q.code]" :question="q" :number="i + 1" :error="errors[`answers.${q.code}`]?.[0]" />

                            <div id="q-overall-wrap" class="py-3 border-bottom" role="radiogroup" aria-labelledby="q-overall-label">
                                <div id="q-overall-label" class="fw-semibold mb-2">Overall recommendation <span class="text-danger" aria-hidden="true">*</span></div>
                                <div class="vstack gap-1">
                                    <div v-for="(o, i) in view.form.recommendation_options" :key="o" class="form-check">
                                        <input :id="`ref-overall-${i}`" v-model="overall" class="form-check-input" :class="{ 'is-invalid': errors.overall_recommendation }" type="radio" name="overall" :value="o">
                                        <label class="form-check-label" :for="`ref-overall-${i}`">{{ o }}</label>
                                    </div>
                                </div>
                                <div v-if="errors.overall_recommendation" class="invalid-feedback d-block">{{ errors.overall_recommendation[0] }}</div>
                            </div>

                            <div class="pt-3">
                                <label class="form-label fw-semibold" for="ref-comments">Additional comments <span class="fw-normal text-muted small">(optional)</span></label>
                                <textarea id="ref-comments" v-model="comments" class="form-control" :class="{ 'is-invalid': errors.comments }" rows="5" maxlength="10000"></textarea>
                                <div class="form-text text-end">{{ comments.length }} / 10000</div>
                                <div v-if="errors.comments" class="invalid-feedback d-block">{{ errors.comments[0] }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="sticky-actions">
                        <div class="d-flex align-items-center gap-2">
                            <span class="small text-muted flex-grow-1"><i class="bi bi-info-circle me-1"></i>Nothing is sent until you confirm on the next screen.</span>
                            <button type="submit" class="btn btn-primary">Review my answers <i class="bi bi-arrow-right ms-1"></i></button>
                        </div>
                    </div>
                </form>

                <!-- Review and submit -->
                <div v-else-if="phase === 'review'">
                    <div class="card shadow-sm mb-3">
                        <div class="card-header bg-white d-flex align-items-center">
                            <span class="fw-semibold flex-grow-1">Section A · Referee information</span>
                            <button type="button" class="btn btn-sm btn-link" @click="backToForm">Edit</button>
                        </div>
                        <table class="table table-sm kv-table mb-0">
                            <tbody>
                                <tr v-for="f in infoFields" :key="f.key"><th class="ps-3">{{ f.label }}</th><td class="text-pre">{{ info[f.key] || '—' }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="card shadow-sm mb-3">
                        <div class="card-header bg-white d-flex align-items-center">
                            <span class="fw-semibold flex-grow-1">Section B · Assessment</span>
                            <button type="button" class="btn btn-sm btn-link" @click="backToForm">Edit</button>
                        </div>
                        <table class="table table-sm mb-0">
                            <tbody>
                                <tr v-for="(q, i) in questions" :key="q.code">
                                    <td class="ps-3 small" style="width: 60%">{{ i + 1 }}. {{ q.label }}</td>
                                    <td class="fw-semibold text-pre">{{ answerText(q) }}</td>
                                </tr>
                                <tr class="table-light"><td class="ps-3 fw-semibold">Overall recommendation</td><td class="fw-bold">{{ overall }}</td></tr>
                                <tr><td class="ps-3 small">Additional comments</td><td class="text-pre small">{{ comments || '—' }}</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="card shadow-sm border-primary mb-3">
                        <div class="card-body">
                            <div class="form-check">
                                <input id="ref-confirm" v-model="confirmGoodFaith" class="form-check-input" :class="{ 'is-invalid': errors.confirm_good_faith }" type="checkbox">
                                <label class="form-check-label" for="ref-confirm">{{ view.form.good_faith_statement || 'I confirm that the information given above is accurate to the best of my knowledge and is submitted in good faith.' }}</label>
                                <div v-if="errors.confirm_good_faith" class="invalid-feedback d-block">{{ errors.confirm_good_faith[0] }}</div>
                            </div>
                            <p class="small text-muted mt-2 mb-0">Once submitted, the recommendation cannot be changed.</p>
                        </div>
                    </div>

                    <div class="d-flex gap-2 justify-content-between">
                        <button type="button" class="btn btn-outline-secondary" :disabled="busy" @click="backToForm"><i class="bi bi-arrow-left me-1"></i>Back</button>
                        <button type="button" class="btn btn-primary" :disabled="busy || !confirmGoodFaith" @click="submit">
                            <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span><i v-else class="bi bi-send me-1"></i>Submit recommendation
                        </button>
                    </div>
                </div>

                <p v-if="view.contact_email" class="small text-muted text-center mt-4 mb-0">
                    Questions about this request? Write to <a :href="`mailto:${view.contact_email}`">{{ view.contact_email }}</a>.
                </p>
            </template>
        </template>
    </div>
</template>
