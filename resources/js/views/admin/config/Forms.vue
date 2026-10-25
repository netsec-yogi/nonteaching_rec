<script setup lang="ts">
/**
 * Referee recommendation forms and Screening Committee evaluation forms.
 * Both are templates of ordered, typed questions.
 */
import { computed, reactive, ref, watch } from 'vue';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import { useToast } from '@/stores/toast';
import FieldError from '@/components/FieldError.vue';
import LoadingState from '@/components/LoadingState.vue';
import QuestionEditor from './parts/QuestionEditor.vue';
import { move, type FormQuestion, type FormTemplate } from './parts/types';

const props = defineProps<{ kind: string }>();
const toast = useToast();
const form = useForm();
const loading = ref(true);
const error = ref<string | null>(null);
const templates = ref<FormTemplate[]>([]);
const selected = ref<number | 'new' | null>(null);
const isReferee = computed(() => props.kind === 'referee');
const title = computed(() => (isReferee.value ? 'Referee recommendation forms' : 'Screening Committee evaluation forms'));

const f = reactive({ name: '', intro: '', good_faith_statement: '', is_active: true });
const options = ref<string[]>([]);
const questions = ref<FormQuestion[]>([]);

async function load(keep?: number) {
    loading.value = true;
    error.value = null;
    try {
        templates.value = (await api.get(`/admin/forms/${props.kind}`)).data;
        const pick = templates.value.find((t) => t.id === keep) ?? templates.value[0];
        if (pick) select(pick); else startNew();
    } catch (e) { error.value = toApiError(e).message; } finally { loading.value = false; }
}
watch(() => props.kind, () => load(), { immediate: true });

function select(t: FormTemplate) {
    form.clear();
    selected.value = t.id;
    Object.assign(f, {
        name: t.name, intro: (isReferee.value ? t.introduction : t.description) ?? '',
        good_faith_statement: t.good_faith_statement ?? '', is_active: t.is_active,
    });
    options.value = [...t.recommendation_options];
    questions.value = (t.questions ?? t.fields ?? []).map((q) => ({
        id: q.id, code: q.code, label: q.label, help_text: q.help_text, type: q.type, is_required: q.is_required,
        options: q.options ? JSON.parse(JSON.stringify(q.options)) : null,
    }));
}

function startNew() {
    form.clear();
    selected.value = 'new';
    Object.assign(f, { name: '', intro: '', good_faith_statement: isReferee.value ? 'I confirm that the information given above is accurate to the best of my knowledge and is submitted in good faith.' : '', is_active: true });
    options.value = isReferee.value ? ['Strongly Recommended', 'Recommended', 'Not Recommended'] : ['Recommended', 'Not Recommended', 'Requires Further Review'];
    questions.value = [];
}

function addQuestion() { questions.value.push({ code: '', label: '', help_text: null, type: 'yes_no', options: null, is_required: true }); }

async function save() {
    const payload: Record<string, unknown> = {
        name: f.name, is_active: f.is_active,
        recommendation_options: options.value.map((o) => o.trim()).filter(Boolean),
        questions: questions.value.map((q) => ({
            ...q, help_text: q.help_text || null,
            options: q.type === 'choice' ? { choices: (q.options?.choices ?? []).map((c) => c.trim()).filter(Boolean) } : q.options,
        })),
    };
    if (isReferee.value) { payload.introduction = f.intro || null; payload.good_faith_statement = f.good_faith_statement; }
    else payload.description = f.intro || null;
    const id = selected.value;
    const res = await form.run(() => (id === 'new' ? api.post(`/admin/forms/${props.kind}`, payload) : api.put(`/admin/forms/${props.kind}/${id}`, payload)), 'Form saved.');
    if (res) load(res.data.id);
}

async function remove() {
    const t = templates.value.find((x) => x.id === selected.value);
    if (!t || !confirm(`Delete “${t.name}”? Recommendations or evaluations already submitted keep their own copy of the questions. Advertisements using this form should be switched to another one.`)) return;
    try {
        await api.delete(`/admin/forms/${props.kind}/${t.id}`);
        toast.success('Form deleted.');
        load();
    } catch (e) { toast.error(toApiError(e).message); }
}

