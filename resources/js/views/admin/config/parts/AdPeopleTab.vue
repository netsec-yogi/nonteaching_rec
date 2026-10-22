<script setup lang="ts">
/** Tab 8: Recruitment Administrators, scrutiny teams and Screening Committees for this advertisement. */
import { computed, onMounted, reactive, ref } from 'vue';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import { useToast } from '@/stores/toast';
import BaseModal from '@/components/BaseModal.vue';
import FieldError from '@/components/FieldError.vue';
import LoadingState from '@/components/LoadingState.vue';
import type { AdvertisementDetail, Committee, FormTemplate, Team, UserOption } from './types';

const props = defineProps<{ ad: AdvertisementDetail; evaluationForms: FormTemplate[] }>();
const emit = defineEmits<{ (e: 'updated', ad: AdvertisementDetail): void }>();
const toast = useToast();
const loading = ref(true);
const error = ref<string | null>(null);
const admins = ref<UserOption[]>([]);
const scrutinyUsers = ref<UserOption[]>([]);
const committeeUsers = ref<UserOption[]>([]);
const teams = ref<Team[]>([]);
const committees = ref<Committee[]>([]);
const base = computed(() => `/admin/advertisements/${props.ad.id}`);

const opts = (role: string) => api.get('/admin/user-options', { params: { roles: [role] } }).then((r) => r.data as UserOption[]);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const [a, s, c, t, m] = await Promise.all([opts('recruitment_admin'), opts('scrutiny_user'), opts('committee_member'),
            api.get(`${base.value}/teams`), api.get(`${base.value}/committees`)]);
        admins.value = a; scrutinyUsers.value = s; committeeUsers.value = c; teams.value = t.data; committees.value = m.data;
    } catch (e) { error.value = toApiError(e).message; } finally { loading.value = false; }
}
onMounted(load);

// ---- administrators
const adminForm = useForm();
const adminIds = ref<number[]>(props.ad.administrators.map((u) => u.id));
async function saveAdmins() {
    const res = await adminForm.run(() => api.put(`${base.value}/administrators`, { user_ids: adminIds.value }), 'Recruitment Administrators updated.');
    if (res) emit('updated', res.data as AdvertisementDetail);
}

// ---- teams
const teamForm = useForm();
const team = ref<Team | null | undefined>(undefined);
const tf = reactive({ name: '', post_id: null as number | null, member_ids: [] as number[] });
function openTeam(t: Team | null) {
    teamForm.clear();
    Object.assign(tf, { name: t?.name ?? '', post_id: t?.post_id ?? null, member_ids: t?.members.map((m) => m.id) ?? [] });
    team.value = t;
}
async function saveTeam() {
    const t = team.value;
    const res = await teamForm.run(() => (t ? api.put(`${base.value}/teams/${t.id}`, tf) : api.post(`${base.value}/teams`, tf)), 'Scrutiny team saved.');
    if (res) { team.value = undefined; load(); }
}
async function deleteTeam(t: Team) {
    if (!confirm(`Delete scrutiny team “${t.name}”?`)) return;
    try { await api.delete(`${base.value}/teams/${t.id}`); toast.success('Team deleted.'); load(); } catch (e) { toast.error(toApiError(e).message); }
}

// ---- committees
const comForm = useForm();
const committee = ref<Committee | null | undefined>(undefined);
const cf = reactive({ name: '', post_id: null as number | null, evaluation_mode: null as string | null, evaluation_form_template_id: null as number | null });
const members = ref<{ user_id: number; is_chair: boolean }[]>([]);
function openCommittee(c: Committee | null) {
    comForm.clear();
    Object.assign(cf, { name: c?.name ?? '', post_id: c?.post_id ?? null, evaluation_mode: c?.evaluation_mode ?? null, evaluation_form_template_id: c?.evaluation_form_template_id ?? null });
    members.value = c?.members.map((m) => ({ user_id: m.id, is_chair: !!m.pivot?.is_chair })) ?? [];
    committee.value = c;
}
function toggleMember(id: number, on: boolean) {
    if (on) members.value.push({ user_id: id, is_chair: false });
    else members.value = members.value.filter((m) => m.user_id !== id);
}
function setChair(id: number) { members.value.forEach((m) => { m.is_chair = m.user_id === id; }); }
async function saveCommittee() {
    const c = committee.value;
    const payload = { ...cf, members: members.value };
    const res = await comForm.run(() => (c ? api.put(`${base.value}/committees/${c.id}`, payload) : api.post(`${base.value}/committees`, payload)), 'Screening Committee saved.');
    if (res) { committee.value = undefined; load(); }
}
async function deleteCommittee(c: Committee) {
    if (!confirm(`Delete “${c.name}”?`)) return;
    try { await api.delete(`${base.value}/committees/${c.id}`); toast.success('Committee deleted.'); load(); } catch (e) { toast.error(toApiError(e).message); }
}
const postLabel = (id: number | null) => (id ? props.ad.posts.find((p) => p.id === id)?.code ?? `#${id}` : 'All posts');
</script>

