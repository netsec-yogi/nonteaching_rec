import { defineStore } from 'pinia';
import { api, ensureCsrf } from '@/api/client';

export interface User {
    id: number;
    name: string;
    email: string;
    mobile: string | null;
    designation: string | null;
    email_verified: boolean;
    roles: string[];
    permissions: string[];
    is_staff: boolean;
    is_super_admin: boolean;
}

export const useAuth = defineStore('auth', {
    state: () => ({ user: null as User | null, loaded: false }),
    getters: {
        isApplicant: (s) => !!s.user?.roles.includes('applicant'),
        isStaff: (s) => !!s.user?.is_staff,
    },
    actions: {
        async load() {
            try {
                await ensureCsrf();
                const { data } = await api.get('/auth/me');
                this.user = data.user;
            } catch {
                this.user = null;
            } finally {
                this.loaded = true;
            }
        },
        async login(email: string, password: string, remember = false) {
            await ensureCsrf();
            const { data } = await api.post('/auth/login', { email, password, remember });
            this.user = data.user;
            return data.user as User;
        },
        async register(payload: Record<string, unknown>) {
            await ensureCsrf();
            const { data } = await api.post('/auth/register', payload);
            this.user = data.user;
            return data.user as User;
        },
        async logout() {
            try { await api.post('/auth/logout'); } finally { this.user = null; }
        },
        hasRole(...roles: string[]) {
            return !!this.user && roles.some((r) => this.user!.roles.includes(r));
        },
        can(permission: string) {
            return !!this.user && (this.user.is_super_admin || this.user.permissions.includes(permission));
        },
        /** Where to land after signing in. */
        homeRoute(): string {
            const u = this.user;
            if (!u) return '/login';
            if (!u.is_staff) return '/dashboard';
            if (u.is_super_admin || u.roles.includes('recruitment_admin')) return '/admin';
            if (u.roles.includes('scrutiny_user')) return '/scrutiny';
            if (u.roles.includes('committee_member')) return '/committee';
            return '/admin';
        },
    },
});
