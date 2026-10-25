<script setup lang="ts">
/**
 * Multi-step application wizard: one step per group of numbered sections of
 * the paper form (SectionRegistry order). Each step is saved separately with
 * PUT /applications/{uuid}/sections/{key}; the response is the refreshed
 * payload, so step issues and requirements stay in sync with the server.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, provide, reactive, ref, watch, type Component, type Ref } from 'vue';
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRouter, type RouteLocationNormalized } from 'vue-router';
import { api, toApiError, type ValidationErrors } from '@/api/client';
import LoadingState from '@/components/LoadingState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { useToast } from '@/stores/toast';
import { formatDate, formatDateTime } from '@/utils/format';
import { clone, wizardKey } from './wizard/context';
import type { ApplicationPayload, DocumentSummary, StepDataMap, StepInfo, StepKey } from './wizard/types';
import PaymentStep from './wizard/steps/PaymentStep.vue';
import PostStep from './wizard/steps/PostStep.vue';
import PersonalStep from './wizard/steps/PersonalStep.vue';
import ExServicemanStep from './wizard/steps/ExServicemanStep.vue';
import QualificationsStep from './wizard/steps/QualificationsStep.vue';
import EmploymentStep from './wizard/steps/EmploymentStep.vue';
import CriminalStep from './wizard/steps/CriminalStep.vue';
import MeritStep from './wizard/steps/MeritStep.vue';
import TrainingStep from './wizard/steps/TrainingStep.vue';
import MembershipsStep from './wizard/steps/MembershipsStep.vue';
import SuitabilityStep from './wizard/steps/SuitabilityStep.vue';
import RefereesStep from './wizard/steps/RefereesStep.vue';
import DeclarationsStep from './wizard/steps/DeclarationsStep.vue';
import AchievementsStep from './wizard/steps/AchievementsStep.vue';
import CandidateDeclarationStep from './wizard/steps/CandidateDeclarationStep.vue';
import EndorsementStep from './wizard/steps/EndorsementStep.vue';
import ChecklistStep from './wizard/steps/ChecklistStep.vue';

const props = defineProps<{ uuid: string; step?: string }>();
const router = useRouter();
const toast = useToast();

const COMPONENTS: Record<StepKey, Component> = {
    payment: PaymentStep, post: PostStep, personal: PersonalStep, ex_serviceman: ExServicemanStep,
    qualifications: QualificationsStep, employment: EmploymentStep, criminal: CriminalStep, merit: MeritStep,
    training: TrainingStep, memberships: MembershipsStep, suitability: SuitabilityStep, referees: RefereesStep,
    declarations: DeclarationsStep, achievements: AchievementsStep, declaration: CandidateDeclarationStep,
    endorsement: EndorsementStep, checklist: ChecklistStep,
};

const payload = ref<ApplicationPayload | null>(null);
const drafts = ref<Partial<StepDataMap>>({});
const pristine: Partial<Record<StepKey, string>> = {};
const loading = ref(true);
const loadError = ref<string | null>(null);
const errors = reactive<ValidationErrors>({});
const saving = ref(false);
const message = ref<string | null>(null);

const app = computed(() => payload.value?.application ?? null);
const steps = computed<StepInfo[]>(() => payload.value?.steps ?? []);
const isStep = (key?: string | null): key is StepKey => !!key && steps.value.some((s) => s.key === key);
const currentKey = computed<StepKey>(() => {
    if (isStep(props.step)) return props.step;
    if (isStep(app.value?.current_step)) return app.value!.current_step as StepKey;
    return steps.value[0]?.key ?? 'payment';
});
const currentIndex = computed(() => steps.value.findIndex((s) => s.key === currentKey.value));
const current = computed(() => steps.value[currentIndex.value]);
const nextStep = computed(() => steps.value[currentIndex.value + 1] ?? null);
const prevStep = computed(() => steps.value[currentIndex.value - 1] ?? null);

function stepEditable(key: string): boolean {
    const a = app.value;
    if (!a?.editable) return false;
    return !a.editable_sections || a.editable_sections.includes(key);
}
const readonly = computed(() => !stepEditable(currentKey.value));

const applicable = computed(() => steps.value.filter((s) => s.applicable));
const doneCount = computed(() => applicable.value.filter((s) => !s.issues.length && (s.visited || s.key === 'post')).length);
const progress = computed(() => (applicable.value.length ? Math.round((doneCount.value / applicable.value.length) * 100) : 0));
const issueCount = computed(() => steps.value.reduce((n, s) => n + s.issues.length, 0));

/* ---------- Draft state ---------- */

