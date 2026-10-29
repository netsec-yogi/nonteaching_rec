<script setup lang="ts">
/**
 * Public and applicant pages. A signed-in applicant gets ApplicantShell (top navigation);
 * visitors and staff browsing public pages get the public top bar.
 */
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { useAuth } from '@/stores/auth';
import ApplicantShell from './ApplicantShell.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';

const auth = useAuth();
const route = useRoute();
const asApplicant = computed(() => auth.isApplicant);
</script>

<template>
    <ApplicantShell v-if="asApplicant">
        <div class="applicant-container"><router-view /></div>
    </ApplicantShell>

    <div v-else class="d-flex flex-column min-vh-100">
        <a href="#main" class="visually-hidden-focusable position-fixed top-0 start-0 m-2 btn btn-secondary" style="z-index: 1070">Skip to content</a>
        <header class="public-header">
            <div class="container public-header-inner">
                <router-link to="/" class="public-brand">
                    <span class="public-brand-mark"><img src="/images/institute-logo.svg" alt="ABV-IIITM Gwalior"></span>
                    <span class="min-w-0">
                        <span class="public-brand-name d-block text-truncate">Non-Teaching Recruitment Portal</span>
                        <span class="public-brand-sub d-block text-truncate">ABV-IIITM Gwalior</span>
                    </span>
                </router-link>
                <nav class="ms-auto d-flex align-items-center gap-1">
                    <ThemeToggle />
                    <router-link class="public-nav-link d-none d-sm-inline" to="/">Advertisements</router-link>
                    <template v-if="auth.user">
                        <router-link class="btn btn-primary btn-sm" :to="auth.homeRoute()"><i class="bi bi-grid"></i>Staff console</router-link>
                    </template>
                    <template v-else>
                        <router-link class="public-nav-link" :to="{ path: '/login', query: route.path === '/' ? {} : { redirect: route.fullPath } }">Sign in</router-link>
                        <router-link class="btn btn-primary btn-sm" to="/register">Register</router-link>
                    </template>
                </nav>
            </div>
        </header>
        <main id="main" tabindex="-1" class="flex-grow-1 py-4 animate-fade-up" :key="route.path">
            <div class="container"><router-view /></div>
        </main>
        <footer class="public-footer py-3">
            <div class="container d-flex flex-wrap justify-content-between gap-2">
                <span>Atal Bihari Vajpayee Indian Institute of Information Technology and Management, Gwalior · Morena Link Road, Gwalior – 474015</span>
                <router-link to="/privacy">Privacy notice</router-link>
            </div>
        </footer>
    </div>
</template>
