<script setup lang="ts">
/** Links between the System maintenance screens, shown only where the user has the permission. */
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { useAuth } from '@/stores/auth';

const auth = useAuth();
const route = useRoute();
const links = computed(() => [
    { to: '/admin/maintenance', label: 'Overview', icon: 'speedometer2', show: true, exact: true },
    { to: '/admin/maintenance/backups', label: 'Database backups', icon: 'database', show: ['backups.manage', 'backups.restore', 'backups.download'].some((p) => auth.can(p)) },
    { to: '/admin/maintenance/logs', label: 'Application logs', icon: 'file-earmark-text', show: auth.can('logs.view') },
].filter((l) => l.show));
const active = (l: { to: string; exact?: boolean }) => (l.exact ? route.path === l.to : route.path.startsWith(l.to));
</script>

<template>
    <nav aria-label="System maintenance">
        <ul class="nav nav-tabs mb-3">
            <li v-for="l in links" :key="l.to" class="nav-item">
                <router-link :to="l.to" class="nav-link" :class="{ active: active(l) }" :aria-current="active(l) ? 'page' : undefined"><i class="bi me-1" :class="`bi-${l.icon}`" aria-hidden="true"></i>{{ l.label }}</router-link>
            </li>
        </ul>
    </nav>
</template>