function normalise<K extends StepKey>(key: K, data: StepDataMap[K], p: ApplicationPayload): StepDataMap[K] {
    const copy = clone(data);
    if (key === 'declarations') {
        const d = copy as StepDataMap['declarations'];
        for (const code of Object.keys(p.options.declaration_questions)) d[code] ??= { answer: null, details: null };
    }
    return copy;
}

function isDirty(key: StepKey): boolean {
    if (!stepEditable(key) || drafts.value[key] === undefined) return false;
    return JSON.stringify(drafts.value[key]) !== pristine[key];
}
const dirty = computed(() => {
    // Touch the draft so the computed tracks deep changes.
    void JSON.stringify(drafts.value[currentKey.value]);
    return isDirty(currentKey.value);
});

/** Take a fresh payload; keep unsaved edits on other steps unless told to replace them. */
function adopt(p: ApplicationPayload, replace: StepKey[] | 'all') {
    payload.value = p;
    const next: Partial<StepDataMap> = { ...drafts.value };
    for (const s of p.steps) {
        const key = s.key;
        if (replace !== 'all' && !replace.includes(key) && isDirty(key)) continue;
        (next as Record<string, unknown>)[key] = normalise(key, p.data[key], p);
        pristine[key] = JSON.stringify(next[key]);
    }
    drafts.value = next;
}

function discard(key: StepKey) {
    if (!payload.value) return;
    (drafts.value as Record<string, unknown>)[key] = normalise(key, payload.value.data[key], payload.value);
    pristine[key] = JSON.stringify(drafts.value[key]);
}

function clearErrors() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    message.value = null;
}

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await api.get<ApplicationPayload>(`/applications/${props.uuid}`);
        adopt(data, 'all');
        if (!isStep(props.step)) router.replace(`/applications/${props.uuid}/edit/${currentKey.value}`);
    } catch (e) {
        const ae = toApiError(e);
        loadError.value = ae.status === 404 ? 'Application not found.' : ae.message;
    } finally {
        loading.value = false;
    }
}

/** Re-read the payload after an upload so requirements and issues update. */
async function refresh() {
    try {
        const { data } = await api.get<ApplicationPayload>(`/applications/${props.uuid}`);
        adopt(data, []);
    } catch { /* keep current state; the next save refreshes it */ }
}

function setDocuments(docs: DocumentSummary[]) {
    if (payload.value) payload.value.documents = docs;
    refresh();
}

provide(wizardKey, {
    uuid: props.uuid,
    // Steps render only once the payload has loaded.
    payload: payload as Ref<ApplicationPayload>,
    errors,
    readonly,
    setDocuments,
});

/* ---------- Saving ---------- */

function body(key: StepKey): unknown {
    if (key === 'payment' && Number(app.value?.fee.amount ?? 0) <= 0) return {};
    return drafts.value[key];
}

async function save(): Promise<boolean> {
    const key = currentKey.value;
    if (readonly.value) return true;
    clearErrors();
    saving.value = true;
    try {
        const { data } = await api.put<ApplicationPayload>(`/applications/${props.uuid}/sections/${key}`, body(key));
        adopt(data, [key]);
        const remaining = data.steps.find((s) => s.key === key)?.issues ?? [];
        toast.show(remaining.length ? 'Saved. Some items on this step still need attention.' : 'Saved.', remaining.length ? 'warning' : 'success');
        return true;
    } catch (e) {
        const ae = toApiError(e);
        Object.assign(errors, ae.errors);
        if (ae.status === 422) {
            message.value = 'Please correct the highlighted fields. Nothing on this step has been saved yet.';
            await nextTick();
            document.querySelector('.wizard-body .is-invalid, .wizard-body .invalid-feedback')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            message.value = ae.message;
            toast.error(ae.message);
        }
        return false;
    } finally {
        saving.value = false;
    }
}

async function saveAndContinue() {
    if (!(await save())) return;
    go(nextStep.value ? `/applications/${props.uuid}/edit/${nextStep.value.key}` : `/applications/${props.uuid}/review`);
}

function go(path: string) {
    router.push(path);
}

const errorSummary = computed(() => [...new Set(Object.values(errors).flat())]);

/* ---------- Leaving with unsaved changes ---------- */

function confirmLeave(): boolean {
    if (!dirty.value) return true;
    if (!confirm('You have unsaved changes on this step. Leave without saving them?')) return false;
    discard(currentKey.value);
    return true;
}

onBeforeRouteUpdate((to: RouteLocationNormalized) => {
    if (to.params.uuid !== props.uuid) return confirmLeave();
    if (!confirmLeave()) return false;
    clearErrors();
    return true;
});
onBeforeRouteLeave(() => confirmLeave());

function beforeUnload(e: BeforeUnloadEvent) {
    if (dirty.value) {
        e.preventDefault();
        e.returnValue = '';
    }
}
onMounted(() => window.addEventListener('beforeunload', beforeUnload));
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload));

