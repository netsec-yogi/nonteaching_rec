<script setup lang="ts">
/** Staff accounts and their roles. Applicants register themselves and are not managed here. */
import { onMounted, reactive, ref } from 'vue';
import { api, toApiError } from '@/api/client';
import { useForm } from '@/composables/useForm';
import { useToast } from '@/stores/toast';
import { useAuth } from '@/stores/auth';
import BaseModal from '@/components/BaseModal.vue';
import FieldError from '@/components/FieldError.vue';
import LoadingState from '@/components/LoadingState.vue';
import Pager from '@/components/Pager.vue';
import { formatDateTime } from '@/utils/format';
import { STAFF_ROLES } from './parts/types';

interface StaffUser {
    id: number; name: string; email: string; mobile: string | null; designation: string | null; is_active: boolean;
    last_login_at: string | null; locked_until: string | null; roles: { id: number; slug: string; name: string }[];
}

const toast = useToast();
const auth = useAuth();
const form = useForm();
const loading = ref(true);
const error = ref<string | null>(null);
const users = ref<StaffUser[]>([]);
const meta = reactive({ page: 1, lastPage: 1, total: 0 });
const filters = reactive({ q: '', role: '' });
const editing = ref<StaffUser | null | undefined>(undefined);
const f = reactive({ name: '', email: '', mobile: '', designation: '', is_active: true, roles: [] as string[] });

async function load(page = 1) {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await api.get('/admin/users', { params: { page, q: filters.q || undefined, role: filters.role || undefined } });
        users.value = data.data;
        Object.assign(meta, { page: data.current_page, lastPage: data.last_page, total: data.total });
    } catch (e) { error.value = toApiError(e).message; } finally { loading.value = false; }
}
onMounted(() => load());

let timer: ReturnType<typeof setTimeout> | undefined;
function search() { clearTimeout(timer); timer = setTimeout(() => load(), 350); }

function open(u: StaffUser | null) {
    form.clear();
    Object.assign(f, u
        ? { name: u.name, email: u.email, mobile: u.mobile ?? '', designation: u.designation ?? '', is_active: u.is_active, roles: u.roles.map((r) => r.slug).filter((r) => r in STAFF_ROLES) }
        : { name: '', email: '', mobile: '', designation: '', is_active: true, roles: [] });
    editing.value = u;
}

async function save() {
    const u = editing.value;
    const payload = { ...f, mobile: f.mobile || null, designation: f.designation || null };
    const res = await form.run(() => (u ? api.put(`/admin/users/${u.id}`, payload) : api.post('/admin/users', payload)),
        u ? 'User updated.' : `Account created. A link to set a password has been emailed to ${f.email}.`);
    if (res) { editing.value = undefined; load(meta.page); }
}

