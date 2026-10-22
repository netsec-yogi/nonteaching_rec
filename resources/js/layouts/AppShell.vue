<script setup lang="ts">
/**
 * Application shell in the structure of the Hostel Management AppLayout:
 * white sidebar with a deep-navy brand strip and sectioned navigation, a
 * mobile drawer, and a sticky translucent top bar with the page title,
 * notifications and the user menu. Staff and applicants pass their own menus.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import { useAuth } from '@/stores/auth';
import { formatDateTime } from '@/utils/format';
import ThemeToggle from '@/components/ThemeToggle.vue';

export interface ShellItem { to: string; label: string; icon: string; exact?: boolean }
export interface ShellSection { title?: string; items: ShellItem[] }

const props = defineProps<{ sections: ShellSection[]; appName: string; homeTo: string; narrow?: boolean }>();

const auth = useAuth();
const route = useRoute();
const router = useRouter();

const drawer = ref(false);
const menu = ref(false);
const bell = ref(false);
watch(() => route.fullPath, () => { drawer.value = false; menu.value = false; bell.value = false; });

const isActive = (item: ShellItem) => (item.exact ? route.path === item.to : route.path === item.to || route.path.startsWith(item.to + '/'));

/** Page title: the route's own title, else the menu entry the page belongs to. */
const title = computed(() => {
    if (typeof route.meta.title === 'string') return route.meta.title;
    const items = props.sections.flatMap((s) => s.items).filter((i) => isActive(i));
    return items.sort((a, b) => b.to.length - a.to.length)[0]?.label ?? props.appName;
});

const roleLabels: Record<string, string> = {
    super_admin: 'Super Administrator', recruitment_admin: 'Recruitment Administrator', scrutiny_user: 'Scrutiny Officer',
    committee_member: 'Screening Committee', applicant: 'Applicant',
};
const roleLabel = computed(() => roleLabels[auth.user?.roles[0] ?? ''] ?? '');
const initials = computed(() => (auth.user?.name ?? '').split(/\s+/).filter((p) => /^[A-Za-z]/.test(p) && !/^(dr|prof|shri|smt|mr|mrs|ms)\.?$/i.test(p)).map((p) => p[0]).slice(0, 2).join('').toUpperCase());

interface Notice { id: string; data: { title?: string; message?: string; link?: string | null }; read_at: string | null; created_at: string }
const notices = ref<Notice[]>([]);
const unread = computed(() => notices.value.filter((n) => !n.read_at).length);
async function loadNotices() {
    try { notices.value = (await api.get<Notice[]>('/notifications')).data.slice(0, 15); } catch { /* the bell is optional */ }
}
async function openNotice(n: Notice) {
    if (!n.read_at) { api.post('/notifications/read', { id: n.id }).catch(() => {}); n.read_at = new Date().toISOString(); }
    bell.value = false;
    if (n.data.link) router.push(n.data.link);
}
async function markAllRead() {
    await api.post('/notifications/read').catch(() => {});
    notices.value.forEach((n) => (n.read_at ??= new Date().toISOString()));
}
onMounted(loadNotices);
watch(bell, (open) => { if (open) loadNotices(); });

async function logout() {
    menu.value = false;
    await auth.logout();
    router.push('/login');
}
</script>

