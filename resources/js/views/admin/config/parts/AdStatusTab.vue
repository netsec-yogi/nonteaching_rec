<script setup lang="ts">
/** Tab 2: lifecycle status, publish checklist and allowed transitions. */
import { ref } from 'vue';
import { api } from '@/api/client';
import { useForm } from '@/composables/useForm';
import BaseModal from '@/components/BaseModal.vue';
import FieldError from '@/components/FieldError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { formatDateTime, humanize } from '@/utils/format';
import type { AdvertisementDetail } from './types';

const props = defineProps<{ ad: AdvertisementDetail }>();
const emit = defineEmits<{ (e: 'updated', ad: AdvertisementDetail): void }>();
const form = useForm();
const target = ref<string | null>(null);
const reason = ref('');

const verbs: Record<string, string> = { published: 'Publish', suspended: 'Suspend', closed: 'Close', archived: 'Archive' };
const effects: Record<string, string> = {
    published: 'The advertisement becomes visible on the public portal and candidates can apply between the opening and closing dates.',
    suspended: 'The advertisement stays visible but no new applications or submissions are accepted until it is published again.',
    closed: 'No further applications are accepted. Scrutiny and committee work continue.',
    archived: 'The advertisement becomes read-only. This cannot be undone.',
};
const btn: Record<string, string> = { published: 'btn-success', suspended: 'btn-warning', closed: 'btn-secondary', archived: 'btn-dark' };

function start(status: string) { target.value = status; reason.value = ''; form.clear(); }

async function confirm() {
    if (!target.value) return;
    const res = await form.run(() => api.post(`/admin/advertisements/${props.ad.id}/status`, { status: target.value, reason: reason.value || null }),
        `Advertisement ${humanize(target.value).toLowerCase()}.`);
    if (res) { target.value = null; emit('updated', res.data as AdvertisementDetail); }
}
</script>

<template>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card section-card">
                <div class="card-header">Current status</div>
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <StatusBadge :value="ad.status" class="fs-6" />
                        <span v-if="ad.published_at" class="small text-muted">First published {{ formatDateTime(ad.published_at) }}</span>
                        <span v-if="ad.archived_at" class="small text-muted">Archived {{ formatDateTime(ad.archived_at) }}</span>
                    </div>

                    <template v-if="ad.status === 'draft'">
                        <div v-if="ad.publish_problems.length" class="alert alert-warning">
                            <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Before this advertisement can be published:</div>
                            <ul class="mb-0 small"><li v-for="p in ad.publish_problems" :key="p">{{ p }}</li></ul>
                        </div>
                        <div v-else class="alert alert-success small"><i class="bi bi-check-circle me-1"></i>All publishing checks pass.</div>
                    </template>

                    <div v-if="ad.allowed_transitions.length" class="d-flex flex-wrap gap-2">
                        <button v-for="t in ad.allowed_transitions" :key="t" type="button" class="btn" :class="btn[t] ?? 'btn-outline-primary'"
                                :disabled="t === 'published' && ad.status === 'draft' && ad.publish_problems.length > 0" @click="start(t)">
                            {{ verbs[t] ?? humanize(t) }}
                        </button>
                    </div>
                    <p v-else class="text-muted small mb-0">No further status changes are possible.</p>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card section-card">
                <div class="card-header">Applications</div>
                <ul class="list-group list-group-flush">
                    <li v-for="(count, status) in ad.application_counts" :key="status" class="list-group-item d-flex justify-content-between">
                        <StatusBadge :value="String(status)" /><span class="fw-semibold">{{ count }}</span>
                    </li>
                    <li v-if="!Object.keys(ad.application_counts ?? {}).length" class="list-group-item small text-muted">No applications yet.</li>
                </ul>
            </div>
        </div>

        <BaseModal v-if="target" :title="`${verbs[target] ?? humanize(target)} advertisement`" @close="target = null">
            <p>{{ effects[target] }}</p>
            <label class="form-label" for="tr-reason">Reason / remarks (recorded in the audit trail)</label>
            <textarea id="tr-reason" v-model="reason" rows="3" maxlength="500" class="form-control" :class="{ 'is-invalid': form.errors.reason }"></textarea>
            <FieldError :errors="form.errors" name="reason" />
            <div v-for="m in form.errors.status ?? []" :key="m" class="text-danger small mt-1"><i class="bi bi-exclamation-circle me-1"></i>{{ m }}</div>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="target = null">Cancel</button>
                <button type="button" class="btn" :class="btn[target] ?? 'btn-primary'" :disabled="form.busy.value" @click="confirm">
                    {{ verbs[target] ?? humanize(target) }}
                </button>
            </template>
        </BaseModal>
    </div>
</template>
