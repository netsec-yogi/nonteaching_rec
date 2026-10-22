<script setup lang="ts">
/**
 * Upload slots for the document types configured to appear on a step.
 * Mandatory types come from the candidate's resolved requirements; other
 * types of the step are optional. Types attached per row (certificates on
 * qualification rows, etc.) are summarised instead of given a loose slot,
 * unless loose documents of that type already exist.
 */
import { computed } from 'vue';
import FileUploader from '@/components/FileUploader.vue';
import { ROW_DOCUMENTS, useWizard } from './context';
import type { StepKey } from './types';

const props = withDefaults(defineProps<{
    step: StepKey;
    /** Optional types to hide (unless required or already uploaded), e.g. a disability certificate when not PwD. */
    hide?: string[];
    /** Types to mark mandatory regardless of requirements (e.g. endorsement when required). */
    forceRequired?: string[];
    title?: string;
}>(), { hide: () => [], forceRequired: () => [], title: 'Documents for this section' });

const { uuid, payload, readonly, setDocuments } = useWizard();
const rowType = computed(() => ROW_DOCUMENTS[props.step]?.type ?? null);
const requirement = (code: string) => payload.value.requirements.find((r) => r.document_type === code);
const hasLoose = (code: string) => payload.value.documents.some((d) => d.document_type === code && !d.documentable_type);
const hasAny = (code: string) => payload.value.documents.some((d) => d.document_type === code);

const slots = computed(() => payload.value.document_types
    .filter((t) => (t.step ?? 'checklist') === props.step)
    .filter((t) => t.code !== rowType.value || hasLoose(t.code))
    .map((t) => {
        const req = requirement(t.code);
        return {
            type: t,
            required: !!req?.is_mandatory || props.forceRequired.includes(t.code),
            instructions: req?.instructions ?? t.description ?? null,
        };
    })
    .filter((s) => s.required || !props.hide.includes(s.type.code) || hasAny(s.type.code))
    .sort((a, b) => Number(b.required) - Number(a.required)));

const rowRequirement = computed(() => (rowType.value ? requirement(rowType.value) : undefined));
</script>

<template>
    <div v-if="slots.length || rowRequirement" class="card section-card">
        <div class="card-header"><i class="bi bi-paperclip me-1"></i>{{ title }}</div>
        <div class="card-body py-2">
            <div v-if="rowRequirement" class="alert py-2 small mb-2" :class="rowRequirement.satisfied ? 'alert-success' : 'alert-warning'">
                <i class="bi me-1" :class="rowRequirement.satisfied ? 'bi-check-circle' : 'bi-exclamation-triangle'"></i>
                <strong>{{ rowRequirement.is_mandatory ? 'Required' : 'Optional' }}: {{ rowRequirement.name }}.</strong>
                {{ rowRequirement.instructions ?? 'Attach the document against the relevant row below.' }}
                <span v-if="rowRequirement.satisfied">(uploaded)</span>
            </div>
            <FileUploader v-for="s in slots" :key="s.type.code" :application-uuid="uuid" :type="s.type"
                :documents="payload.documents" :required="s.required" :instructions="s.instructions"
                :single="s.type.code === 'photo'" :disabled="readonly" @changed="setDocuments" />
            <p v-if="!slots.length && !rowRequirement" class="text-muted small mb-0">No documents for this section.</p>
        </div>
    </div>
</template>
