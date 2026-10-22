<script setup lang="ts">
/** Tab 7: which checklist templates scrutiny officers use for this advertisement (or for individual posts). */
import { onMounted, ref } from 'vue';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import { useToast } from '@/stores/toast';
import BaseModal from '@/components/BaseModal.vue';
import FieldError from '@/components/FieldError.vue';
import type { AdvertisementDetail, ChecklistAssignment, ChecklistTemplate } from './types';

const props = defineProps<{ ad: AdvertisementDetail }>();
const emit = defineEmits<{ (e: 'reload'): void }>();
const toast = useToast();
const form = useForm();
const templates = ref<ChecklistTemplate[]>([]);
const assigning = ref(false);
const templateId = ref<number | null>(null);
const postIds = ref<number[]>([]);

onMounted(async () => {
    try { templates.value = (await api.get('/admin/checklists')).data.templates; } catch (e) { toast.error(toApiError(e).message); }
});

function open() { form.clear(); templateId.value = templates.value[0]?.id ?? null; postIds.value = []; assigning.value = true; }

async function assign() {
    if (!templateId.value) { form.errors.template = ['Choose a checklist template.']; return; }
    const res = await form.run(() => api.post(`/admin/checklists/${templateId.value}/assign`, { advertisement_id: props.ad.id, post_ids: postIds.value }), 'Checklist assigned.');
    if (res) { assigning.value = false; emit('reload'); }
}

async function unassign(a: ChecklistAssignment) {
    if (!confirm(`Remove “${a.template?.name}” from ${a.post ? 'post ' + a.post.code : 'this advertisement'}?`)) return;
    try {
        await api.delete(`/admin/checklists/${a.checklist_template_id}/assignments/${a.id}`);
        toast.success('Assignment removed.');
        emit('reload');
    } catch (e) { toast.error(toApiError(e).message); }
}
</script>

<template>
    <div>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <p class="text-muted small mb-0">An application is checked against every template assigned to the whole advertisement plus any assigned to its post. Items marked “applicant visible” also appear on the candidate's Step 17 checklist.</p>
            <button v-if="ad.status !== 'archived'" type="button" class="btn btn-sm btn-primary text-nowrap" @click="open"><i class="bi bi-plus-lg me-1"></i>Assign checklist</button>
        </div>
        <div class="card">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Checklist template</th><th>Applies to</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    <tr v-for="a in ad.checklist_assignments" :key="a.id">
                        <td><router-link :to="`/admin/checklists/${a.checklist_template_id}`">{{ a.template?.name }}</router-link></td>
                        <td>{{ a.post ? `Post ${a.post.code}` : 'Whole advertisement' }}</td>
                        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" :disabled="ad.status === 'archived'" @click="unassign(a)"><i class="bi bi-x-lg"></i> Unassign</button></td>
                    </tr>
                    <tr v-if="!ad.checklist_assignments.length"><td colspan="3" class="text-center text-muted py-4">No checklist assigned. One is required before publishing.</td></tr>
                </tbody>
            </table>
        </div>

        <BaseModal v-if="assigning" title="Assign checklist template" @close="assigning = false">
            <div class="mb-3">
                <label class="form-label required" for="ck-tpl">Checklist template</label>
                <select id="ck-tpl" v-model="templateId" class="form-select" :class="{ 'is-invalid': form.errors.template }">
                    <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }} ({{ t.items_count }} items)</option>
                </select>
                <FieldError :errors="form.errors" name="template" />
            </div>
            <div class="form-label">Posts</div>
            <div class="form-text mt-0 mb-1">Leave all unticked to assign to the whole advertisement.</div>
            <div v-for="p in ad.posts" :key="p.id" class="form-check">
                <input :id="`ck-post-${p.id}`" v-model="postIds" class="form-check-input" type="checkbox" :value="p.id">
                <label class="form-check-label" :for="`ck-post-${p.id}`">{{ p.code }} — {{ p.name }}</label>
            </div>
            <FieldError :errors="form.errors" name="post_ids" />
            <FieldError :errors="form.errors" name="advertisement_id" />
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="assigning = false">Cancel</button>
                <button type="button" class="btn btn-primary" :disabled="form.busy.value" @click="assign">Assign</button>
            </template>
        </BaseModal>
    </div>
</template>
