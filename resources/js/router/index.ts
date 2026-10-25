import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { useAuth } from '@/stores/auth';
import PublicLayout from '@/layouts/PublicLayout.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import BareLayout from '@/layouts/BareLayout.vue';
import GuestLayout from '@/layouts/GuestLayout.vue';

declare module 'vue-router' {
    interface RouteMeta {
        guest?: boolean;          // only for signed-out users
        auth?: boolean;           // any signed-in user
        applicant?: boolean;      // applicant role
        staff?: boolean;          // any staff role
        permission?: string;      // staff permission required
        title?: string;
    }
}

const routes: RouteRecordRaw[] = [
    {
        path: '/',
        component: PublicLayout,
        children: [
            { path: '', component: () => import('@/views/public/Home.vue'), meta: { title: 'Advertisements' } },
            { path: 'advertisements/:id', component: () => import('@/views/public/AdvertisementDetail.vue'), props: true, meta: { title: 'Advertisement' } },
            { path: 'privacy', component: () => import('@/views/public/Privacy.vue'), meta: { title: 'Privacy notice' } },
            { path: 'account', component: () => import('@/views/common/Account.vue'), meta: { auth: true, title: 'Account' } },

            { path: 'dashboard', component: () => import('@/views/applicant/Dashboard.vue'), meta: { applicant: true, title: 'My applications' } },
            { path: 'applications/:uuid', component: () => import('@/views/applicant/ApplicationStatus.vue'), props: true, meta: { applicant: true, title: 'Application status' } },
            { path: 'applications/:uuid/edit/:step?', component: () => import('@/views/applicant/Wizard.vue'), props: true, meta: { applicant: true, title: 'Application form' } },
            { path: 'applications/:uuid/review', component: () => import('@/views/applicant/Review.vue'), props: true, meta: { applicant: true, title: 'Review and submit' } },
        ],
    },
    {
        path: '/',
        component: GuestLayout,
        children: [
            { path: 'login', component: () => import('@/views/auth/Login.vue'), meta: { guest: true, title: 'Sign in' } },
            { path: 'register', component: () => import('@/views/auth/Register.vue'), meta: { guest: true, title: 'Register' } },
            { path: 'forgot-password', component: () => import('@/views/auth/ForgotPassword.vue'), meta: { guest: true, title: 'Forgot password' } },
            { path: 'reset-password', component: () => import('@/views/auth/ResetPassword.vue'), meta: { title: 'Reset password' } },
            { path: 'reset-password/:token', redirect: (to) => ({ path: '/reset-password', query: { ...to.query, token: to.params.token as string } }) },
        ],
    },
    {
        path: '/referee',
        component: BareLayout,
        children: [{ path: ':token', component: () => import('@/views/referee/RefereeForm.vue'), props: true, meta: { title: 'Referee recommendation' } }],
    },
    {
        path: '/',
        component: AdminLayout,
        meta: { staff: true },
        children: [
            { path: 'admin', component: () => import('@/views/admin/Dashboard.vue'), meta: { staff: true, title: 'Dashboard' } },
            { path: 'admin/applications', component: () => import('@/views/admin/applications/List.vue'), meta: { staff: true, title: 'Applications' } },
            { path: 'admin/applications/:uuid', component: () => import('@/views/admin/applications/Profile.vue'), props: true, meta: { staff: true, title: 'Application' } },
            { path: 'scrutiny', component: () => import('@/views/admin/scrutiny/Queue.vue'), meta: { staff: true, title: 'Scrutiny queue' } },
            { path: 'scrutiny/:uuid', component: () => import('@/views/admin/scrutiny/Workspace.vue'), props: true, meta: { staff: true, title: 'Scrutiny workspace' } },
            { path: 'committee', component: () => import('@/views/admin/committee/Queue.vue'), meta: { staff: true, title: 'Screening Committee' } },
            { path: 'committee/comparison/:id', component: () => import('@/views/admin/committee/Comparison.vue'), props: true, meta: { staff: true, title: 'Comparison report' } },
            { path: 'committee/:uuid', component: () => import('@/views/admin/committee/Workspace.vue'), props: true, meta: { staff: true, title: 'Committee workspace' } },
            { path: 'admin/reports', component: () => import('@/views/admin/reports/Reports.vue'), meta: { staff: true, title: 'Reports' } },
            { path: 'admin/logs/:type', component: () => import('@/views/admin/logs/Logs.vue'), props: true, meta: { staff: true } },

            { path: 'admin/advertisements', component: () => import('@/views/admin/config/Advertisements.vue'), meta: { staff: true, permission: 'advertisements.manage' } },
            { path: 'admin/advertisements/new', component: () => import('@/views/admin/config/AdvertisementEditor.vue'), meta: { staff: true, permission: 'advertisements.manage' } },
            { path: 'admin/advertisements/:id', component: () => import('@/views/admin/config/AdvertisementEditor.vue'), props: true, meta: { staff: true, permission: 'advertisements.manage' } },
            { path: 'admin/cycles', component: () => import('@/views/admin/config/Cycles.vue'), meta: { staff: true, permission: 'advertisements.manage' } },
            { path: 'admin/checklists', component: () => import('@/views/admin/config/Checklists.vue'), meta: { staff: true, permission: 'configuration.manage' } },
            { path: 'admin/checklists/:id', component: () => import('@/views/admin/config/ChecklistEditor.vue'), props: true, meta: { staff: true, permission: 'configuration.manage' } },
            { path: 'admin/document-types', component: () => import('@/views/admin/config/DocumentTypes.vue'), meta: { staff: true, permission: 'configuration.manage' } },
            { path: 'admin/forms/:kind', component: () => import('@/views/admin/config/Forms.vue'), props: true, meta: { staff: true, permission: 'configuration.manage' } },
            { path: 'admin/email-templates', component: () => import('@/views/admin/config/EmailTemplates.vue'), meta: { staff: true, permission: 'templates.manage' } },
            { path: 'admin/settings', component: () => import('@/views/admin/config/Settings.vue'), meta: { staff: true, permission: 'settings.manage' } },
            { path: 'admin/users', component: () => import('@/views/admin/config/Users.vue'), meta: { staff: true, permission: 'users.manage' } },
            { path: 'admin/permissions', component: () => import('@/views/admin/config/Permissions.vue'), meta: { staff: true, permission: 'users.manage' } },
        ],
    },
    { path: '/:pathMatch(.*)*', component: PublicLayout, children: [{ path: '', component: () => import('@/views/common/NotFound.vue') }] },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: (to, from, saved) => saved ?? (to.path !== from.path ? { top: 0 } : undefined),
});

