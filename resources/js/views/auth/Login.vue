<script setup lang="ts">
import { computed, reactive } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from '@/stores/auth';
import { useForm } from '@/composables/useForm';
import FieldError from '@/components/FieldError.vue';
import PasswordInput from '@/views/public/parts/PasswordInput.vue';

const auth = useAuth();
const route = useRoute();
const router = useRouter();
const { busy, errors, message, run } = useForm();

const form = reactive({ email: '', password: '', remember: false });

/** Only same-origin paths are honoured, to avoid open redirects. */
const redirect = computed(() => {
    const r = route.query.redirect;
    return typeof r === 'string' && r.startsWith('/') && !r.startsWith('//') ? r : null;
});
const resetDone = computed(() => route.query.reset === '1');

async function submit() {
    const user = await run(() => auth.login(form.email.trim(), form.password, form.remember));
    if (!user) {
        form.password = '';
        return;
    }
    router.replace(redirect.value ?? auth.homeRoute());
}
</script>

<template>
    <div>
        <div>
            <h1 class="guest-title">Sign in</h1>
            <p class="guest-subtitle">Use the email address you registered with.</p>
            <div v-if="resetDone" class="alert alert-success small" role="status">Your password has been reset. Sign in with your new password.</div>
            <div v-if="redirect && !resetDone" class="alert alert-info small" role="status">Please sign in to continue.</div>
            <div class="mt-3">
                <div>
                    <form novalidate @submit.prevent="submit">
                        <div v-if="message && !Object.keys(errors).length" class="alert alert-danger small" role="alert">{{ message }}</div>
                        <div class="mb-3">
                            <label for="login-email" class="form-label">Email address</label>
                            <input
                                id="login-email"
                                v-model="form.email"
                                type="email"
                                class="form-control"
                                :class="{ 'is-invalid': errors.email }"
                                autocomplete="username"
                                inputmode="email"
                                required
                                autofocus
                                :aria-invalid="!!errors.email || undefined"
                                aria-describedby="login-email-error"
                            >
                            <div id="login-email-error"><FieldError :errors="errors" name="email" /></div>
                        </div>
                        <div class="mb-3">
                            <label for="login-password" class="form-label">Password</label>
                            <PasswordInput id="login-password" v-model="form.password" autocomplete="current-password" :invalid="!!errors.password" />
                            <FieldError :errors="errors" name="password" />
                        </div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div class="form-check mb-0">
                                <input id="login-remember" v-model="form.remember" class="form-check-input" type="checkbox">
                                <label class="form-check-label" for="login-remember">Keep me signed in</label>
                            </div>
                            <router-link to="/forgot-password" class="small fw-semibold">Forgot password?</router-link>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" :disabled="busy">
                            <span v-if="busy" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                            Sign in
                        </button>
                    </form>
                </div>
                <div class="small text-muted mt-4">
                    New applicant? <router-link to="/register">Create an account</router-link>
                </div>
            </div>
            <p class="small text-muted mt-3">Do not use "Keep me signed in" on a shared or public computer.</p>
        </div>
    </div>
</template>
