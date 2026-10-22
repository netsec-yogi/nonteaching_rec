<script setup lang="ts">
import { ref } from 'vue';
import { api, ensureCsrf } from '@/api/client';
import { useForm } from '@/composables/useForm';
import FieldError from '@/components/FieldError.vue';

const { busy, errors, message, run } = useForm();
const email = ref('');
const sent = ref<string | null>(null);

async function submit() {
    const res = await run(async () => {
        await ensureCsrf();
        const { data } = await api.post<{ message: string }>('/auth/forgot-password', { email: email.value.trim() });
        return data;
    });
    if (res) sent.value = res.message;
}
</script>

<template>
    <div>
        <div>
            <h1 class="guest-title">Forgot password</h1>
            <div class="mt-3">
                <div>
                    <div v-if="sent" role="status">
                        <div class="alert alert-success">{{ sent }}</div>
                        <p class="small text-muted">The link is valid for a limited time. If you do not receive the email within a few minutes, check your spam folder or try again.</p>
                        <router-link to="/login" class="btn btn-outline-primary btn-sm">Back to sign in</router-link>
                    </div>
                    <form v-else novalidate @submit.prevent="submit">
                        <p class="small text-muted">Enter the email address you registered with. We will send you a link to set a new password.</p>
                        <div v-if="message && !Object.keys(errors).length" class="alert alert-danger small" role="alert">{{ message }}</div>
                        <div class="mb-3">
                            <label for="fp-email" class="form-label">Email address</label>
                            <input id="fp-email" v-model="email" type="email" class="form-control" :class="{ 'is-invalid': errors.email }" autocomplete="email" inputmode="email" required autofocus>
                            <FieldError :errors="errors" name="email" />
                        </div>
                        <button type="submit" class="btn btn-primary w-100" :disabled="busy">
                            <span v-if="busy" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                            Send reset link
                        </button>
                    </form>
                </div>
                <div class="small text-muted mt-4">
                    <router-link to="/login">Back to sign in</router-link>
                </div>
            </div>
        </div>
    </div>
</template>
