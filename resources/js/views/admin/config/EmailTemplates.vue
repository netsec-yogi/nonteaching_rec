<script setup lang="ts">
/** Every automated email: subject and Markdown body with {{ placeholders }}, previewed as it will be sent. */
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import FieldError from '@/components/FieldError.vue';
import LoadingState from '@/components/LoadingState.vue';
import { formatDateTime } from '@/utils/format';

interface Template {
    id: number; key: string; name: string; subject: string; body: string; placeholders: string[] | null;
    description: string | null; is_active: boolean; updated_at: string;
}

const form = useForm();
const loading = ref(true);
const error = ref<string | null>(null);
const templates = ref<Template[]>([]);
const current = ref<Template | null>(null);
const q = ref('');
const f = reactive({ subject: '', body: '', is_active: true });
const preview = ref<{ subject: string; html: string } | null>(null);
const previewError = ref<string | null>(null);
const body = ref<HTMLTextAreaElement | null>(null);
const subjectInput = ref<HTMLInputElement | null>(null);
const lastFocus = ref<'subject' | 'body'>('body');
const dirty = computed(() => !!current.value && (f.subject !== current.value.subject || f.body !== current.value.body || f.is_active !== current.value.is_active));
const unknown = computed(() => {
    const allowed = new Set(current.value?.placeholders ?? []);
    const used = [...`${f.subject}\n${f.body}`.matchAll(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi)].map((m) => m[1]);
    return [...new Set(used.filter((u) => !allowed.has(u)))];
});

async function load(keep?: number) {
    loading.value = true;
    try {
        templates.value = (await api.get('/admin/email-templates')).data;
        const pick = templates.value.find((t) => t.id === (keep ?? current.value?.id)) ?? templates.value[0];
        if (pick) select(pick, true);
    } catch (e) { error.value = toApiError(e).message; } finally { loading.value = false; }
}
onMounted(() => load());

function select(t: Template, force = false) {
    if (!force && dirty.value && !confirm('Discard unsaved changes to this template?')) return;
    form.clear();
    current.value = t;
    Object.assign(f, { subject: t.subject, body: t.body, is_active: t.is_active });
    refresh();
}

let timer: ReturnType<typeof setTimeout> | undefined;
watch(() => [f.subject, f.body], () => { clearTimeout(timer); timer = setTimeout(refresh, 600); });
onBeforeUnmount(() => clearTimeout(timer));

async function refresh() {
    if (!current.value) return;
    const id = current.value.id;
    try {
        const { data } = await api.post(`/admin/email-templates/${id}/preview`, { subject: f.subject, body: f.body });
        if (current.value?.id === id) { preview.value = data; previewError.value = null; }
    } catch (e) { previewError.value = toApiError(e).message; }
}

function insert(p: string) {
    const token = `{{ ${p} }}`;
    const el = lastFocus.value === 'subject' ? subjectInput.value : body.value;
    const key = lastFocus.value;
    if (!el) { f.body += token; return; }
    const start = el.selectionStart ?? f[key].length;
    const end = el.selectionEnd ?? start;
    f[key] = f[key].slice(0, start) + token + f[key].slice(end);
    requestAnimationFrame(() => { el.focus(); el.setSelectionRange(start + token.length, start + token.length); });
}

async function save() {
    if (!current.value) return;
    const res = await form.run(() => api.put(`/admin/email-templates/${current.value!.id}`, f), 'Email template saved.');
    if (res) load(current.value.id);
}

const match = (t: Template) => !q.value || `${t.name} ${t.key}`.toLowerCase().includes(q.value.toLowerCase());
</script>

