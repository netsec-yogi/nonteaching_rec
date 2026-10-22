<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { api } from '@/api/client';
import { useAuth } from '@/stores/auth';
import { useForm } from '@/composables/useForm';
import FieldError from '@/components/FieldError.vue';
import PasswordInput from '@/views/public/parts/PasswordInput.vue';
import PasswordRules from '@/views/public/parts/PasswordRules.vue';
import { humanize } from '@/utils/format';

const auth = useAuth();
const user = computed(() => auth.user);
const pw = useForm();
const resend = useForm();
const resent = ref(false);

const form = reactive({ current_password: '', password: '', password_confirmation: '' });
const changed = ref(false);

async function changePassword() {
    changed.value = false;
    const ok = await pw.run(() => api.post('/auth/password', form), 'Password changed.');
    if (ok) {
        form.current_password = form.password = form.password_confirmation = '';
        changed.value = true;
    }
}

async function resendVerification() {
    const ok = await resend.run(() => api.post('/auth/email/resend'), 'Verification email sent.');
    if (ok) resent.value = true;
}
</script>

<template>
    <div v-if="user">
        <h1 class="page-title h3 mb-3">Account</h1>
        <div class="row g-3">
            <div class="col-lg-5">
                <section class="card section-card">
                    <h2 class="card-header h6 mb-0">Profile</h2>
                    <div class="card-body">
                        <table class="table table-sm kv-table mb-0">
                            <tbody>
                                <tr><th scope="row">Name</th><td class="fw-semibold">{{ user.name }}</td></tr>
                                <tr>
                                    <th scope="row">Email</th>
                                    <td>
                                        {{ user.email }}
                                        <span v-if="user.email_verified" class="badge text-bg-success ms-1"><i class="bi bi-patch-check me-1" aria-hidden="true"></i>Verified</span>
                                        <span v-else class="badge text-bg-warning ms-1">Not verified</span>
                                    </td>
                                </tr>
                                <tr><th scope="row">Mobile</th><td>{{ user.mobile || '—' }}</td></tr>
                                <tr v-if="user.designation"><th scope="row">Designation</th><td>{{ user.designation }}</td></tr>
                                <tr><th scope="row">Account type</th><td>{{ user.roles.map((r) => humanize(r)).join(', ') || '—' }}</td></tr>
                            </tbody>
                        </table>
                        <div v-if="!user.email_verified" class="alert alert-warning small mt-3 mb-0">
                            <p class="mb-2">Your email address has not been verified. Open the link in the verification email we sent you.</p>
                            <p v-if="resent" class="mb-0 text-success" role="status"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>A new verification link has been sent to {{ user.email }}.</p>
                            <button v-else type="button" class="btn btn-sm btn-outline-dark" :disabled="resend.busy.value" @click="resendVerification">
                                <span v-if="resend.busy.value" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                Resend verification email
                            </button>
                        </div>
                        <p class="small text-muted mt-3 mb-0">
                            <template v-if="auth.isApplicant">Your name, email and mobile number cannot be changed here. If they are incorrect, contact the Recruitment Section.</template>
                            <template v-else>Contact the portal administrator to change your profile details.</template>
                        </p>
                    </div>
                </section>
            </div>

            <div class="col-lg-7">
                <section class="card section-card">
                    <h2 class="card-header h6 mb-0">Change password</h2>
                    <div class="card-body">
                        <div v-if="changed" class="alert alert-success small" role="status">Your password has been changed. Other signed-in sessions have been signed out.</div>
                        <form novalidate @submit.prevent="changePassword">
                            <input type="text" class="d-none" autocomplete="username" :value="user.email" readonly aria-hidden="true" tabindex="-1">
                            <div class="mb-3">
                                <label for="acc-current" class="form-label">Current password</label>
                                <PasswordInput id="acc-current" v-model="form.current_password" autocomplete="current-password" :invalid="!!pw.errors.current_password" />
                                <FieldError :errors="pw.errors" name="current_password" />
                            </div>
                            <div class="row g-3 mb-2">
                                <div class="col-md-6">
                                    <label for="acc-new" class="form-label">New password</label>
                                    <PasswordInput id="acc-new" v-model="form.password" autocomplete="new-password" :invalid="!!pw.errors.password" describedby="acc-rules" />
                                    <FieldError :errors="pw.errors" name="password" />
                                </div>
                                <div class="col-md-6">
                                    <label for="acc-new2" class="form-label">Confirm new password</label>
                                    <PasswordInput id="acc-new2" v-model="form.password_confirmation" autocomplete="new-password" />
                                </div>
                            </div>
                            <PasswordRules id="acc-rules" :password="form.password" :confirmation="form.password_confirmation" />
                            <button type="submit" class="btn btn-primary mt-3" :disabled="pw.busy.value">
                                <span v-if="pw.busy.value" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                Change password
                            </button>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
