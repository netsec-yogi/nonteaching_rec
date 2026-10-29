<script setup lang="ts">
/**
 * Staff application shell in the structure of the Hostel Management AppLayout:
 * white sidebar with a deep-navy brand strip and sectioned navigation, a
 * mobile drawer, and a sticky translucent top bar with the page title,
 * notifications and the user menu. (Applicants use ApplicantShell's top navigation.)
 */
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import ThemeToggle from '@/components/ThemeToggle.vue';
import NotificationBell from '@/components/shell/NotificationBell.vue';
import UserMenu from '@/components/shell/UserMenu.vue';

export interface ShellItem { to: string; label: string; icon: string; exact?: boolean }
export interface ShellSection { title?: string; items: ShellItem[] }

const props = defineProps<{ sections: ShellSection[]; appName: string; homeTo: string; narrow?: boolean }>();

const route = useRoute();
const drawer = ref(false);
watch(() => route.fullPath, () => { drawer.value = false; });

const isActive = (item: ShellItem) => (item.exact ? route.path === item.to : route.path === item.to || route.path.startsWith(item.to + '/'));

/** Page title: the route's own title, else the menu entry the page belongs to. */
const title = computed(() => {
    if (typeof route.meta.title === 'string') return route.meta.title;
    const items = props.sections.flatMap((s) => s.items).filter((i) => isActive(i));
    return items.sort((a, b) => b.to.length - a.to.length)[0]?.label ?? props.appName;
});
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
                <button type="button" class="btn btn-ghost ms-auto p-1 d-lg-none" aria-label="Close menu" @click="drawer = false"><i class="bi bi-x-lg"></i></button>
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
                    <NotificationBell />
                    <UserMenu />
                </div>
            </header>
            <main id="main" tabindex="-1" :key="route.path" class="shell-main animate-fade-up" :class="{ 'shell-main-narrow': narrow }">
                <slot />
            </main>
        </div>
    </div>
</template>