async function reset(u: StaffUser) {
    if (!confirm(`Email a password reset link to ${u.email}?`)) return;
    try {
        const { data } = await api.post(`/admin/users/${u.id}/reset`);
        toast.success(data.message ?? 'Password reset link sent.');
    } catch (e) { toast.error(toApiError(e).message); }
}
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h1 class="page-title h4 mb-0">Users</h1>
            <button type="button" class="btn btn-primary btn-sm ms-auto" @click="open(null)"><i class="bi bi-person-plus me-1"></i>New staff user</button>
        </div>
        <div class="card mb-3">
            <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-end">
                <div class="flex-grow-1" style="max-width: 22rem">
                    <label class="form-label small mb-0" for="u-q">Search</label>
                    <input id="u-q" v-model="filters.q" type="search" class="form-control form-control-sm" placeholder="Name or email" @input="search">
                </div>
                <div>
                    <label class="form-label small mb-0" for="u-role">Role</label>
                    <select id="u-role" v-model="filters.role" class="form-select form-select-sm" @change="load()">
                        <option value="">All staff roles</option>
                        <option v-for="(label, slug) in STAFF_ROLES" :key="slug" :value="slug">{{ label }}</option>
                    </select>
                </div>
            </div>
        </div>

        <LoadingState :loading="loading" :error="error">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm table-cards align-middle mb-0">
                        <thead class="table-light"><tr><th>Name</th><th>Email / mobile</th><th>Roles</th><th>Last sign-in</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            <tr v-for="u in users" :key="u.id" :class="{ 'text-muted': !u.is_active }">
                                <td><div class="fw-semibold">{{ u.name }}</div><div class="small text-muted">{{ u.designation }}</div></td>
                                <td class="small"><div>{{ u.email }}</div><div class="text-muted">{{ u.mobile }}</div></td>
                                <td><span v-for="r in u.roles" :key="r.id" class="badge text-bg-light border me-1">{{ r.name }}</span></td>
                                <td class="small text-nowrap">{{ formatDateTime(u.last_login_at) }}</td>
                                <td>
                                    <span class="badge" :class="u.is_active ? 'text-bg-success' : 'text-bg-secondary'">{{ u.is_active ? 'Active' : 'Inactive' }}</span>
                                    <span v-if="u.locked_until && new Date(u.locked_until) > new Date()" class="badge text-bg-warning ms-1" :title="`Locked until ${formatDateTime(u.locked_until)}`">Locked</span>
                                </td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-primary" @click="open(u)"><i class="bi bi-pencil"></i> Edit</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-1" title="Send password reset link" @click="reset(u)"><i class="bi bi-key"></i><span class="visually-hidden">Send password reset link</span></button>
                                </td>
                            </tr>
                            <tr v-if="!users.length"><td colspan="6" class="text-center text-muted py-4">No users match.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <Pager :page="meta.page" :last-page="meta.lastPage" :total="meta.total" @change="load" />
        </LoadingState>

        <BaseModal v-if="editing !== undefined" :title="editing ? `Edit ${editing.name}` : 'New staff user'" @close="editing = undefined">
            <div v-if="!editing" class="alert alert-info small py-2"><i class="bi bi-envelope me-1"></i>No password is set here. The new user receives an email with a link to set their own password.</div>
            <div class="mb-3">
                <label class="form-label required" for="us-name">Name</label>
                <input id="us-name" v-model="f.name" class="form-control" maxlength="200" :class="{ 'is-invalid': form.errors.name }">
                <FieldError :errors="form.errors" name="name" />
            </div>
            <div class="mb-3">
                <label class="form-label required" for="us-email">Email</label>
                <input id="us-email" v-model="f.email" type="email" class="form-control" maxlength="255" :class="{ 'is-invalid': form.errors.email }">
                <FieldError :errors="form.errors" name="email" />
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label" for="us-mobile">Mobile</label>
                    <input id="us-mobile" v-model="f.mobile" class="form-control" maxlength="15" :class="{ 'is-invalid': form.errors.mobile }">
                    <FieldError :errors="form.errors" name="mobile" />
                </div>
                <div class="col-6">
                    <label class="form-label" for="us-desig">Designation</label>
                    <input id="us-desig" v-model="f.designation" class="form-control" maxlength="255" :class="{ 'is-invalid': form.errors.designation }">
                    <FieldError :errors="form.errors" name="designation" />
                </div>
            </div>
            <div class="mb-3">
                <div class="form-label required">Roles</div>
                <div v-for="(label, slug) in STAFF_ROLES" :key="slug" class="form-check">
                    <input :id="`us-r-${slug}`" v-model="f.roles" class="form-check-input" type="checkbox" :value="slug"
                           :disabled="slug === 'super_admin' && editing?.id === auth.user?.id">
                    <label class="form-check-label" :for="`us-r-${slug}`">{{ label }}</label>
                </div>
                <FieldError :errors="form.errors" name="roles" />
                <div v-if="f.roles.includes('super_admin')" class="form-text text-warning">The Super Administrator has every permission, including user and configuration management.</div>
            </div>
            <div class="form-check">
                <input id="us-active" v-model="f.is_active" class="form-check-input" type="checkbox" :disabled="editing?.id === auth.user?.id">
                <label class="form-check-label" for="us-active">Active — inactive users cannot sign in and are signed out immediately</label>
            </div>
            <FieldError :errors="form.errors" name="is_active" />
            <div v-if="form.message.value && !Object.keys(form.errors).length" class="text-danger small mt-2">{{ form.message.value }}</div>
            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="editing = undefined">Cancel</button>
                <button type="button" class="btn btn-primary" :disabled="form.busy.value" @click="save">{{ editing ? 'Save' : 'Create and send link' }}</button>
            </template>
        </BaseModal>
    </div>
</template>
