/** Display helpers shared by every view. Dates are shown in Indian format. */

export function formatDate(value?: string | null): string {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
}

export function formatDateTime(value?: string | null): string {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export function formatMoney(value?: number | string | null): string {
    if (value === null || value === undefined || value === '') return '—';
    return '₹ ' + Number(value).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function formatSize(bytes?: number | null): string {
    if (!bytes) return '—';
    return bytes >= 1024 * 1024 ? (bytes / 1024 / 1024).toFixed(1) + ' MB' : Math.round(bytes / 1024) + ' KB';
}

export function humanize(value?: string | null): string {
    if (!value) return '—';
    return value.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}

export function wordCount(text?: string | null): number {
    const t = (text ?? '').trim();
    return t === '' ? 0 : t.split(/\s+/u).length;
}

export function monthsToText(months?: number | null): string {
    const m = months ?? 0;
    return `${Math.floor(m / 12)} yr ${m % 12} mo`;
}

/** Bootstrap colour for a status value used anywhere in the portal. */
export function statusColor(value?: string | null): string {
    const map: Record<string, string> = {
        draft: 'secondary', submitted: 'primary', reopened: 'warning', withdrawn: 'dark',
        received: 'info', assigned: 'info', in_progress: 'info', under_review: 'info',
        clarification_required: 'warning', clarification_received: 'warning', completed: 'success', decided: 'success',
        recommended: 'success', incomplete: 'warning', not_recommended: 'danger', further_review: 'warning', pending: 'secondary',
        not_submitted: 'secondary', pending_verification: 'warning', verified: 'success', rejected: 'danger', exempt: 'success',
        not_applicable: 'light', not_started: 'secondary', partial: 'warning', complete: 'success', exception: 'info',
        deficient: 'danger', sent: 'success', queued: 'info', failed: 'danger', not_sent: 'secondary',
        expired: 'dark', revoked: 'dark', replaced: 'dark', not_invited: 'secondary', active: 'info',
        published: 'success', suspended: 'warning', closed: 'secondary', archived: 'dark', open: 'success',
        not_verified: 'danger', responded: 'info', resolved: 'success',
    };
    return map[value ?? ''] ?? 'secondary';
}