<template>
    <a href="#main" class="visually-hidden-focusable position-fixed top-0 start-0 m-2 btn btn-secondary" style="z-index: 1070">Skip to content</a>
    <div class="shell">
        <div v-if="drawer" class="shell-scrim" aria-hidden="true" @click="drawer = false"></div>
        <aside class="shell-sidebar" :class="{ open: drawer }" aria-label="Main navigation">
            <div class="shell-brand">
                <router-link :to="homeTo" class="d-flex align-items-center gap-2 min-w-0 text-truncate">
                    <span class="shell-brand-mark"><img src="/images/institute-logo.svg" alt=""></span>
                    <span class="min-w-0">
                        <span class="shell-brand-name d-block text-truncate">{{ appName }}</span>
                        <span class="shell-brand-sub d-block text-truncate">ABV-IIITM Gwalior</span>
                    </span>
                </router-link>
                <button type="button" class="btn btn-link ms-auto p-1 text-white-50 d-lg-none" aria-label="Close menu" @click="drawer = false"><i class="bi bi-x-lg"></i></button>
            </div>
            <nav class="shell-nav">
                <div v-for="section in sections" :key="section.title ?? 'main'" class="shell-nav-group">
                    <p v-if="section.title" class="shell-nav-title mb-1">{{ section.title }}</p>
                    <router-link
                        v-for="item in section.items" :key="item.to" :to="item.to"
                        class="shell-nav-link" :class="{ active: isActive(item) }" :aria-current="isActive(item) ? 'page' : undefined"
                        active-class="" exact-active-class=""
                    >
                        <i class="bi" :class="`bi-${item.icon}`" aria-hidden="true"></i>
                        <span class="flex-grow-1 text-truncate">{{ item.label }}</span>
                    </router-link>
                </div>
            </nav>
            <p class="shell-foot mb-0">Atal Bihari Vajpayee Indian Institute of Information Technology and Management, Gwalior</p>
        </aside>

        <div class="shell-body">
            <header class="shell-topbar">
                <button type="button" class="shell-icon-button shell-menu-button" aria-label="Open menu" @click="drawer = true"><i class="bi bi-list"></i></button>
                <h1 class="shell-topbar-title">{{ title }}</h1>
                <div class="ms-auto d-flex align-items-center gap-1">
                    <slot name="topbar" />
                    <ThemeToggle />
                    <div class="position-relative">
                        <button type="button" class="shell-icon-button" :aria-label="`Notifications (${unread} unread)`" :aria-expanded="bell" @click="bell = !bell">
                            <i class="bi bi-bell"></i>
                            <span v-if="unread" class="shell-dot">{{ unread > 99 ? '99+' : unread }}</span>
                        </button>
                        <template v-if="bell">
                            <div class="position-fixed top-0 start-0 w-100 h-100" style="z-index: 1055" aria-hidden="true" @click="bell = false"></div>
                            <div class="shell-menu" style="width: 22rem; max-width: calc(100vw - 1.5rem)" role="menu">
                                <div class="d-flex align-items-center justify-content-between px-3 pt-1 pb-2">
                                    <span class="fw-semibold small text-body">Notifications</span>
                                    <button v-if="unread" type="button" class="btn btn-link btn-sm p-0" @click="markAllRead">Mark all read</button>
                                </div>
                                <div style="max-height: 22rem; overflow-y: auto">
                                    <button v-for="n in notices" :key="n.id" type="button" class="dropdown-item align-items-start" @click="openNotice(n)">
                                        <span class="mt-1 rounded-circle flex-shrink-0" :style="{ width: '.5rem', height: '.5rem', background: n.read_at ? 'transparent' : 'var(--accent-500)' }"></span>
                                        <span class="min-w-0">
                                            <span class="d-block fw-semibold text-body">{{ n.data.title }}</span>
                                            <span class="d-block text-muted text-wrap" style="font-size: .75rem">{{ n.data.message }}</span>
                                            <span class="d-block text-muted" style="font-size: .6875rem">{{ formatDateTime(n.created_at) }}</span>
                                        </span>
                                    </button>
                                    <p v-if="!notices.length" class="text-muted small text-center py-3 mb-0">You're all caught up.</p>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div class="position-relative">
                        <button type="button" class="shell-user" :aria-expanded="menu" aria-haspopup="menu" @click="menu = !menu">
                            <span class="shell-avatar">{{ initials || '?' }}</span>
                            <span class="d-none d-sm-block">
                                <span class="shell-user-name">{{ auth.user?.name }}</span>
                                <span class="shell-user-role">{{ roleLabel }}</span>
                            </span>
                            <i class="bi bi-chevron-down small text-muted"></i>
                        </button>
                        <template v-if="menu">
                            <div class="position-fixed top-0 start-0 w-100 h-100" style="z-index: 1055" aria-hidden="true" @click="menu = false"></div>
                            <div class="shell-menu" role="menu">
                                <p class="text-truncate text-muted px-3 pt-1 pb-2 mb-0" style="font-size: .75rem">{{ auth.user?.email }}</p>
                                <router-link to="/account" class="dropdown-item" role="menuitem"><i class="bi bi-key"></i>Account &amp; password</router-link>
                                <button type="button" class="dropdown-item" role="menuitem" @click="logout"><i class="bi bi-box-arrow-right"></i>Sign out</button>
                            </div>
                        </template>
                    </div>
                </div>
            </header>
            <main id="main" :key="route.path" class="shell-main animate-fade-up" :class="{ 'shell-main-narrow': narrow }">
                <slot />
            </main>
        </div>
    </div>
</template>