<template>
    <div>
        <h1 class="page-title h4 mb-3">Email templates</h1>
        <LoadingState :loading="loading" :error="error">
            <div class="row g-3">
                <div class="col-lg-3">
                    <input v-model="q" type="search" class="form-control form-control-sm mb-2" placeholder="Search templates…" aria-label="Search templates">
                    <div class="list-group small">
                        <button v-for="t in templates.filter(match)" :key="t.id" type="button" class="list-group-item list-group-item-action" :class="{ active: current?.id === t.id }" @click="select(t)">
                            <div class="fw-semibold">{{ t.name }}</div>
                            <div :class="current?.id === t.id ? 'text-white-50' : 'text-muted'"><code :class="current?.id === t.id ? 'text-white-50' : ''">{{ t.key }}</code><span v-if="!t.is_active"> · inactive</span></div>
                        </button>
                    </div>
                </div>
                <div v-if="current" class="col-lg-9">
                    <div class="row g-3">
                        <div class="col-xl-6">
                            <div class="card section-card">
                                <div class="card-header d-flex align-items-center">
                                    <span>{{ current.name }}</span>
                                    <div class="form-check form-switch ms-auto mb-0">
                                        <input id="et-active" v-model="f.is_active" class="form-check-input" type="checkbox">
                                        <label class="form-check-label small" for="et-active">Active</label>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <p v-if="current.description" class="small text-muted">{{ current.description }}</p>
                                    <div v-if="!f.is_active" class="alert alert-warning small py-2">While inactive, this email is not sent.</div>
                                    <div class="mb-3">
                                        <label class="form-label required" for="et-subject">Subject</label>
                                        <input id="et-subject" ref="subjectInput" v-model="f.subject" class="form-control" maxlength="300" :class="{ 'is-invalid': form.errors.subject }" @focus="lastFocus = 'subject'">
                                        <FieldError :errors="form.errors" name="subject" />
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label required" for="et-body">Body <span class="text-muted fw-normal small">(Markdown)</span></label>
                                        <textarea id="et-body" ref="body" v-model="f.body" rows="16" class="form-control font-monospace small" maxlength="20000" :class="{ 'is-invalid': form.errors.body }" @focus="lastFocus = 'body'"></textarea>
                                        <FieldError :errors="form.errors" name="body" />
                                    </div>
                                    <div class="small mb-1 text-muted">Insert a placeholder at the cursor:</div>
                                    <div class="d-flex flex-wrap gap-1 mb-2">
                                        <button v-for="p in current.placeholders ?? []" :key="p" type="button" class="btn btn-sm btn-outline-secondary py-0 font-monospace" @click="insert(p)">{{ p }}</button>
                                        <span v-if="!current.placeholders?.length" class="small text-muted">None available.</span>
                                    </div>
                                    <div v-if="unknown.length" class="alert alert-warning small py-2 mb-2">
                                        Not available for this email and will be left blank: <code v-for="u in unknown" :key="u" class="me-1">{{ u }}</code>
                                    </div>
                                    <div class="small text-muted mb-3">**bold**, *italic*, [link text](url), blank line for a new paragraph, two trailing spaces for a line break.</div>
                                    <div class="d-flex align-items-center gap-2">
                                        <small class="text-muted">Last updated {{ formatDateTime(current.updated_at) }}</small>
                                        <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" :disabled="!dirty" @click="select(current, true)">Revert</button>
                                        <button type="button" class="btn btn-primary btn-sm" :disabled="!dirty || form.busy.value" @click="save">Save template</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-6">
                            <div class="card section-card h-100">
                                <div class="card-header d-flex align-items-center">Preview <span class="small text-muted ms-2 fw-normal">with sample values</span>
                                    <button type="button" class="btn btn-sm btn-link ms-auto" title="Refresh preview" @click="refresh"><i class="bi bi-arrow-clockwise"></i></button>
                                </div>
                                <div class="card-body d-flex flex-column">
                                    <div v-if="previewError" class="alert alert-danger small">{{ previewError }}</div>
                                    <template v-if="preview">
                                        <div class="small text-muted">Subject</div>
                                        <div class="fw-semibold mb-2">{{ preview.subject }}</div>
                                        <iframe :srcdoc="preview.html" sandbox="" title="Email preview" class="border rounded w-100 flex-grow-1 bg-white" style="min-height: 28rem"></iframe>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </LoadingState>
    </div>
</template>
