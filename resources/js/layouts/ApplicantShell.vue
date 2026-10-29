<script setup lang="ts">
/**
 * Signed-in applicant layout: no sidebar. Institute branding, the main navigation, theme toggle,
 * notifications and the account menu share one sticky header (deep-navy brand strip in the
 * Hostel Management style). Below the xl breakpoint (tablets and phones) the links collapse behind a menu button.
 */
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '@/api/client';
import ThemeToggle from '@/components/ThemeToggle.vue';
import NotificationBell from '@/components/shell/NotificationBell.vue';
import UserMenu from '@/components/shell/UserMenu.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { useAuth } from '@/stores/auth';

interface MyApplication { uuid: string; post: string; post_code: string; advertisement: string; status: string; reference_no: string | null }

const route = useRoute();
const auth = useAuth();
const mobileOpen = ref(false);
const appsOpen = ref(false);
const apps = ref<MyApplication[] | null>(null);
const appsError = ref(false);

watch(() => route.fullPath, () => { mobileOpen.value = false; appsOpen.value = false; });

/** Loaded the first time the list is opened (the dashboard endpoint also carries open advertisements). */
async function loadApps() {
    if (apps.value) return;
    try { apps.value = (await api.get<{ applications: MyApplication[] }>('/applicant/dashboard')).data.applications; } catch { appsError.value = true; }
}
watch([appsOpen, mobileOpen], ([a, m]) => { if (a || m) loadApps(); });

const links = [
    { to: '/dashboard', label: 'Dashboard', icon: 'house', exact: true },
    { to: '/', label: 'Advertisements', icon: 'megaphone', exact: true, also: '/advertisements/' },
];
const isActive = (l: { to: string; exact?: boolean; also?: string }) =>
    (l.exact ? route.path === l.to : route.path.startsWith(l.to)) || (!!l.also && route.path.startsWith(l.also));
const appsActive = computed(() => route.path.startsWith('/applications/'));
const accountActive = computed(() => route.path === '/account');
</script>

