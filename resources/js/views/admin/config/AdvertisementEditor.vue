<script setup lang="ts">
/**
 * Advertisement editor. Everything a recruitment needs — posts, eligibility,
 * fees, documents, workflow, checklists and people — is configured here.
 */
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, toApiError } from '@/api/client';
import { useAuth } from '@/stores/auth';
import LoadingState from '@/components/LoadingState.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AdDetailsForm from './parts/AdDetailsForm.vue';
import AdStatusTab from './parts/AdStatusTab.vue';
import AdPostsTab from './parts/AdPostsTab.vue';
import AdDocumentsTab from './parts/AdDocumentsTab.vue';
import AdRequirementsTab from './parts/AdRequirementsTab.vue';
import AdStagesTab from './parts/AdStagesTab.vue';
import AdChecklistsTab from './parts/AdChecklistsTab.vue';
import AdPeopleTab from './parts/AdPeopleTab.vue';
import type { AdvertisementDetail, Cycle, FormTemplate } from './parts/types';

const props = defineProps<{ id?: string }>();
const route = useRoute();
const router = useRouter();
const auth = useAuth();

const loading = ref(true);
const error = ref<string | null>(null);
const ad = ref<AdvertisementDetail | null>(null);
const cycles = ref<Cycle[]>([]);
const refereeForms = ref<FormTemplate[]>([]);
const evaluationForms = ref<FormTemplate[]>([]);

const tabs = computed(() => [
    { key: 'details', label: 'Details', icon: 'card-text' },
    { key: 'status', label: 'Status', icon: 'flag', badge: ad.value?.publish_problems.length || 0 },
    { key: 'posts', label: 'Posts', icon: 'briefcase', badge: ad.value ? ad.value.posts.length : 0, info: true },
    { key: 'documents', label: 'Advertisement PDFs', icon: 'file-earmark-pdf' },
    { key: 'requirements', label: 'Document requirements', icon: 'files' },
    { key: 'stages', label: 'Scrutiny workflow', icon: 'diagram-3' },
    { key: 'checklists', label: 'Checklists', icon: 'list-check' },
    { key: 'people', label: 'People', icon: 'people', hidden: !auth.can('users.manage') },
].filter((t) => !t.hidden));
const tab = ref<string>((route.query.tab as string) || 'details');
watch(tab, (t) => router.replace({ query: { ...route.query, tab: t === 'details' ? undefined : t } }));

async function loadOptions() {
    const optional = (p: Promise<{ data: unknown }>) => p.then((r) => r.data).catch(() => []);
    const [c, r, e] = await Promise.all([
        api.get('/admin/cycles').then((x) => x.data as Cycle[]),
        optional(api.get('/admin/forms/referee')),
        optional(api.get('/admin/forms/evaluation')),
    ]);
    cycles.value = c;
    refereeForms.value = r as FormTemplate[];
    evaluationForms.value = e as FormTemplate[];
}

async function reload() {
    if (!props.id) return;
    try {
        ad.value = (await api.get(`/admin/advertisements/${props.id}`)).data;
    } catch (e) { error.value = toApiError(e).message; }
}

async function init() {
    loading.value = true;
    error.value = null;
    try {
        await Promise.all([loadOptions(), props.id ? reload() : Promise.resolve()]);
        if (!props.id) { ad.value = null; tab.value = 'details'; }
    } catch (e) { error.value = toApiError(e).message; } finally { loading.value = false; }
}
watch(() => props.id, (now, before) => { if (now !== String(ad.value?.id ?? '') || !before) init(); }, { immediate: true });

function saved(next: AdvertisementDetail) {
    const created = !ad.value;
    ad.value = next;
    if (created) router.replace(`/admin/advertisements/${next.id}?tab=posts`).then(() => { tab.value = 'posts'; });
}
function updated(next: AdvertisementDetail) { ad.value = next; }
</script>

<template>
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small mb-1">
                <li class="breadcrumb-item"><router-link to="/admin/advertisements">Advertisements</router-link></li>
                <li class="breadcrumb-item active" aria-current="page">{{ ad ? ad.number : 'New advertisement' }}</li>
            </ol>
        </nav>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h1 class="page-title h4 mb-0">{{ ad ? ad.title : 'New advertisement' }}</h1>
            <StatusBadge v-if="ad" :value="ad.status" />
            <router-link v-if="ad && ['published', 'closed'].includes(ad.status)" :to="`/advertisements/${ad.id}`" class="btn btn-sm btn-outline-secondary ms-auto" target="_blank">
                <i class="bi bi-box-arrow-up-right me-1"></i>Public page
            </router-link>
        </div>

        <LoadingState :loading="loading" :error="error">
            <ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto" role="tablist">
                <li v-for="t in tabs" :key="t.key" class="nav-item" role="presentation">
                    <button type="button" class="nav-link text-nowrap" :class="{ active: tab === t.key }" role="tab" :aria-selected="tab === t.key"
                            :disabled="!ad && t.key !== 'details'" @click="tab = t.key">
                        <i class="bi me-1" :class="`bi-${t.icon}`"></i>{{ t.label }}
                        <span v-if="t.badge" class="badge rounded-pill ms-1" :class="t.info ? 'text-bg-light border' : 'text-bg-warning'">{{ t.badge }}</span>
                    </button>
                </li>
            </ul>
            <p v-if="!ad" class="small text-muted">Save the details to create the draft; posts, documents, workflow, checklists and people can then be configured.</p>

            <AdDetailsForm v-if="tab === 'details'" :key="ad?.id ?? 'new'" :ad="ad" :cycles="cycles" :referee-forms="refereeForms" :evaluation-forms="evaluationForms" @saved="saved" />
            <template v-else-if="ad">
                <AdStatusTab v-if="tab === 'status'" :ad="ad" @updated="updated" />
                <AdPostsTab v-else-if="tab === 'posts'" :ad="ad" @reload="reload" />
                <AdDocumentsTab v-else-if="tab === 'documents'" :ad="ad" @reload="reload" />
                <AdRequirementsTab v-else-if="tab === 'requirements'" :ad="ad" />
                <AdStagesTab v-else-if="tab === 'stages'" :ad="ad" @reload="reload" />
                <AdChecklistsTab v-else-if="tab === 'checklists'" :ad="ad" @reload="reload" />
                <AdPeopleTab v-else-if="tab === 'people'" :ad="ad" :evaluation-forms="evaluationForms" @updated="updated" />
            </template>
        </LoadingState>
    </div>
</template>
