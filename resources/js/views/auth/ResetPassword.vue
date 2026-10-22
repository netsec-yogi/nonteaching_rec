<script setup lang="ts">
import { reactive } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, ensureCsrf } from '@/api/client';
import { useAuth } from '@/stores/auth';
import { useForm } from '@/composables/useForm';
import FieldError from '@/components/FieldError.vue';
import PasswordInput from '@/views/public/parts/PasswordInput.vue';
import PasswordRules from '@/views/public/parts/PasswordRules.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
const { busy, errors, message, run } = useForm();

const q = (v: unknown) => (typeof v === 'string' ? v : '');
const form = reactive({
    token: q(route.query.token),
    email: q(route.query.email),
    password: '',
    password_confirmation: '',
});

async function submit() {
    const res = await run(async () => {
        await ensureCsrf();
        const { data } = await api.post<{ message: string }>('/auth/reset-password', form);
        return data;
    }, 'Your password has been reset.');
    if (!res) return;
    // Resetting invalidates existing sessions on the server; sign in again.
    if (auth.user) await auth.logout().catch(() => undefined);
    router.replace({ path: '/login', query: { reset: '1' } });
}
</script>

<template>
    <div>
        <div>
            <h1 class="guest-title">Set a new password</h1>
            <div v-if="!form.token" class="alert alert-warning">
                This reset link is incomplete or invalid. Please use the link from the email exactly as sent, or
                <router-link to="/forgot-password" class="alert-link">request a new link</router-link>.
            </div>
            <div v-else class="mt-3">
                <div>
                    <form novalidate @submit.prevent="submit">
                        <div v-if="message && !Object.keys(errors).length" class="alert alert-danger small" role="alert">{{ message }}</div>
                        <div class="mb-3">
                            <label for="rp-email" class="form-label">Email address</label>
                            <input id="rp-email" v-model="form.email" type="email" class="form-control" :class="{ 'is-invalid': errors.email }" autocomplete="username" inputmode="email" required>
                            <FieldError :errors="errors" name="email" />
                            <div v-if="errors.email" class="small mt-1"><router-link to="/forgot-password">Request a new reset link</router-link></div>
                        </div>
                        <div class="mb-3">
                            <label for="rp-password" class="form-label">New password</label>
                            <PasswordInput id="rp-password" v-model="form.password" autocomplete="new-password" :invalid="!!errors.password" describedby="rp-rules" />
                            <FieldError :errors="errors" name="password" />
                        </div>
                        <div class="mb-3">
                            <label for="rp-password2" class="form-label">Confirm new password</label>
                            <PasswordInput id="rp-password2" v-model="form.password_confirmation" autocomplete="new-password" />
                            <PasswordRules id="rp-rules" :password="form.password" :confirmation="form.password_confirmation" />
                        </div>
                        <FieldError :errors="errors" name="token" />
                        <button type="submit" class="btn btn-primary w-100" :disabled="busy">
                            <span v-if="busy" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                            Reset password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