watch(() => props.uuid, load, { immediate: true });

/* ---------- Presentation ---------- */

function stepIcon(s: StepInfo): string {
    if (!s.applicable) return 'bi-dash-circle';
    if (s.issues.length) return 'bi-exclamation-triangle-fill text-warning';
    if (s.visited || s.key === 'post') return 'bi-check-circle-fill text-success';
    return 'bi-circle';
}
const stepRef = (s: StepInfo) => (/^\d/.test(s.form_reference) ? `Section${/[–-]/.test(s.form_reference) ? 's' : ''} ${s.form_reference}` : s.form_reference);
/** Mobile step picker; re-syncs the select if navigation is cancelled at the unsaved-changes prompt. */
async function onMobileStep(e: Event) {
    const el = e.target as HTMLSelectElement;
    await router.push(`/applications/${props.uuid}/edit/${el.value}`);
    await nextTick();
    el.value = currentKey.value;
}
</script>

<template>
    <LoadingState :loading="loading" :error="loadError">
        <div v-if="payload && app" class="pb-2">
            <nav aria-label="breadcrumb" class="small">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><router-link to="/dashboard">My applications</router-link></li>
                    <li class="breadcrumb-item active" aria-current="page">Application form</li>
                </ol>
            </nav>
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                <div>
                    <h1 class="page-title h3 mb-1">Application for {{ app.post.name }}</h1>
                    <div class="text-muted small">
                        Advt. No. {{ app.advertisement.number }} · Post code {{ app.post.code }}
                        <span v-if="app.reference_no"> · Ref. {{ app.reference_no }}</span>
                        · Last date: <strong>{{ formatDateTime(app.advertisement.closes_at) }}</strong>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <StatusBadge :value="app.status" />
                    <router-link :to="`/applications/${uuid}/review`" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1"></i>Preview &amp; submit</router-link>
                </div>
            </div>

            <div v-if="!app.editable" class="alert alert-secondary d-flex flex-wrap align-items-center gap-2">
                <i class="bi bi-lock-fill"></i>
                <span class="flex-grow-1">This application has been submitted and can no longer be edited. You are viewing it read-only.</span>
                <router-link :to="`/applications/${uuid}`" class="btn btn-sm btn-outline-dark">View status</router-link>
            </div>
            <div v-else-if="app.status === 'reopened'" class="alert alert-warning">
                <div class="fw-semibold"><i class="bi bi-arrow-counterclockwise me-1"></i>Your application has been reopened for correction.</div>
                <div v-if="app.reopen_reason" class="text-pre mt-1">{{ app.reopen_reason }}</div>
                <div v-if="app.reopened_until" class="mt-1">Make the corrections and resubmit by <strong>{{ formatDateTime(app.reopened_until) }}</strong>.</div>
                <div v-if="app.editable_sections" class="mt-1 small">
                    Only these steps can be edited:
                    <span v-for="s in steps.filter((x) => app!.editable_sections!.includes(x.key))" :key="s.key" class="badge text-bg-light border me-1">{{ s.form_reference }} · {{ s.title }}</span>
                </div>
            </div>
            <div v-else-if="!app.advertisement.is_open" class="alert alert-danger small">
                <i class="bi bi-calendar-x me-1"></i>The last date for this advertisement ({{ formatDate(app.advertisement.closes_at) }}) has passed. A draft can no longer be submitted.
            </div>

            <div class="row g-3">
                <!-- Desktop step list -->
                <aside class="col-lg-3 d-none d-lg-block">
                    <div class="position-sticky" style="top: 1rem">
                        <div class="small text-muted mb-1 d-flex justify-content-between"><span>Progress</span><span>{{ doneCount }} / {{ applicable.length }} complete</span></div>
                        <div class="progress mb-3" style="height: 6px" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" aria-label="Form completed">
                            <div class="progress-bar bg-success" :style="{ width: `${progress}%` }"></div>
                        </div>
                        <div class="list-group wizard-steps">
                            <router-link v-for="(s, i) in steps" :key="s.key" :to="`/applications/${uuid}/edit/${s.key}`"
                                class="list-group-item list-group-item-action d-flex align-items-start gap-2"
                                :class="{ active: s.key === currentKey, 'text-muted': !s.applicable && s.key !== currentKey }"
                                :aria-current="s.key === currentKey ? 'step' : undefined">
                                <i class="bi mt-1" :class="[stepIcon(s), s.key === currentKey ? 'text-white' : '']" aria-hidden="true"></i>
                                <span class="flex-grow-1">
                                    <span class="step-ref d-block">{{ i + 1 }} · {{ stepRef(s) }}</span>
                                    <span class="d-block lh-sm">{{ s.title }}</span>
                                    <span v-if="s.issues.length" class="visually-hidden">({{ s.issues.length }} item(s) need attention)</span>
                                </span>
                                <i v-if="!stepEditable(s.key) && app.editable" class="bi bi-lock small mt-1" title="Not open for correction"></i>
                                <span v-if="s.key === currentKey && dirty" class="badge rounded-pill text-bg-warning mt-1" title="Unsaved changes">•</span>
                            </router-link>
                            <router-link :to="`/applications/${uuid}/review`" class="list-group-item list-group-item-action d-flex align-items-start gap-2">
                                <i class="bi bi-send mt-1" aria-hidden="true"></i>
                                <span><span class="step-ref d-block">Final</span><span class="d-block lh-sm">Preview and submit</span></span>
                            </router-link>
                        </div>
                        <p v-if="issueCount" class="small text-muted mt-2 mb-0"><i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>{{ issueCount }} item(s) to complete before submission.</p>
                    </div>
                </aside>

                <section class="col-lg-9 wizard-body">
                    <!-- Mobile step picker -->
                    <div class="d-lg-none mb-3">
                        <label for="mobile-step" class="form-label small text-muted mb-1">Step {{ currentIndex + 1 }} of {{ steps.length }} · {{ doneCount }} / {{ applicable.length }} complete</label>
                        <select id="mobile-step" :value="currentKey" class="form-select" @change="onMobileStep">
                            <option v-for="(s, i) in steps" :key="s.key" :value="s.key">
                                {{ s.issues.length ? '⚠' : s.applicable ? '✓' : '–' }} {{ i + 1 }}. [{{ s.form_reference }}] {{ s.title }}
                            </option>
                        </select>
                        <div class="progress mt-2" style="height: 6px" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" aria-label="Form completed">
                            <div class="progress-bar bg-success" :style="{ width: `${progress}%` }"></div>
                        </div>
                    </div>

                    <div v-if="current" class="mb-3">
                        <div class="form-text-ref">STEP {{ currentIndex + 1 }} OF {{ steps.length }} · {{ stepRef(current).toUpperCase() }}</div>
                        <h2 class="h4 mb-0">{{ current.title }}</h2>
                    </div>

                    <div v-if="readonly && app.editable" class="alert alert-secondary small py-2"><i class="bi bi-lock me-1"></i>This step is not open for correction and is shown read-only.</div>
                    <div v-if="current?.issues.length" class="alert alert-warning small py-2">
                        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Still needed before you can submit:</div>
                        <ul class="mb-0 ps-3"><li v-for="(issue, i) in current.issues" :key="i">{{ issue }}</li></ul>
                    </div>
                    <div v-if="message" class="alert alert-danger small py-2" role="alert">
                        <div class="fw-semibold">{{ message }}</div>
                        <ul v-if="errorSummary.length" class="mb-0 ps-3 mt-1"><li v-for="(m, i) in errorSummary.slice(0, 12)" :key="i">{{ m }}</li></ul>
                    </div>

                    <form novalidate @submit.prevent="saveAndContinue">
                        <fieldset :disabled="readonly || saving" class="wizard-fieldset">
                            <component :is="COMPONENTS[currentKey]" v-if="drafts[currentKey]" :key="currentKey" :form="drafts[currentKey]" />
                        </fieldset>

                        <div class="sticky-actions mt-3">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <button v-if="prevStep" type="button" class="btn btn-outline-secondary" aria-label="Back to the previous step" @click="go(`/applications/${uuid}/edit/${prevStep.key}`)">
                                    <i class="bi bi-chevron-left"></i><span class="d-none d-sm-inline ms-1">Back</span>
                                </button>
                                <span v-if="dirty" class="small text-warning-emphasis d-none d-md-inline"><i class="bi bi-circle-fill me-1" style="font-size: .5rem"></i>Unsaved changes</span>
                                <div class="ms-auto d-flex gap-2">
                                    <template v-if="!readonly">
                                        <button type="button" class="btn btn-outline-primary" :disabled="saving" @click="save">
                                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span><i v-else class="bi bi-save me-1"></i>Save draft
                                        </button>
                                        <button type="submit" class="btn btn-primary" :disabled="saving">
                                            Save &amp; {{ nextStep ? 'continue' : 'review' }}<i class="bi bi-chevron-right ms-1"></i>
                                        </button>
                                    </template>
                                    <button v-else type="button" class="btn btn-primary" @click="go(nextStep ? `/applications/${uuid}/edit/${nextStep.key}` : `/applications/${uuid}/review`)">
                                        {{ nextStep ? 'Next' : 'Preview' }}<i class="bi bi-chevron-right ms-1"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </LoadingState>
</template>

<style scoped>
.wizard-fieldset { min-width: 0; }
</style>
