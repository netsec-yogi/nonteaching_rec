<script setup lang="ts">
/** The check list (page 5): candidate marks each enclosure ✓ / X / NA. */
import FieldError from '@/components/FieldError.vue';
import { useWizard } from '../context';
import StepUploads from '../StepUploads.vue';
import type { ChecklistData, ChecklistEntry } from '../types';

defineProps<{ form: ChecklistData }>();
const { payload, errors } = useWizard();
const choices: { value: ChecklistEntry['candidate_status']; label: string; title: string }[] = [
    { value: 'yes', label: '✓', title: 'Enclosed' },
    { value: 'no', label: 'X', title: 'Not enclosed' },
    { value: 'na', label: 'NA', title: 'Not applicable' },
];
const docLabel = (id: number) => {
    const d = payload.value.documents.find((x) => x.id === id);
    return d ? `${d.document_type_name ?? 'Document'} — ${d.original_name}` : '';
};
/** Documents of the item's type first, then the rest. */
const docsFor = (e: ChecklistEntry) => {
    const docs = payload.value.documents;
    return [...docs.filter((d) => d.document_type === e.document_type_code), ...docs.filter((d) => d.document_type !== e.document_type_code)];
};
const k = (i: number, f: string) => `entries.${i}.${f}`;
</script>

<template>
    <div class="vstack gap-3">
        <div class="card section-card">
            <div class="card-header">Check List</div>
            <div class="card-body pb-0">
                <p class="small text-muted mb-2">
                    Mark each item as <strong>✓</strong> (enclosed / uploaded), <strong>X</strong> (not enclosed) or <strong>NA</strong> (not applicable).
                    Optionally give the page reference and link the uploaded document.
                </p>
                <FieldError :errors="errors" name="entries" />
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr><th style="width: 3rem">S.No.</th><th>Item</th><th class="text-center" style="min-width: 10rem">Status</th><th style="min-width: 7rem">Page ref.</th><th style="min-width: 14rem">Uploaded document</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(e, i) in form.entries" :key="e.checklist_item_id">
                            <td>{{ i + 1 }}</td>
                            <td class="small">{{ e.label }}<span v-if="e.is_mandatory" class="text-danger" title="Mandatory item"> *</span>
                                <FieldError :errors="errors" :name="k(i, 'checklist_item_id')" />
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm" role="radiogroup" :aria-label="`Status of item ${i + 1}`">
                                    <template v-for="c in choices" :key="c.value ?? ''">
                                        <input :id="`cl${i}-${c.value}`" v-model="e.candidate_status" type="radio" class="btn-check" :name="`cl${i}`" :value="c.value">
                                        <label class="btn" :class="e.candidate_status === c.value ? (c.value === 'yes' ? 'btn-success' : c.value === 'no' ? 'btn-danger' : 'btn-secondary') : 'btn-outline-secondary'" :for="`cl${i}-${c.value}`" :title="c.title">{{ c.label }}</label>
                                    </template>
                                </div>
                                <FieldError :errors="errors" :name="k(i, 'candidate_status')" />
                            </td>
                            <td>
                                <input v-model="e.page_reference" class="form-control form-control-sm" :class="{ 'is-invalid': errors[k(i, 'page_reference')] }" maxlength="100" :aria-label="`Page reference for item ${i + 1}`">
                                <FieldError :errors="errors" :name="k(i, 'page_reference')" />
                            </td>
                            <td>
                                <select v-model="e.document_id" class="form-select form-select-sm" :class="{ 'is-invalid': errors[k(i, 'document_id')] }" :aria-label="`Document for item ${i + 1}`" :title="e.document_id ? docLabel(e.document_id) : ''">
                                    <option :value="null">—</option>
                                    <option v-for="d in docsFor(e)" :key="d.id" :value="d.id">{{ d.document_type_name ?? 'Document' }} — {{ d.original_name }}</option>
                                </select>
                                <FieldError :errors="errors" :name="k(i, 'document_id')" />
                            </td>
                        </tr>
                        <tr v-if="!form.entries.length"><td colspan="5" class="text-muted">No checklist items are configured for this post.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <StepUploads step="checklist" title="Other documents" :hide="['clarification_document']" />
    </div>
</template>
