<script setup lang="ts">
/** Tab 6: the ordered scrutiny workflow. Exactly one decision stage, and it must be last. */
import { computed, onMounted, ref } from 'vue';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import FieldError from '@/components/FieldError.vue';
import LoadingState from '@/components/LoadingState.vue';
import { STAGE_ROLES, move, rowErrors, type AdvertisementDetail, type Stage } from './types';

const props = defineProps<{ ad: AdvertisementDetail }>();
const emit = defineEmits<{ (e: 'reload'): void }>();
const form = useForm();
const loading = ref(true);
const error = ref<string | null>(null);
const stages = ref<Stage[]>([]);
const dirty = ref(false);
const readOnly = computed(() => props.ad.status === 'archived');
const url = computed(() => `/admin/advertisements/${props.ad.id}/stages`);

const toRow = (s: Stage): Stage => ({ id: s.id, code: s.code, name: s.name, role: s.role, is_decision_stage: s.is_decision_stage, allow_same_reviewer: s.allow_same_reviewer });

async function load() {
    loading.value = true;
    try {
        stages.value = ((await api.get(url.value)).data as Stage[]).map(toRow);
        dirty.value = false;
    } catch (e) { error.value = toApiError(e).message; } finally { loading.value = false; }
}
onMounted(load);

const problems = computed(() => {
    const out: string[] = [];
    const decisions = stages.value.filter((s) => s.is_decision_stage).length;
    if (!stages.value.length) out.push('Add at least one stage.');
    if (stages.value.length > 8) out.push('A workflow may have at most 8 stages.');
    if (decisions !== 1) out.push('Exactly one stage must be the decision stage.');
    else if (!stages.value[stages.value.length - 1]?.is_decision_stage) out.push('The decision stage must be the last stage.');
    const codes = stages.value.map((s) => s.code);
    if (codes.some((c, i) => c && codes.indexOf(c) !== i)) out.push('Stage codes must be unique.');
    if (stages.value.some((s) => !/^[a-z0-9_]+$/.test(s.code))) out.push('Codes may contain only lower-case letters, digits and underscores.');
    if (stages.value.some((s) => !s.name.trim())) out.push('Every stage needs a name.');
    return out;
});

function setDecision(i: number) {
    stages.value.forEach((s, j) => { s.is_decision_stage = j === i; });
    changed();
}
function add() {
    // New stages go before the decision stage so it stays last.
    const at = stages.value.findIndex((s) => s.is_decision_stage);
    const row: Stage = { id: null, code: `stage_${stages.value.length + 1}`, name: '', role: 'scrutiny_user', is_decision_stage: false, allow_same_reviewer: false };
    if (at >= 0) stages.value.splice(at, 0, row); else stages.value.push(row);
    changed();
}
function changed() { dirty.value = true; }

async function save() {
    if (problems.value.length) return;
    const res = await form.run(() => api.put(url.value, { stages: stages.value }), 'Scrutiny workflow saved.');
    if (res) { stages.value = (res.data as Stage[]).map(toRow); dirty.value = false; emit('reload'); }
}
</script>

<template>
    <LoadingState :loading="loading" :error="error">
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th style="width: 3rem">#</th><th>Code</th><th>Name</th><th>Performed by</th><th class="text-center">Decision</th><th class="text-center">Same reviewer allowed</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="(s, i) in stages" :key="s.id ?? `new-${i}`">
                                    <td class="text-muted">{{ i + 1 }}</td>
                                    <td>
                                        <input v-model="s.code" class="form-control form-control-sm font-monospace" maxlength="40" :disabled="readOnly" :class="{ 'is-invalid': form.errors[`stages.${i}.code`] }" @input="changed">
                                        <FieldError :errors="rowErrors(form.errors, `stages.${i}.`)" name="code" />
                                    </td>
                                    <td>
                                        <input v-model="s.name" class="form-control form-control-sm" maxlength="150" :disabled="readOnly" :class="{ 'is-invalid': form.errors[`stages.${i}.name`] }" @input="changed">
                                        <FieldError :errors="rowErrors(form.errors, `stages.${i}.`)" name="name" />
                                    </td>
                                    <td>
                                        <select v-model="s.role" class="form-select form-select-sm" :disabled="readOnly" @change="changed">
                                            <option v-for="(label, key) in STAGE_ROLES" :key="key" :value="key">{{ label }}</option>
                                        </select>
                                    </td>
                                    <td class="text-center"><input class="form-check-input" type="radio" name="decision-stage" :checked="s.is_decision_stage" :disabled="readOnly" :aria-label="`Stage ${i + 1} is the decision stage`" @change="setDecision(i)"></td>
                                    <td class="text-center"><input v-model="s.allow_same_reviewer" class="form-check-input" type="checkbox" :disabled="readOnly" :aria-label="`Stage ${i + 1} allows the same reviewer`" @change="changed"></td>
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="btn btn-sm btn-link p-0 me-1" :disabled="readOnly || i === 0" title="Move up" @click="move(stages, i, -1); changed()"><i class="bi bi-arrow-up"></i></button>
                                        <button type="button" class="btn btn-sm btn-link p-0 me-1" :disabled="readOnly || i === stages.length - 1" title="Move down" @click="move(stages, i, 1); changed()"><i class="bi bi-arrow-down"></i></button>
                                        <button type="button" class="btn btn-sm btn-link text-danger p-0" :disabled="readOnly || stages.length <= 1" title="Remove" @click="stages.splice(i, 1); changed()"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div v-if="dirty && problems.length" class="alert alert-warning small mt-2 mb-0">
                    <ul class="mb-0"><li v-for="p in problems" :key="p">{{ p }}</li></ul>
                </div>
                <div v-if="form.message.value" class="alert alert-danger small mt-2 mb-0">{{ form.message.value }}</div>
                <div v-if="!readOnly" class="d-flex gap-2 mt-2">
                    <button type="button" class="btn btn-sm btn-outline-primary" :disabled="stages.length >= 8" @click="add"><i class="bi bi-plus"></i> Add stage</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="!dirty" @click="load">Discard changes</button>
                    <button type="button" class="btn btn-sm btn-primary ms-auto" :disabled="!dirty || problems.length > 0 || form.busy.value" @click="save">Save workflow</button>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card section-card">
                    <div class="card-header"><i class="bi bi-shield-check me-1"></i>Separation of duties</div>
                    <div class="card-body small">
                        <p>Every application passes through these stages in order. Each stage is carried out by an officer holding the selected role and assigned to that application.</p>
                        <p>The <strong>decision stage</strong> records the administrative outcome (complete, incomplete, not eligible, …) and must be the final stage, so that no decision is taken before every earlier check is done.</p>
                        <p>Leave <strong>“Same reviewer allowed”</strong> unticked to keep the four-eyes principle: an officer who acted on an earlier stage of an application cannot be assigned to, or act on, this stage for the same application.</p>
                        <p class="mb-0 text-muted">Stages that already have assignments cannot be removed, only renamed or reordered.</p>
                    </div>
                </div>
            </div>
        </div>
    </LoadingState>
</template>