router.beforeEach((to) => {
    const auth = useAuth();
    const signedIn = !!auth.user;
    const needs = to.matched.reduce((acc, r) => ({ ...acc, ...r.meta }), {} as Record<string, unknown>);

    if (needs.guest && signedIn) return auth.homeRoute();
    if ((needs.auth || needs.applicant || needs.staff) && !signedIn) return { path: '/login', query: { redirect: to.fullPath } };
    if (needs.applicant && !auth.isApplicant) return auth.homeRoute();
    if (needs.staff && !auth.isStaff) return '/dashboard';
    if (typeof needs.permission === 'string' && !auth.can(needs.permission)) return auth.homeRoute();
    return true;
});

router.afterEach((to, from) => {
    const title = to.meta.title;
    document.title = (title ? `${title} — ` : '') + 'Non-Teaching Recruitment — ABV-IIITM Gwalior';

    // In a single-page app the browser does not reset focus on navigation, so screen reader and
    // keyboard users would stay on the link they used. Move focus to the new page's content
    // (not on first load, and not when only the query string changes, e.g. filters).
    if (from.matched.length && to.path !== from.path) {
        setTimeout(() => {
            const main = document.getElementById('main');
            // Leave focus alone if the new page already placed it (e.g. an autofocus field).
            if (main && !main.contains(document.activeElement)) main.focus({ preventScroll: true });
        });
    }
});

export default router;
