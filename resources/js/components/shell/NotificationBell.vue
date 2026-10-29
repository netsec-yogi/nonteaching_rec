<script setup lang="ts">
/** Top-bar notifications bell with the latest 15 notices (shared by the staff and applicant shells). */
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import { formatDateTime } from '@/utils/format';

interface Notice { id: string; data: { title?: string; message?: string; link?: string | null }; read_at: string | null; created_at: string }

const route = useRoute();
const router = useRouter();
const open = ref(false);
const notices = ref<Notice[]>([]);
const unread = computed(() => notices.value.filter((n) => !n.read_at).length);

async function load() {
    try { notices.value = (await api.get<Notice[]>('/notifications')).data.slice(0, 15); } catch { /* the bell is optional */ }
}
async function openNotice(n: Notice) {
    if (!n.read_at) { api.post('/notifications/read', { id: n.id }).catch(() => {}); n.read_at = new Date().toISOString(); }
    open.value = false;
    if (n.data.link) router.push(n.data.link);
}
async function markAllRead() {
    await api.post('/notifications/read').catch(() => {});
    notices.value.forEach((n) => (n.read_at ??= new Date().toISOString()));
}
onMounted(load);
watch(open, (o) => { if (o) load(); });
watch(() => route.fullPath, () => { open.value = false; });
</script>

<template>
    <div class="position-relative">
        <button type="button" class="shell-icon-button" :aria-label="unread ? `${unread > 99 ? '99+' : unread} unread notifications` : 'Notifications'" :aria-expanded="open" @click="open = !open">
            <i class="bi bi-bell"></i>
            <span v-if="unread" class="shell-dot" aria-hidden="true">{{ unread > 99 ? '99+' : unread }}</span>
        </button>
        <template v-if="open">
            <div class="position-fixed top-0 start-0 w-100 h-100" style="z-index: 1055" aria-hidden="true" @click="open = false"></div>
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
</template>