const optErr = computed(() => Object.entries(form.errors).filter(([k]) => k.startsWith('recommendation_options')).map(([, v]) => v[0]));
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h1 class="page-title h4 mb-0">{{ title }}</h1>
            <button type="button" class="btn btn-primary btn-sm ms-auto" @click="startNew"><i class="bi bi-plus-lg me-1"></i>New form</button>
        </div>
        <div class="alert alert-info small">
            <i class="bi bi-info-circle me-1"></i>
            {{ isReferee ? 'Each submitted recommendation' : 'Each submitted evaluation' }} stores a snapshot of the question wording it was answered against.
            Editing, reordering or removing questions here does not alter {{ isReferee ? 'recommendations' : 'evaluations' }} already received; changes apply to new submissions only.
        </div>

        <LoadingState :loading="loading" :error="error">
            <div class="row g-3">
                <div class="col-lg-3">
                    <div class="list-group">
                        <button v-for="t in templates" :key="t.id" type="button" class="list-group-item list-group-item-action" :class="{ active: selected === t.id }" @click="select(t)">
                            <div class="fw-semibold small">{{ t.name }}</div>
                            <div class="small text-muted">{{ (t.questions ?? t.fields ?? []).length }} questions{{ t.is_active ? '' : ' · inactive' }}</div>
                        </button>
                        <div v-if="selected === 'new'" class="list-group-item active small fw-semibold">New form</div>
                    </div>
                </div>
                <div class="col-lg-9">
                    <div class="card section-card mb-3">
                        <div class="card-body row g-3">
                            <div class="col-md-8">
                                <label class="form-label required" for="fm-name">Template name</label>
                                <input id="fm-name" v-model="f.name" class="form-control" maxlength="150" :class="{ 'is-invalid': form.errors.name }">
                                <FieldError :errors="form.errors" name="name" />
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check mb-2">
                                    <input id="fm-active" v-model="f.is_active" class="form-check-input" type="checkbox">
                                    <label class="form-check-label" for="fm-active">Active (offered for new advertisements)</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="fm-intro">{{ isReferee ? 'Introduction shown to the referee' : 'Description' }}</label>
                                <textarea id="fm-intro" v-model="f.intro" rows="3" class="form-control" maxlength="5000" :class="{ 'is-invalid': form.errors.introduction || form.errors.description }"></textarea>
                                <FieldError :errors="form.errors" :name="isReferee ? 'introduction' : 'description'" />
                            </div>
                            <div v-if="isReferee" class="col-12">
                                <label class="form-label required" for="fm-gf">Good-faith statement (the referee must confirm it)</label>
                                <textarea id="fm-gf" v-model="f.good_faith_statement" rows="2" class="form-control" maxlength="2000" :class="{ 'is-invalid': form.errors.good_faith_statement }"></textarea>
                                <FieldError :errors="form.errors" name="good_faith_statement" />
                            </div>
                            <div class="col-12">
                                <div class="form-label required">{{ isReferee ? 'Overall recommendation options' : 'Committee recommendation options' }}</div>
                                <div class="row g-1">
                                    <div v-for="(o, i) in options" :key="i" class="col-md-6">
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">{{ i + 1 }}</span>
                                            <input v-model="options[i]" :aria-label="`Recommendation option ${i + 1}`" class="form-control" maxlength="100" :class="{ 'is-invalid': form.errors[`recommendation_options.${i}`] }">
                                            <button type="button" class="btn btn-outline-secondary" :disabled="i === 0" title="Move up" @click="move(options, i, -1)"><i class="bi bi-arrow-up"></i></button>
                                            <button type="button" class="btn btn-outline-danger" :disabled="options.length <= 2" title="Remove" @click="options.splice(i, 1)"><i class="bi bi-x"></i></button>
                                        </div>
                                    </div>
                                </div>
                                <div v-for="m in optErr" :key="m" class="invalid-feedback d-block">{{ m }}</div>
                                <button type="button" class="btn btn-sm btn-link p-0 mt-1" :disabled="options.length >= 10" @click="options.push('')"><i class="bi bi-plus"></i> Add option</button>
                            </div>
                        </div>
                    </div>

                    <h2 class="h6">Questions <span class="text-muted fw-normal">({{ questions.length }})</span></h2>
                    <FieldError :errors="form.errors" name="questions" />
                    <QuestionEditor v-for="(q, i) in questions" :key="q.id ?? `n${i}`" :q="q" :index="i" :errors="form.errors" :first="i === 0" :last="i === questions.length - 1"
                                    @move="(d) => move(questions, i, d)" @remove="questions.splice(i, 1)" />
                    <button type="button" class="btn btn-sm btn-outline-primary mb-3" :disabled="questions.length >= 40" @click="addQuestion"><i class="bi bi-plus"></i> Add question</button>

                    <div class="sticky-actions d-flex align-items-center gap-2">
                        <span v-if="form.message.value && Object.keys(form.errors).length" class="text-danger small">Please correct the highlighted fields.</span>
                        <button v-if="selected !== 'new'" type="button" class="btn btn-outline-danger" @click="remove"><i class="bi bi-trash me-1"></i>Delete</button>
                        <button type="button" class="btn btn-primary ms-auto" :disabled="form.busy.value" @click="save">{{ selected === 'new' ? 'Create form' : 'Save form' }}</button>
                    </div>
                </div>
            </div>
        </LoadingState>
    </div>
</template>
