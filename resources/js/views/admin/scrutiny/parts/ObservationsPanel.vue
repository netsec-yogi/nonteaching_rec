<script setup lang="ts">
/** Scrutiny observations: free-text notes against a section, optionally flagged as a deficiency. */
import { reactive, ref } from 'vue';
import { api } from '@/api/client';
import { useForm } from '@/composables/useForm';
import FieldError from '@/components/FieldError.vue';
import { formatDateTime, humanize } from '@/utils/format';
import type { Observation } from './types';

const props = defineProps<{ applicationUuid: string; observations: Observation[]; sections: { key: string; title: string }[]; canAct: boolean }>();
const emit = defineEmits<{ (e: 'changed'): void }>();

const { busy, errors, run } = useForm();
const draft = reactive({ section: 'general', observation: '', is_deficiency: false });
const resolving = ref<number | null>(null);

const sectionTitle = (key: string | null) => props.sections.find((s) => s.key === key)?.title ?? humanize(key ?? 'general');

async function add() {
    const ok = await run(() => api.post(`/admin/applications/${props.applicationUuid}/scrutiny/observations`, { ...draft }), 'Observation recorded.');
    if (ok) {
        draft.observation = '';
        draft.is_deficiency = false;
        emit('changed');
    }
}

async function resolve(o: Observation) {
    if (!confirm('Mark this observation as resolved?')) return;
    resolving.value = o.id;
    const ok = await run(() => api.post(`/admin/applications/${props.applicationUuid}/scrutiny/observations/${o.id}/resolve`), 'Observation resolved.');
    resolving.value = null;
    if (ok) emit('changed');
}
</script>

<template>
    <div>
        <form v-if="canAct" class="card section-card mb-3" @submit.prevent="add">
            <div class="card-header">Add observation</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-5">
                        <label class="form-label small mb-1" for="obs-section">Section</label>
                        <select id="obs-section" v-model="draft.section" class="form-select form-select-sm">
                            <option value="general">General</option>
                            <option v-for="s in sections" :key="s.key" :value="s.key">{{ s.title }}</option>
                        </select>
                    </div>
                    <div class="col-md-7 d-flex align-items-end">
                        <div class="form-check">
                            <input id="obs-def" v-model="draft.is_deficiency" class="form-check-input" type="checkbox">
                            <label class="form-check-label small" for="obs-def">This is a deficiency in the application</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small mb-1 required" for="obs-text">Observation</label>
                        <textarea id="obs-text" v-model="draft.observation" class="form-control form-control-sm" :class="{ 'is-invalid': errors.observation }" rows="3" maxlength="5000"></textarea>
                        <FieldError :errors="errors" name="observation" />
                    </div>
                </div>
                <div class="text-end mt-2">
                    <button type="submit" class="btn btn-sm btn-primary" :disabled="busy || !draft.observation.trim()">Add observation</button>
                </div>
            </div>
        </form>

        <ul class="list-group">
            <li v-for="o in observations" :key="o.id" class="list-group-item" :class="{ 'bg-light': o.resolved_at }">
                <div class="d-flex flex-wrap gap-2 align-items-start">
                    <div class="flex-grow-1">
                        <div class="small text-muted">
                            {{ sectionTitle(o.section) }}
                            <span v-if="o.is_deficiency" class="badge text-bg-danger ms-1">Deficiency</span>
                            <span v-if="o.resolved_at" class="badge text-bg-success ms-1">Resolved {{ formatDateTime(o.resolved_at) }}</span>
                        </div>
                        <div class="text-pre" :class="{ 'text-decoration-line-through text-muted': o.resolved_at }">{{ o.observation }}</div>
                        <div class="small text-muted">{{ o.by ?? '—' }}, {{ formatDateTime(o.at) }}</div>
                    </div>
                    <button v-if="canAct && !o.resolved_at" type="button" class="btn btn-sm btn-outline-success" :disabled="resolving === o.id" @click="resolve(o)">
                        <i class="bi bi-check2 me-1"></i>Resolve
                    </button>
                </div>
            </li>
            <li v-if="!observations.length" class="list-group-item text-muted small">No observations recorded.</li>
        </ul>
    </div>
</template>
