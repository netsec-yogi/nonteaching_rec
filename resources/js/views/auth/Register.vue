<script setup lang="ts">
import { reactive } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '@/stores/auth';
import { useForm } from '@/composables/useForm';
import FieldError from '@/components/FieldError.vue';
import PasswordInput from '@/views/public/parts/PasswordInput.vue';
import PasswordRules from '@/views/public/parts/PasswordRules.vue';

const auth = useAuth();
const router = useRouter();
const { busy, errors, message, run } = useForm();

const form = reactive({
    name: '', email: '', mobile: '', password: '', password_confirmation: '', accept_privacy: false,
});

async function submit() {
    const user = await run(() => auth.register({ ...form, name: form.name.trim(), email: form.email.trim(), mobile: form.mobile.trim() }));
    if (user) router.replace({ path: '/dashboard', query: { registered: '1' } });
}
</script>

<template>
    <div>
        <div>
            <h1 class="guest-title">Create an applicant account</h1>
            <p class="text-muted">One account is enough to apply for any number of posts. Use an email address and mobile number that you check regularly — all communication about your application will be sent there.</p>
            <div class="mt-3">
                <div>
                    <form novalidate @submit.prevent="submit">
                        <div v-if="message && !Object.keys(errors).length" class="alert alert-danger small" role="alert">{{ message }}</div>
                        <div v-else-if="Object.keys(errors).length" class="alert alert-danger small" role="alert">Please correct the highlighted fields.</div>

                        <div class="mb-3">
                            <label for="reg-name" class="form-label required">Full name</label>
                            <input id="reg-name" v-model="form.name" type="text" class="form-control" :class="{ 'is-invalid': errors.name }" autocomplete="name" maxlength="200" required aria-describedby="reg-name-help">
                            <div id="reg-name-help" class="form-text">As it appears in your matriculation certificate. Letters, spaces, full stops, apostrophes and hyphens only.</div>
                            <FieldError :errors="errors" name="name" />
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-7">
                                <label for="reg-email" class="form-label required">Email address</label>
                                <input id="reg-email" v-model="form.email" type="email" class="form-control" :class="{ 'is-invalid': errors.email }" autocomplete="email" inputmode="email" maxlength="255" required>
                                <FieldError :errors="errors" name="email" />
                                <div v-if="errors.email?.[0]?.includes('already exists')" class="small mt-1">
                                    <router-link to="/login">Sign in</router-link> · <router-link to="/forgot-password">Reset password</router-link>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <label for="reg-mobile" class="form-label required">Mobile number</label>
                                <div class="input-group" :class="{ 'has-validation': errors.mobile }">
                                    <span class="input-group-text">+91</span>
                                    <input id="reg-mobile" v-model="form.mobile" type="tel" class="form-control" :class="{ 'is-invalid': errors.mobile }" autocomplete="tel-national" inputmode="numeric" maxlength="14" placeholder="10-digit number" required>
                                </div>
                                <FieldError :errors="errors" name="mobile" />
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="reg-password" class="form-label required">Password</label>
                                <PasswordInput id="reg-password" v-model="form.password" autocomplete="new-password" :invalid="!!errors.password" describedby="reg-password-rules" />
                                <FieldError :errors="errors" name="password" />
                            </div>
                            <div class="col-md-6">
                                <label for="reg-password2" class="form-label required">Confirm password</label>
                                <PasswordInput id="reg-password2" v-model="form.password_confirmation" autocomplete="new-password" :invalid="!!errors.password_confirmation" />
                                <FieldError :errors="errors" name="password_confirmation" />
                            </div>
                            <div class="col-12 mt-0">
                                <PasswordRules id="reg-password-rules" :password="form.password" :confirmation="form.password_confirmation" />
                            </div>
                        </div>

                        <div class="form-check mb-3">
                            <input id="reg-privacy" v-model="form.accept_privacy" class="form-check-input" :class="{ 'is-invalid': errors.accept_privacy }" type="checkbox" required>
                            <label class="form-check-label" for="reg-privacy">
                                I have read the <router-link to="/privacy" target="_blank">privacy notice</router-link> and agree to my information being used to process my applications.
                            </label>
                            <FieldError :errors="errors" name="accept_privacy" />
                        </div>

                        <button type="submit" class="btn btn-primary w-100" :disabled="busy">
                            <span v-if="busy" class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                            Create account
                        </button>
                    </form>
                </div>
                <div class="small text-muted mt-4">
                    Already registered? <router-link to="/login">Sign in</router-link>
                </div>
            </div>
        </div>
    </div>
</template>
