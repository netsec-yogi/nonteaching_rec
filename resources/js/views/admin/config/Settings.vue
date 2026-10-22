<script setup lang="ts">
/** System-wide settings, grouped by key prefix. Input types follow each setting's current value. */
import { computed, onMounted, ref } from 'vue';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import LoadingState from '@/components/LoadingState.vue';
import { humanize } from '@/utils/format';

type Value = string | number | boolean | null;
const GROUPS: Record<string, { title: string; icon: string; help?: string }> = {
    institute: { title: 'Institute', icon: 'building', help: 'Shown in the portal header, emails and generated PDFs.' },
    declaration: { title: 'Candidate declaration', icon: 'pen' },
    privacy: { title: 'Privacy notice', icon: 'shield-lock' },
    referee: { title: 'Referees', icon: 'envelope-paper' },
    clarification: { title: 'Clarifications', icon: 'chat-left-text' },
    retention: { title: 'Records retention', icon: 'archive' },
    uploads: { title: 'Uploads', icon: 'upload' },
    workflow: { title: 'Workflow', icon: 'diagram-3' },
    applicant: { title: 'Applicants', icon: 'person' },
};
const LABELS: Record<string, string> = {
    'institute.recruitment_email': 'Recruitment email', 'institute.recruitment_phone': 'Recruitment phone',
    'declaration.version': 'Declaration version', 'declaration.text': 'Declaration text', 'privacy.notice': 'Privacy notice text',
    'referee.default_deadline_days': 'Default referee deadline (days)', 'referee.token_rate_limit_per_minute': 'Referee link requests per minute',
    'clarification.default_days': 'Default clarification response time (days)', 'retention.default_years': 'Default retention (years)',
    'uploads.max_kb_default': 'Default upload limit (KB)', 'workflow.require_all_referees_before_committee': 'Require all referee recommendations before forwarding to the committee',
    'applicant.max_open_drafts': 'Maximum open draft applications per candidate',
};

const form = useForm();
const loading = ref(true);
const error = ref<string | null>(null);
const keys = ref<string[]>([]);
const original = ref<Record<string, Value>>({});
// eslint-disable-next-line @typescript-eslint/no-explicit-any -- bound to text, number and checkbox inputs
const values = ref<Record<string, any>>({});

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/admin/settings');
        keys.value = data.keys;
        original.value = { ...data.values };
        values.value = { ...data.values };
    } catch (e) { error.value = toApiError(e).message; } finally { loading.value = false; }
}
onMounted(load);

const groups = computed(() => {
    const out: Record<string, string[]> = {};
    for (const k of keys.value) (out[k.split('.')[0]] ??= []).push(k);
    return Object.entries(out);
});
const kind = (k: string) => {
    const v = original.value[k];
    if (typeof v === 'boolean') return 'bool';
    if (typeof v === 'number') return 'number';
    return typeof v === 'string' && (v.length > 120 || /text|notice|address/.test(k)) ? 'long' : 'text';
};
const label = (k: string) => LABELS[k] ?? humanize(k.split('.').slice(1).join(' '));
const changed = computed(() => keys.value.filter((k) => values.value[k] !== original.value[k]));
const versionChanged = computed(() => changed.value.includes('declaration.version'));
const textChangedOnly = computed(() => changed.value.includes('declaration.text') && !versionChanged.value);

async function save() {
    if (versionChanged.value && !confirm('Changing the declaration version requires every candidate with a draft application to accept the declaration again before submitting. Continue?')) return;
    const payload = Object.fromEntries(changed.value.map((k) => [k, kind(k) === 'number' ? Number(values.value[k]) : values.value[k]]));
    const res = await form.run(() => api.put('/admin/settings', { values: payload }), 'Settings saved.');
    if (res) { original.value = { ...res.data.values }; values.value = { ...res.data.values }; }
}
</script>

<template>
    <div>
        <h1 class="page-title h4 mb-3">Settings</h1>
        <LoadingState :loading="loading" :error="error">
            <div class="row g-3">
                <div v-for="[g, ks] in groups" :key="g" :class="ks.some((k) => kind(k) === 'long') ? 'col-12' : 'col-lg-6'">
                    <div class="card section-card h-100">
                        <div class="card-header"><i class="bi me-1" :class="`bi-${GROUPS[g]?.icon ?? 'sliders'}`"></i>{{ GROUPS[g]?.title ?? humanize(g) }}</div>
                        <div class="card-body">
                            <p v-if="GROUPS[g]?.help" class="small text-muted">{{ GROUPS[g].help }}</p>
                            <div v-if="g === 'declaration'" class="alert alert-warning small py-2">
                                <i class="bi bi-exclamation-triangle me-1"></i>Changing the <strong>declaration version</strong> requires every candidate with a draft application to re-accept the declaration. Change the version whenever the declaration text changes materially.
                            </div>
                            <div v-for="k in ks" :key="k" class="mb-3">
                                <div v-if="kind(k) === 'bool'" class="form-check form-switch">
                                    <input :id="`s-${k}`" v-model="values[k]" class="form-check-input" type="checkbox">
                                    <label class="form-check-label" :for="`s-${k}`">{{ label(k) }}</label>
                                </div>
                                <template v-else>
                                    <label class="form-label small fw-semibold mb-1" :for="`s-${k}`">{{ label(k) }} <code class="fw-normal text-muted ms-1">{{ k }}</code></label>
                                    <textarea v-if="kind(k) === 'long'" :id="`s-${k}`" v-model="values[k]" rows="4" class="form-control" maxlength="10000"></textarea>
                                    <input v-else-if="kind(k) === 'number'" :id="`s-${k}`" v-model.number="values[k]" type="number" min="0" class="form-control" style="max-width: 12rem">
                                    <input v-else :id="`s-${k}`" v-model="values[k]" class="form-control" maxlength="10000">
                                </template>
                                <div v-if="values[k] !== original[k]" class="form-text text-warning"><i class="bi bi-pencil-fill me-1"></i>Changed</div>
                            </div>
                            <div v-if="g === 'declaration' && textChangedOnly" class="small text-warning">You changed the declaration text but not its version; candidates who already accepted will not be asked again.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="sticky-actions d-flex align-items-center gap-2 mt-3">
                <span class="small text-muted">{{ changed.length ? `${changed.length} unsaved change(s)` : 'No unsaved changes' }}</span>
                <span v-if="form.message.value" class="small text-danger">{{ form.message.value }}</span>
                <button type="button" class="btn btn-outline-secondary ms-auto" :disabled="!changed.length" @click="values = { ...original }">Discard</button>
                <button type="button" class="btn btn-primary" :disabled="!changed.length || form.busy.value" @click="save">Save settings</button>
            </div>
        </LoadingState>
    </div>
</template>
