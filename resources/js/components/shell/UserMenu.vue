<script setup lang="ts">
/** Avatar button with the account menu (shared by the staff and applicant shells). */
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from '@/stores/auth';

defineProps<{ extraLinks?: { to: string; label: string; icon: string }[] }>();

const auth = useAuth();
const route = useRoute();
const router = useRouter();
const open = ref(false);
watch(() => route.fullPath, () => { open.value = false; });

const roleLabels: Record<string, string> = {
    super_admin: 'Super Administrator', recruitment_admin: 'Recruitment Administrator', scrutiny_user: 'Scrutiny Officer',
    committee_member: 'Screening Committee', applicant: 'Applicant',
};
const roleLabel = computed(() => roleLabels[auth.user?.roles[0] ?? ''] ?? '');
const initials = computed(() => (auth.user?.name ?? '').split(/\s+/).filter((p) => /^[A-Za-z]/.test(p) && !/^(dr|prof|shri|smt|mr|mrs|ms)\.?$/i.test(p)).map((p) => p[0]).slice(0, 2).join('').toUpperCase());

async function logout() {
    open.value = false;
    await auth.logout();
    router.push('/login');
}
</script>

<template>
    <div class="position-relative">
        <button type="button" class="shell-user" :aria-expanded="open" aria-haspopup="menu" @click="open = !open">
            <span class="shell-avatar">{{ initials || '?' }}</span>
            <span class="d-none d-sm-block">
                <span class="shell-user-name">{{ auth.user?.name }}</span>
                <span class="shell-user-role">{{ roleLabel }}</span>
            </span>
            <i class="bi bi-chevron-down small text-muted"></i>
        </button>
        <template v-if="open">
            <div class="position-fixed top-0 start-0 w-100 h-100" style="z-index: 1055" aria-hidden="true" @click="open = false"></div>
            <div class="shell-menu" role="menu">
                <p class="text-truncate text-muted px-3 pt-1 pb-2 mb-0" style="font-size: .75rem">{{ auth.user?.email }}</p>
                <router-link to="/account" class="dropdown-item" role="menuitem"><i class="bi bi-key"></i>Account &amp; password</router-link>
                <router-link v-for="l in extraLinks ?? []" :key="l.to" :to="l.to" class="dropdown-item" role="menuitem"><i class="bi" :class="`bi-${l.icon}`"></i>{{ l.label }}</router-link>
                <button type="button" class="dropdown-item" role="menuitem" @click="logout"><i class="bi bi-box-arrow-right"></i>Sign out</button>
            </div>
        </template>
    </div>
</template>