<template>
    <LoadingState :loading="loading" :error="error">
        <div class="card section-card mb-3">
            <div class="card-header">Recruitment Administrators</div>
            <div class="card-body">
                <p class="small text-muted">Recruitment Administrators manage this advertisement's applications: receipt, fee and document verification, assignment to scrutiny and forwarding to the committee.</p>
                <div class="row g-1">
                    <div v-for="u in admins" :key="u.id" class="col-md-6">
                        <div class="form-check">
                            <input :id="`adm-${u.id}`" v-model="adminIds" class="form-check-input" type="checkbox" :value="u.id">
                            <label class="form-check-label" :for="`adm-${u.id}`">{{ u.name }} <span class="text-muted small">{{ u.designation ?? u.email }}</span></label>
                        </div>
                    </div>
                    <div v-if="!admins.length" class="small text-muted">No active users hold the Recruitment Administrator role. <router-link to="/admin/users">Manage users</router-link></div>
                </div>
                <FieldError :errors="adminForm.errors" name="user_ids" />
                <button type="button" class="btn btn-sm btn-primary mt-2" :disabled="adminForm.busy.value" @click="saveAdmins">Save administrators</button>
            </div>
        </div>

        <div class="card section-card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Scrutiny teams</span>
                <button type="button" class="btn btn-sm btn-outline-primary" @click="openTeam(null)"><i class="bi bi-plus"></i> Add team</button>
            </div>
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Team</th><th>Post</th><th>Members</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    <tr v-for="t in teams" :key="t.id">
                        <td class="fw-semibold">{{ t.name }}</td>
                        <td class="small">{{ postLabel(t.post_id) }}</td>
                        <td class="small">{{ t.members.map((m) => m.name).join(', ') || '—' }}</td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-primary" @click="openTeam(t)"><i class="bi bi-pencil"></i><span class="visually-hidden">Edit</span></button>
                            <button type="button" class="btn btn-sm btn-outline-danger ms-1" @click="deleteTeam(t)"><i class="bi bi-trash"></i><span class="visually-hidden">Delete</span></button>
                        </td>
                    </tr>
                    <tr v-if="!teams.length"><td colspan="4" class="text-center text-muted py-3">No scrutiny teams.</td></tr>
                </tbody>
            </table>
        </div>

        <div class="card section-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Screening Committees</span>
                <button type="button" class="btn btn-sm btn-outline-primary" @click="openCommittee(null)"><i class="bi bi-plus"></i> Add committee</button>
            </div>
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Committee</th><th>Post</th><th>Members</th><th>Mode / form</th><th>Assigned</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    <tr v-for="c in committees" :key="c.id">
                        <td class="fw-semibold">{{ c.name }}</td>
                        <td class="small">{{ postLabel(c.post_id) }}</td>
                        <td class="small">
                            <span v-for="(m, i) in c.members" :key="m.id">{{ i ? ', ' : '' }}{{ m.name }}<span v-if="m.pivot?.is_chair" class="badge text-bg-info ms-1">Chair</span></span>
                            <span v-if="!c.members.length" class="text-muted">—</span>
                        </td>
                        <td class="small">{{ c.evaluation_mode ? (c.evaluation_mode === 'individual' ? 'Individual' : 'Collective') : 'Advertisement default' }}<div class="text-muted">{{ c.evaluation_form?.name ?? 'Default form' }}</div></td>
                        <td class="small">{{ c.assignments_count ?? 0 }}</td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-primary" @click="openCommittee(c)"><i class="bi bi-pencil"></i><span class="visually-hidden">Edit</span></button>
                            <button type="button" class="btn btn-sm btn-outline-danger ms-1" :disabled="!!c.assignments_count" :title="c.assignments_count ? 'Has applications assigned' : 'Delete'" @click="deleteCommittee(c)"><i class="bi bi-trash"></i><span class="visually-hidden">Delete</span></button>
                        </td>
                    </tr>
                    <tr v-if="!committees.length"><td colspan="6" class="text-center text-muted py-3">No Screening Committees.</td></tr>
                </tbody>
            </table>
        </div>

        <BaseModal v-if="team !== undefined" :title="team ? 'Edit scrutiny team' : 'Add scrutiny team'" @close="team = undefined">
            <div class="mb-3">
                <label class="form-label required" for="tm-name">Team name</label>
                <input id="tm-name" v-model="tf.name" class="form-control" maxlength="150" :class="{ 'is-invalid': teamForm.errors.name }">
                <FieldError :errors="teamForm.errors" name="name" />
            </div>
            <div class="mb-3">
                <label class="form-label" for="tm-post">Post</label>
                <select id="tm-post" v-model="tf.post_id" class="form-select">
                    <option :value="null">All posts</option>
                    <option v-for="p in ad.posts" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                </select>
                <FieldError :errors="teamForm.errors" name="post_id" />
            </div>
            <div class="form-label">Members (Scrutiny Users)</div>
            <div v-for="u in scrutinyUsers" :key="u.id" class="form-check">
                <input :id="`tm-u-${u.id}`" v-model="tf.member_ids" class="form-check-input" type="checkbox" :value="u.id">
                <label class="form-check-label" :for="`tm-u-${u.id}`">{{ u.name }} <span class="text-muted small">{{ u.designation ?? u.email }}</span></label>
            </div>
            <p v-if="!scrutinyUsers.length" class="small text-muted">No active Scrutiny Users.</p>
            <FieldError :errors="teamForm.errors" name="member_ids" />
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="team = undefined">Cancel</button>
                <button type="button" class="btn btn-primary" :disabled="teamForm.busy.value" @click="saveTeam">Save team</button>
            </template>
        </BaseModal>

        <BaseModal v-if="committee !== undefined" :title="committee ? 'Edit Screening Committee' : 'Add Screening Committee'" size="lg" @close="committee = undefined">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label required" for="cm-name">Committee name</label>
                    <input id="cm-name" v-model="cf.name" class="form-control" maxlength="200" :class="{ 'is-invalid': comForm.errors.name }">
                    <FieldError :errors="comForm.errors" name="name" />
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="cm-post">Post</label>
                    <select id="cm-post" v-model="cf.post_id" class="form-select">
                        <option :value="null">All posts</option>
                        <option v-for="p in ad.posts" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                    </select>
                    <FieldError :errors="comForm.errors" name="post_id" />
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="cm-mode">Evaluation mode</label>
                    <select id="cm-mode" v-model="cf.evaluation_mode" class="form-select">
                        <option :value="null">Advertisement default ({{ ad.committee_evaluation_mode }})</option>
                        <option value="individual">Individual</option>
                        <option value="collective">Collective</option>
                    </select>
                    <FieldError :errors="comForm.errors" name="evaluation_mode" />
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="cm-form">Evaluation form</label>
                    <select id="cm-form" v-model="cf.evaluation_form_template_id" class="form-select">
                        <option :value="null">Advertisement default</option>
                        <option v-for="t in evaluationForms" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                    <FieldError :errors="comForm.errors" name="evaluation_form_template_id" />
                </div>
            </div>
            <div class="form-label mt-3">Members (Screening Committee Members)</div>
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Member</th><th class="text-center">Chair</th></tr></thead>
                <tbody>
                    <tr v-for="u in committeeUsers" :key="u.id">
                        <td>
                            <div class="form-check mb-0">
                                <input :id="`cm-u-${u.id}`" class="form-check-input" type="checkbox" :checked="members.some((m) => m.user_id === u.id)" @change="toggleMember(u.id, ($event.target as HTMLInputElement).checked)">
                                <label class="form-check-label" :for="`cm-u-${u.id}`">{{ u.name }} <span class="text-muted small">{{ u.designation ?? u.email }}</span></label>
                            </div>
                        </td>
                        <td class="text-center">
                            <input class="form-check-input" type="radio" name="chair" :aria-label="`${u.name} is chair`" :disabled="!members.some((m) => m.user_id === u.id)"
                                   :checked="members.some((m) => m.user_id === u.id && m.is_chair)" @change="setChair(u.id)">
                        </td>
                    </tr>
                    <tr v-if="!committeeUsers.length"><td colspan="2" class="small text-muted">No active Screening Committee Members.</td></tr>
                </tbody>
            </table>
            <FieldError :errors="comForm.errors" name="members" />
            <p class="small text-muted mt-2 mb-0">In individual mode each member records an evaluation and the chair records the committee's recommendation.</p>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="committee = undefined">Cancel</button>
                <button type="button" class="btn btn-primary" :disabled="comForm.busy.value" @click="saveCommittee">Save committee</button>
            </template>
        </BaseModal>
    </LoadingState>
</template>
