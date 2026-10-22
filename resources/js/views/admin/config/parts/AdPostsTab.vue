<script setup lang="ts">
/** Tab 3: posts advertised, with vacancies, eligibility and overrides. */
import { computed, ref } from 'vue';
import { api, toApiError } from '@/api/client';
import { useToast } from '@/stores/toast';
import StatusBadge from '@/components/StatusBadge.vue';
import { ENDORSEMENT_MODES, type AdvertisementDetail, type Post } from './types';
import PostModal from './PostModal.vue';

const props = defineProps<{ ad: AdvertisementDetail }>();
const emit = defineEmits<{ (e: 'reload'): void }>();
const toast = useToast();
const editing = ref<Post | null | undefined>(undefined); // undefined = closed, null = new
const nextSort = computed(() => props.ad.posts.reduce((m, p) => Math.max(m, p.sort_order + 1), 0));
const readOnly = computed(() => props.ad.status === 'archived');

async function remove(p: Post) {
    if (!confirm(`Delete post ${p.code} — ${p.name}? A post that has applications must be closed instead.`)) return;
    try {
        await api.delete(`/admin/advertisements/${props.ad.id}/posts/${p.id}`);
        toast.success('Post deleted.');
        emit('reload');
    } catch (e) { toast.error(toApiError(e).message); }
}

function saved() { editing.value = undefined; emit('reload'); }
</script>

<template>
    <div>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <p class="text-muted small mb-0">Each post carries its own vacancies, eligibility, and optionally its own fee schedule and endorsement rule.</p>
            <button v-if="!readOnly" type="button" class="btn btn-sm btn-primary" @click="editing = null"><i class="bi bi-plus-lg me-1"></i>Add post</button>
        </div>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Code</th><th>Post</th><th>Vacancies</th><th>Criteria</th><th>Overrides</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                        <tr v-for="p in ad.posts" :key="p.id">
                            <td class="fw-semibold">{{ p.code }}</td>
                            <td>
                                <div>{{ p.name }}</div>
                                <div class="small text-muted">{{ [p.department, p.group_classification, p.pay_level].filter(Boolean).join(' · ') }}</div>
                            </td>
                            <td>
                                <span class="fw-semibold">{{ p.total_vacancies }}</span>
                                <div class="small text-muted">{{ p.vacancies.map((v) => `${v.category} ${v.count}`).join(', ') }}</div>
                            </td>
                            <td class="small">{{ p.eligibility_criteria.filter((c) => c.is_essential).length }} essential, {{ p.eligibility_criteria.filter((c) => !c.is_essential).length }} desirable</td>
                            <td class="small">
                                <span v-if="p.fee_schedule" class="badge text-bg-light border me-1">Fee ₹{{ p.fee_schedule.default }}</span>
                                <span v-if="p.endorsement_mode" class="badge text-bg-light border">{{ ENDORSEMENT_MODES[p.endorsement_mode] }}</span>
                                <span v-if="!p.fee_schedule && !p.endorsement_mode" class="text-muted">—</span>
                            </td>
                            <td><StatusBadge :value="p.status" /></td>
                            <td class="text-end text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-primary" :disabled="readOnly" @click="editing = p"><i class="bi bi-pencil"></i> Edit</button>
                                <button type="button" class="btn btn-sm btn-outline-danger ms-1" :disabled="readOnly" @click="remove(p)"><i class="bi bi-trash"></i><span class="visually-hidden">Delete</span></button>
                            </td>
                        </tr>
                        <tr v-if="!ad.posts.length"><td colspan="7" class="text-center text-muted py-4">No posts yet. Add at least one post before publishing.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <PostModal v-if="editing !== undefined" :advertisement-id="ad.id" :post="editing" :next-sort="nextSort" @close="editing = undefined" @saved="saved" />
    </div>
</template>
