<script setup lang="ts">
/**
 * A completed referee recommendation, opened on demand (each view is logged
 * by the server). Confidential: never shown to the applicant.
 */
import { onMounted, ref } from 'vue';
import { api, download, toApiError } from '@/api/client';
import BaseModal from '@/components/BaseModal.vue';
import LoadingState from '@/components/LoadingState.vue';
import { formatDateTime, humanize } from '@/utils/format';
import { useToast } from '@/stores/toast';

interface Answer { code: string; label: string; type: string; options: { min?: number; max?: number; labels?: Record<string, string>; choices?: string[] } | null; answer: string | number | null }
interface Recommendation {
    id: number; reference_no: string; position: number; referee_name: string; referee_designation: string; referee_organization: string;
    referee_email: string; referee_phone: string | null; relationship: string; known_duration: string; nature_of_association: string | null;
    candidate_entered: Record<string, string | number | null> | null; answers: Answer[]; overall_recommendation: string; comments: string | null;
    content_hash: string | null; submitted_at: string;
    amendments: { field: string; original: string | null; amended: string | null; reason: string; by: string | null; at: string | null }[];
}

const props = defineProps<{ applicationUuid: string; responseId: number }>();
const emit = defineEmits<{ (e: 'close'): void }>();
const toast = useToast();

const loading = ref(true);
const error = ref<string | null>(null);
const rec = ref<Recommendation | null>(null);
const base = `/admin/applications/${props.applicationUuid}/recommendations/${props.responseId}`;

onMounted(async () => {
    try {
        rec.value = (await api.get(base)).data;
    } catch (e) {
        error.value = toApiError(e).message;
    } finally {
        loading.value = false;
    }
});

function answerText(a: Answer): string {
    if (a.answer === null || a.answer === '') return '—';
    if (a.type === 'yes_no') return String(a.answer).toLowerCase() === 'yes' ? 'Yes' : 'No';
    if (a.type === 'rating') {
        const lbl = a.options?.labels?.[String(a.answer)];
        return `${a.answer} / ${a.options?.max ?? 5}${lbl ? ' — ' + lbl : ''}`;
    }
    return String(a.answer);
}

function pdf() {
    if (!rec.value) return;
    download(`${base}/pdf`, `Referee-Recommendation-${rec.value.reference_no.replace(/\//g, '-')}.pdf`).catch((e) => toast.error(toApiError(e).message));
}
</script>

<template>
    <BaseModal :title="rec ? `Referee recommendation ${rec.position} — ${rec.reference_no}` : 'Referee recommendation'" size="lg" @close="emit('close')">
        <div class="confidential-banner rounded px-3 py-2 mb-3 small fw-semibold">
            <i class="bi bi-shield-lock me-1"></i>CONFIDENTIAL — for the Recruitment Section and the Screening Committee only. Not to be disclosed to the applicant.
        </div>
        <LoadingState :loading="loading" :error="error">
            <template v-if="rec">
                <table class="table table-sm kv-table small">
                    <tbody>
                        <tr><th>Referee</th><td class="fw-semibold">{{ rec.referee_name }}, {{ rec.referee_designation }}</td></tr>
                        <tr><th>Organisation</th><td>{{ rec.referee_organization }}</td></tr>
                        <tr><th>Contact</th><td>{{ rec.referee_email }}<span v-if="rec.referee_phone"> · {{ rec.referee_phone }}</span></td></tr>
                        <tr><th>Relationship / known for</th><td>{{ rec.relationship }} · {{ rec.known_duration }}</td></tr>
                        <tr v-if="rec.nature_of_association"><th>Nature of association</th><td class="text-pre">{{ rec.nature_of_association }}</td></tr>
                        <tr><th>Submitted</th><td>{{ formatDateTime(rec.submitted_at) }}</td></tr>
                    </tbody>
                </table>

                <h6 class="small text-uppercase text-muted">Assessment</h6>
                <table class="table table-sm small">
                    <tbody>
                        <tr v-for="a in rec.answers" :key="a.code">
                            <td style="width: 60%">{{ a.label }}</td>
                            <td class="fw-semibold text-pre">{{ answerText(a) }}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="border rounded p-2 mb-3">
                    <div class="small text-muted">Overall recommendation</div>
                    <div class="fs-6 fw-bold">{{ rec.overall_recommendation }}</div>
                </div>
                <div v-if="rec.comments" class="mb-3">
                    <h6 class="small text-uppercase text-muted">Additional comments</h6>
                    <div class="small text-pre">{{ rec.comments }}</div>
                </div>

                <div v-if="rec.amendments.length" class="mb-3">
                    <h6 class="small text-uppercase text-muted">Recorded corrections</h6>
                    <ul class="small mb-0">
                        <li v-for="(m, i) in rec.amendments" :key="i">
                            <strong>{{ humanize(m.field) }}</strong>: “{{ m.original ?? '—' }}” → “{{ m.amended ?? '—' }}” — {{ m.reason }} ({{ m.by }}, {{ formatDateTime(m.at) }})
                        </li>
                    </ul>
                </div>

                <details v-if="rec.candidate_entered" class="small">
                    <summary class="text-muted">Referee details as entered by the candidate</summary>
                    <table class="table table-sm kv-table mt-2 mb-0">
                        <tbody>
                            <tr v-for="(v, k) in rec.candidate_entered" :key="k"><th>{{ humanize(String(k)) }}</th><td>{{ v ?? '—' }}</td></tr>
                        </tbody>
                    </table>
                </details>
                <div v-if="rec.content_hash" class="small text-muted mt-2 text-break">Integrity hash: <code>{{ rec.content_hash }}</code></div>
            </template>
        </LoadingState>
        <template #footer>
            <button type="button" class="btn btn-outline-secondary" :disabled="!rec" @click="pdf"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
            <button type="button" class="btn btn-secondary" @click="emit('close')">Close</button>
        </template>
    </BaseModal>
</template>
