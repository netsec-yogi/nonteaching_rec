<script setup lang="ts">
/** Upload slot for documents attached to one row of a repeatable step. */
import { computed } from 'vue';
import FileUploader from '@/components/FileUploader.vue';
import { ROW_DOCUMENTS, useWizard } from './context';
import type { StepKey } from './types';

const props = defineProps<{ step: StepKey; rowId: number | null; label?: string }>();
const { uuid, payload, readonly, setDocuments } = useWizard();
const config = computed(() => ROW_DOCUMENTS[props.step]);
const type = computed(() => payload.value.document_types.find((t) => t.code === config.value?.type));
</script>

<template>
    <template v-if="config && type">
        <FileUploader v-if="rowId" :application-uuid="uuid" :type="type" :documents="payload.documents"
            :documentable-type="config.documentable" :documentable-id="rowId" :label="label ?? type.name"
            :disabled="readonly" @changed="setDocuments" />
        <div v-else class="small text-muted border rounded px-2 py-2 bg-light">
            <i class="bi bi-info-circle me-1"></i>Save the step to attach documents to this row.
        </div>
    </template>
</template>
