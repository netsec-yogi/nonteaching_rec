<script setup lang="ts">
/** Confirmation dialog for withdrawing a submitted application. */
import { ref } from 'vue';
import { api } from '@/api/client';
import { useForm } from '@/composables/useForm';
import BaseModal from '@/components/BaseModal.vue';
import FieldError from '@/components/FieldError.vue';

const props = defineProps<{ uuid: string; referenceNo: string | null; post: string }>();
const emit = defineEmits<{ (e: 'close'): void; (e: 'withdrawn'): void }>();

const { busy, errors, message, run } = useForm();
const reason = ref('');
const confirmed = ref(false);

async function submit() {
    const ok = await run(() => api.post(`/applications/${props.uuid}/withdraw`, { reason: reason.value.trim() || null, confirm: confirmed.value }), 'Application withdrawn.');
    if (ok) emit('withdrawn');
}
</script>

<template>
    <BaseModal title="Withdraw application" @close="emit('close')">
        <p>You are about to withdraw your application <strong>{{ referenceNo ?? '' }}</strong> for the post of <strong>{{ post }}</strong>.</p>
        <div class="alert alert-warning small">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>A withdrawn application will not be considered further. Withdrawal cannot be reversed.
        </div>
        <div v-if="message && !Object.keys(errors).length" class="alert alert-danger small" role="alert">{{ message }}</div>
        <div class="mb-3">
            <label for="wd-reason" class="form-label">Reason <span class="text-muted small">(optional)</span></label>
            <textarea id="wd-reason" v-model="reason" class="form-control" :class="{ 'is-invalid': errors.reason }" rows="3" maxlength="500"></textarea>
            <div class="form-text text-end">{{ reason.length }} / 500</div>
            <FieldError :errors="errors" name="reason" />
        </div>
        <div class="form-check">
            <input id="wd-confirm" v-model="confirmed" type="checkbox" class="form-check-input" :class="{ 'is-invalid': errors.confirm }">
            <label for="wd-confirm" class="form-check-label">I understand that withdrawal is final and I wish to withdraw this application.</label>
            <FieldError :errors="errors" name="confirm" />
        </div>
        <template #footer>
            <button type="button" class="btn btn-outline-secondary" @click="emit('close')">Cancel</button>
            <button type="button" class="btn btn-danger" :disabled="busy || !confirmed" @click="submit">
                <span v-if="busy" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Withdraw application
            </button>
        </template>
    </BaseModal>
</template>
