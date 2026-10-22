<script setup lang="ts">
/** Role × permission matrix. The Super Administrator holds every permission implicitly and is not listed. */
import { computed, onMounted, ref } from 'vue';
import { api, toApiError } from '@/api/client';
import { useToast } from '@/stores/toast';
import LoadingState from '@/components/LoadingState.vue';
import { humanize } from '@/utils/format';

interface Role { id: number; slug: string; name: string; permissions: string[] }
interface Permission { slug: string; name: string; group: string; is_sensitive: boolean }

const toast = useToast();
const loading = ref(true);
const error = ref<string | null>(null);
const roles = ref<Role[]>([]);
const original = ref<Record<number, string[]>>({});
const permissions = ref<Permission[]>([]);
const saving = ref<number | null>(null);

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/admin/permissions');
        roles.value = data.roles.map((r: Role) => ({ ...r, permissions: [...r.permissions] }));
        original.value = Object.fromEntries(data.roles.map((r: Role) => [r.id, [...r.permissions]]));
        permissions.value = data.permissions;
    } catch (e) { error.value = toApiError(e).message; } finally { loading.value = false; }
}
onMounted(load);

const groups = computed(() => {
    const out: Record<string, Permission[]> = {};
    for (const p of permissions.value) (out[p.group] ??= []).push(p);
    return Object.entries(out);
});
const isDirty = (r: Role) => {
    const o = original.value[r.id] ?? [];
    return o.length !== r.permissions.length || r.permissions.some((p) => !o.includes(p));
};

function toggle(r: Role, p: Permission, on: boolean) {
    if (on && p.is_sensitive && !confirm(`“${p.name}” is a sensitive permission. Grant it to every ${r.name}?`)) return;
    r.permissions = on ? [...r.permissions, p.slug] : r.permissions.filter((x) => x !== p.slug);
}

async function save(r: Role) {
    saving.value = r.id;
    try {
        await api.put(`/admin/roles/${r.id}/permissions`, { permissions: r.permissions });
        original.value[r.id] = [...r.permissions];
        toast.success(`Permissions for ${r.name} saved.`);
    } catch (e) { toast.error(toApiError(e).message); } finally { saving.value = null; }
}
</script>

<template>
    <div>
        <h1 class="page-title h4 mb-3">Roles and permissions</h1>
        <div class="alert alert-info small">
            <i class="bi bi-info-circle me-1"></i>The <strong>Super Administrator</strong> implicitly holds every permission and is not shown. Permissions marked
            <span class="badge text-bg-danger">Sensitive</span> give access to confidential material or audit records. Changes take effect on each user's next request and are recorded in the audit trail.
            Data access is additionally limited to the advertisements, teams and committees a user is assigned to.
        </div>
        <LoadingState :loading="loading" :error="error">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Permission</th>
                                <th class="text-center text-muted small" style="width: 9rem">Super Administrator</th>
                                <th v-for="r in roles" :key="r.id" class="text-center" style="width: 10rem">{{ r.name }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="[group, perms] in groups" :key="group">
                                <tr class="table-secondary"><th :colspan="roles.length + 2" class="small text-uppercase">{{ humanize(group) }}</th></tr>
                                <tr v-for="p in perms" :key="p.slug">
                                    <td>
                                        <div class="small">{{ p.name }} <span v-if="p.is_sensitive" class="badge text-bg-danger ms-1">Sensitive</span></div>
                                        <code class="small text-muted">{{ p.slug }}</code>
                                    </td>
                                    <td class="text-center"><i class="bi bi-check-lg text-muted" title="Implicit"></i></td>
                                    <td v-for="r in roles" :key="r.id" class="text-center">
                                        <input class="form-check-input" type="checkbox" :checked="r.permissions.includes(p.slug)" :aria-label="`${r.name}: ${p.name}`"
                                               @change="toggle(r, p, ($event.target as HTMLInputElement).checked); ($event.target as HTMLInputElement).checked = r.permissions.includes(p.slug)">
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2"></td>
                                <td v-for="r in roles" :key="r.id" class="text-center">
                                    <button type="button" class="btn btn-sm" :class="isDirty(r) ? 'btn-primary' : 'btn-outline-secondary'" :disabled="!isDirty(r) || saving === r.id" @click="save(r)">Save</button>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </LoadingState>
    </div>
</template>