<template>
    <a href="#main" class="visually-hidden-focusable position-fixed top-0 start-0 m-2 btn btn-secondary" style="z-index: 1070">Skip to content</a>
    <div class="d-flex flex-column min-vh-100">
        <header class="topnav">
            <div class="topnav-inner">
                <router-link to="/dashboard" class="topnav-brand">
                    <span class="topnav-brand-mark"><img src="/images/institute-logo.svg" alt=""></span>
                    <span class="min-w-0">
                        <span class="topnav-brand-name d-block text-truncate">Non-Teaching Recruitment Portal</span>
                        <span class="topnav-brand-sub d-block text-truncate">ABV-IIITM Gwalior</span>
                    </span>
                </router-link>

                <nav class="topnav-links d-none d-xl-flex" aria-label="Main navigation">
                    <router-link v-for="l in links" :key="l.to" :to="l.to" class="topnav-link" :class="{ active: isActive(l) }"
                        :aria-current="isActive(l) ? 'page' : undefined" active-class="" exact-active-class="">
                        <i class="bi" :class="`bi-${l.icon}`" aria-hidden="true"></i>{{ l.label }}
                    </router-link>
                    <div class="position-relative">
                        <button type="button" class="topnav-link" :class="{ active: appsActive }" :aria-expanded="appsOpen" aria-haspopup="menu" @click="appsOpen = !appsOpen">
                            <i class="bi bi-folder2-open" aria-hidden="true"></i>My applications<i class="bi bi-chevron-down small" aria-hidden="true"></i>
                        </button>
                        <template v-if="appsOpen">
                            <div class="position-fixed top-0 start-0 w-100 h-100" style="z-index: 1055" aria-hidden="true" @click="appsOpen = false"></div>
                            <div class="shell-menu topnav-dropdown" role="menu">
                                <p class="text-muted small px-3 pt-1 pb-1 mb-0">Open an application to see its status, or continue a draft.</p>
                                <div v-if="apps === null && !appsError" class="px-3 py-2 small text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Loading…</div>
                                <div v-else-if="appsError" class="px-3 py-2 small text-muted">Could not load your applications.</div>
                                <p v-else-if="!apps?.length" class="px-3 py-2 small text-muted mb-0">You have not started an application yet.</p>
                                <router-link v-for="a in apps ?? []" :key="a.uuid" :to="`/applications/${a.uuid}`" class="dropdown-item align-items-start" role="menuitem">
                                    <i class="bi bi-file-earmark-text mt-1"></i>
                                    <span class="min-w-0 flex-grow-1">
                                        <span class="d-block text-wrap text-body fw-semibold">{{ a.post }}</span>
                                        <span class="d-block small text-muted">{{ a.reference_no ?? a.advertisement }}</span>
                                    </span>
                                    <StatusBadge :value="a.status" />
                                </router-link>
                                <div class="dropdown-divider"></div>
                                <router-link to="/dashboard" class="dropdown-item" role="menuitem"><i class="bi bi-grid"></i>All applications and status</router-link>
                            </div>
                        </template>
                    </div>
                    <router-link to="/account" class="topnav-link" :class="{ active: accountActive }" :aria-current="accountActive ? 'page' : undefined" active-class="" exact-active-class="">
                        <i class="bi bi-person-circle" aria-hidden="true"></i>Profile
                    </router-link>
                </nav>

                <div class="ms-auto d-flex align-items-center gap-1">
                    <ThemeToggle />
                    <NotificationBell />
                    <UserMenu :extra-links="[{ to: '/privacy', label: 'Privacy notice', icon: 'shield-check' }]" />
                    <button type="button" class="shell-icon-button d-xl-none" :aria-expanded="mobileOpen" aria-controls="topnav-mobile"
                        :aria-label="mobileOpen ? 'Close menu' : 'Open menu'" @click="mobileOpen = !mobileOpen">
                        <i class="bi" :class="mobileOpen ? 'bi-x-lg' : 'bi-list'"></i>
                    </button>
                </div>
            </div>

            <nav v-if="mobileOpen" id="topnav-mobile" class="topnav-mobile d-xl-none" aria-label="Main navigation">
                <router-link v-for="l in links" :key="l.to" :to="l.to" class="topnav-mobile-link" :class="{ active: isActive(l) }" :aria-current="isActive(l) ? 'page' : undefined" active-class="" exact-active-class="">
                    <i class="bi" :class="`bi-${l.icon}`" aria-hidden="true"></i>{{ l.label }}
                </router-link>
                <p class="topnav-mobile-title">My applications</p>
                <router-link v-for="a in apps ?? []" :key="a.uuid" :to="`/applications/${a.uuid}`" class="topnav-mobile-link" :class="{ active: route.path.startsWith(`/applications/${a.uuid}`) }">
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i><span class="flex-grow-1 text-truncate">{{ a.post }}</span><StatusBadge :value="a.status" />
                </router-link>
                <p v-if="apps && !apps.length" class="small text-muted px-3 mb-1">No applications yet.</p>
                <p class="topnav-mobile-title">Account</p>
                <router-link to="/account" class="topnav-mobile-link" :class="{ active: accountActive }"><i class="bi bi-person-circle" aria-hidden="true"></i>Profile &amp; password</router-link>
                <router-link to="/privacy" class="topnav-mobile-link"><i class="bi bi-shield-check" aria-hidden="true"></i>Privacy notice</router-link>
                <button type="button" class="topnav-mobile-link" @click="auth.logout().then(() => $router.push('/login'))"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>Sign out</button>
            </nav>
        </header>

        <main id="main" tabindex="-1" :key="route.path" class="applicant-main animate-fade-up">
            <slot />
        </main>
        <footer class="public-footer py-3">
            <div class="applicant-container d-flex flex-wrap justify-content-between gap-2">
                <span>Atal Bihari Vajpayee Indian Institute of Information Technology and Management, Gwalior · Morena Link Road, Gwalior – 474015</span>
                <router-link to="/privacy">Privacy notice</router-link>
            </div>
        </footer>
    </div>
</template>
