<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { api, toApiError } from '@/api/client';
import { useAuth } from '@/stores/auth';
import LoadingState from '@/components/LoadingState.vue';
import { formatDate, formatDateTime, formatSize } from '@/utils/format';
import { documentUrl, type AdvertisementSummary } from './parts/types';

const auth = useAuth();
const ads = ref<AdvertisementSummary[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const sorted = computed(() => [...ads.value].sort((a, b) =>
    Number(b.is_open) - Number(a.is_open) || new Date(b.opens_at).getTime() - new Date(a.opens_at).getTime()));
const openCount = computed(() => ads.value.filter((a) => a.is_open).length);

function statusLabel(ad: AdvertisementSummary): { text: string; cls: string } {
    if (ad.is_open) return { text: 'Open', cls: 'text-bg-success' };
    if (ad.status === 'published' && new Date(ad.opens_at).getTime() > Date.now()) return { text: 'Opens soon', cls: 'text-bg-info' };
    return { text: 'Closed', cls: 'text-bg-secondary' };
}

onMounted(async () => {
    try {
        const { data } = await api.get<AdvertisementSummary[]>('/public/advertisements');
        ads.value = data;
    } catch (e) {
        error.value = toApiError(e).message;
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div>
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
            <div>
                <h1 class="page-title h3 mb-1">Advertisements for Non-Teaching Posts</h1>
                <p class="text-muted mb-0">Read the advertisement and the eligibility conditions for each post carefully before applying. Applications are accepted only through this portal while an advertisement is open.</p>
            </div>
            <div v-if="!auth.user" class="d-flex gap-2">
                <router-link to="/login" class="btn btn-outline-primary btn-sm">Sign in</router-link>
                <router-link to="/register" class="btn btn-primary btn-sm">Register to apply</router-link>
            </div>
        </div>

        <LoadingState :loading="loading" :error="error">
            <div v-if="!ads.length" class="card section-card">
                <div class="card-body text-center text-muted py-5">
                    <i class="bi bi-megaphone fs-2 d-block mb-2" aria-hidden="true"></i>
                    No advertisements are published at present. Please check again later.
                </div>
            </div>

            <template v-else>
                <p class="small text-muted mb-2">
                    {{ openCount }} advertisement{{ openCount === 1 ? '' : 's' }} currently open for applications.
                </p>
                <div class="vstack gap-3">
                    <article v-for="ad in sorted" :key="ad.id" class="card section-card" :class="{ 'border-success': ad.is_open }">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <span><span class="text-muted small me-1">Advt. No.</span>{{ ad.number }}</span>
                            <span class="badge" :class="statusLabel(ad).cls">{{ statusLabel(ad).text }}</span>
                        </div>
                        <div class="card-body">
                            <h2 class="h5 mb-1">
                                <router-link :to="`/advertisements/${ad.id}`">{{ ad.title }}</router-link>
                            </h2>
                            <div v-if="ad.department" class="small text-muted mb-2">{{ ad.department }}</div>
                            <p v-if="ad.summary" class="mb-3">{{ ad.summary }}</p>

                            <dl class="row small mb-3">
                                <dt class="col-sm-3 col-lg-2 text-muted fw-normal">Published</dt>
                                <dd class="col-sm-9 col-lg-4">{{ formatDate(ad.publication_date) }}</dd>
                                <dt class="col-sm-3 col-lg-2 text-muted fw-normal">Opening date</dt>
                                <dd class="col-sm-9 col-lg-4">{{ formatDateTime(ad.opens_at) }}</dd>
                                <dt class="col-sm-3 col-lg-2 text-muted fw-normal">Closing date</dt>
                                <dd class="col-sm-9 col-lg-4"><strong>{{ formatDateTime(ad.closes_at) }}</strong></dd>
                                <dt v-if="ad.contact_email" class="col-sm-3 col-lg-2 text-muted fw-normal">Contact</dt>
                                <dd v-if="ad.contact_email" class="col-sm-9 col-lg-4"><a :href="`mailto:${ad.contact_email}`">{{ ad.contact_email }}</a></dd>
                            </dl>

                            <div v-if="ad.posts.length" class="table-responsive">
                                <table class="table table-sm table-bordered mb-3">
                                    <caption class="visually-hidden">Posts under advertisement {{ ad.number }}</caption>
                                    <thead class="table-light">
                                        <tr><th scope="col">Post</th><th scope="col">Department</th><th scope="col">Pay level</th><th scope="col" class="text-end">Vacancies</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="p in ad.posts" :key="p.id">
                                            <td>{{ p.name }} <span class="text-muted small">({{ p.code }})</span>
                                                <span v-if="p.status !== 'open'" class="badge text-bg-light border ms-1">{{ p.status === 'closed' ? 'Closed' : p.status }}</span>
                                            </td>
                                            <td>{{ p.department ?? '—' }}</td>
                                            <td>{{ p.pay_level ?? '—' }}</td>
                                            <td class="text-end">{{ p.total_vacancies }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <router-link :to="`/advertisements/${ad.id}`" class="btn btn-sm" :class="ad.is_open ? 'btn-primary' : 'btn-outline-secondary'">
                                    {{ ad.is_open ? 'View details and apply' : 'View details' }} <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                </router-link>
                                <a
                                    v-for="d in ad.documents"
                                    :key="d.id"
                                    :href="documentUrl(ad.id, d.id)"
                                    target="_blank"
                                    rel="noopener"
                                    class="btn btn-sm btn-outline-secondary"
                                >
                                    <i class="bi bi-file-earmark-pdf text-danger me-1" aria-hidden="true"></i>{{ d.title || d.original_name }}
                                    <span v-if="d.size" class="text-muted small">({{ formatSize(d.size) }})</span>
                                </a>
                            </div>
                        </div>
                    </article>
                </div>
            </template>
        </LoadingState>
    </div>
</template>
