import type { AxiosError, InternalAxiosRequestConfig } from 'axios';
import type { Router } from 'vue-router';
import { api, ensureCsrf } from './client';
import { useAuth } from '@/stores/auth';
import { useToast } from '@/stores/toast';

/**
 * Reacts to genuine session failures, and nothing else:
 *  - 419 (CSRF token expired): fetch a fresh token and retry the request once.
 *  - 401 on a protected call while we believed we were signed in: the session
 *    really has ended (expired, logged out elsewhere, account deactivated), so
 *    clear the local user and go to the login page, returning here afterwards.
 * 403 is a permission answer, not an authentication failure, and is left to the view.
 */
export function installSessionHandling(router: Router): void {
    let redirecting = false;

    api.interceptors.response.use(undefined, async (error: AxiosError) => {
        const status = error.response?.status;
        const config = error.config as (InternalAxiosRequestConfig & { _csrfRetried?: boolean }) | undefined;
        const url = config?.url ?? '';

        if (status === 419 && config && !config._csrfRetried) {
            config._csrfRetried = true;
            await ensureCsrf();
            return api.request(config);
        }

        // Login failures, the "who am I" probe and the token-only referee form are not sessions.
        const ownsItsOwn401 = url.startsWith('/auth/login') || url.startsWith('/auth/me') || url.startsWith('/referee/');
        if (status === 401 && !ownsItsOwn401) {
            const auth = useAuth();
            if (auth.user && !redirecting) {
                redirecting = true;
                auth.user = null;
                useToast().show('Your session has ended. Please sign in again.', 'warning');
                const here = router.currentRoute.value.fullPath;
                await router.replace({ path: '/login', query: here.startsWith('/login') ? {} : { redirect: here } }).catch(() => {});
                redirecting = false;
            }
        }

        return Promise.reject(error);
    });
}
