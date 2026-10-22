import axios, { AxiosError } from 'axios';

/**
 * Axios configured for Laravel Sanctum's cookie-based SPA authentication.
 * CSRF is taken from the XSRF-TOKEN cookie automatically.
 */
export const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

export type ValidationErrors = Record<string, string[]>;

export interface ApiError {
    status: number;
    message: string;
    errors: ValidationErrors;
}

/** Normalise any failed request into { status, message, errors }. */
export function toApiError(e: unknown): ApiError {
    const err = e as AxiosError<{ message?: string; errors?: ValidationErrors }>;
    const status = err.response?.status ?? 0;
    const data = err.response?.data;
    let message = data?.message || err.message || 'Something went wrong.';
    if (status === 0) message = 'Could not reach the server. Check your connection and try again.';
    if (status === 419) message = 'Your session has expired. Please reload the page and sign in again.';
    if (status === 429) message = 'Too many attempts. Please wait a minute and try again.';
    if (status >= 500) message = 'The server could not complete this request. Please try again shortly.';
    return { status, message, errors: data?.errors ?? {} };
}

/** First error message for a field (supports wildcard prefixes like "rows.0."). */
export function fieldError(errors: ValidationErrors, field: string): string | undefined {
    return errors[field]?.[0];
}

/** Blob responses hide the server's JSON error; read it back so toApiError can show it. */
async function unwrapBlobError(e: unknown): Promise<never> {
    const err = e as AxiosError;
    const data = err.response?.data;
    if (data instanceof Blob && data.type.includes('json')) {
        try { (err.response as { data: unknown }).data = JSON.parse(await data.text()); } catch { /* keep original */ }
    }
    throw err;
}

/** Trigger a browser download for an authenticated file endpoint. */
export async function download(url: string, fallbackName = 'download'): Promise<void> {
    const res = await api.get(url, { responseType: 'blob' }).catch(unwrapBlobError);
    const disposition = (res.headers['content-disposition'] as string | undefined) ?? '';
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition);
    const name = match ? decodeURIComponent(match[1]) : fallbackName;
    const href = URL.createObjectURL(res.data as Blob);
    const a = document.createElement('a');
    a.href = href;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(href), 10_000);
}

/** Open an authenticated PDF/image in a new tab (blob URL, so the session cookie is used). */
export async function openInline(url: string): Promise<void> {
    const win = window.open('', '_blank');
    const res = await api.get(url, { responseType: 'blob', params: { inline: 1 } }).catch((e) => { win?.close(); return unwrapBlobError(e); });
    const href = URL.createObjectURL(res.data as Blob);
    if (win) win.location.href = href;
    else window.location.href = href;
}

export async function ensureCsrf(): Promise<void> {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}
