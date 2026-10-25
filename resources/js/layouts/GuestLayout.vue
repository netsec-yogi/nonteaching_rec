<script setup lang="ts">
/** Sign-in, registration and password screens: the hostel GuestLayout split screen. */
import { computed } from 'vue';
import { useRoute } from 'vue-router';

const route = useRoute();
const wide = computed(() => route.path === '/register');
</script>

<template>
    <div class="guest">
        <a href="#main" class="visually-hidden-focusable position-fixed top-0 start-0 m-2 btn btn-secondary" style="z-index: 1070">Skip to content</a>
        <section class="guest-panel">
            <router-link to="/" class="d-flex align-items-center gap-3 text-white text-decoration-none">
                <span class="guest-mark"><img src="/images/institute-logo.svg" alt=""></span>
                <span class="lh-sm">
                    <span class="d-block fw-semibold">Non-Teaching Recruitment Portal</span>
                    <span class="d-block small" style="color: rgb(255 255 255 / .6)">ABV-IIITM Gwalior</span>
                </span>
            </router-link>
            <div class="my-auto" style="max-width: 28rem">
                <h2>Apply, track and complete recruitment in one place.</h2>
                <ul class="guest-points">
                    <li><i class="bi bi-file-earmark-text"></i>Apply online for advertised non-teaching posts, section by section as in the printed form.</li>
                    <li><i class="bi bi-envelope-check"></i>Referees receive a secure link and send their recommendation directly to the Institute.</li>
                    <li><i class="bi bi-clipboard-check"></i>Follow your application from submission through scrutiny, with every step recorded.</li>
                </ul>
            </div>
            <p class="small mb-0" style="color: rgb(255 255 255 / .5)">Atal Bihari Vajpayee Indian Institute of Information Technology and Management, Gwalior</p>
            <div class="guest-glow" aria-hidden="true"></div>
        </section>
        <main id="main" tabindex="-1" class="guest-form" :class="{ 'guest-form-wide': wide }">
            <div class="guest-form-inner animate-fade-up">
                <router-link to="/" class="d-flex d-lg-none align-items-center gap-2 mb-4 text-decoration-none">
                    <span class="guest-mark" style="background: var(--deep-900)"><img src="/images/institute-logo.svg" alt="" style="background: #fff; border-radius: .3rem"></span>
                    <span class="lh-sm">
                        <span class="d-block fw-semibold small text-body">Non-Teaching Recruitment Portal</span>
                        <span class="d-block text-muted" style="font-size: .75rem">ABV-IIITM Gwalior</span>
                    </span>
                </router-link>
                <router-view />
                <p class="mt-4 mb-0 text-center small"><router-link to="/" class="text-muted"><i class="bi bi-arrow-left me-1"></i>Back to advertisements</router-link></p>
            </div>
        </main>
    </div>
</template>
