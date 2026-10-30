/**
 * Completed years and months by calendar arithmetic, for immediate display while the candidate types.
 * Mirrors App\Support\Age, which is authoritative: the server recalculates the age wherever it is
 * recorded and never accepts one from the browser.
 */

interface Ymd { y: number; m: number; d: number }

const daysInMonth = (y: number, m: number) => new Date(Date.UTC(y, m, 0)).getUTCDate();

/** A real calendar date in YYYY-MM-DD form, or null. */
function parse(value?: string | null): Ymd | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? '');
    if (!match) return null;
    const [y, m, d] = match.slice(1).map(Number);
    return m >= 1 && m <= 12 && d >= 1 && d <= daysInMonth(y, m) ? { y, m, d } : null;
}

export function isoToday(): string {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

/** Null when either date is invalid or the birth date is later than `on`. */
export function ageBetween(birth?: string | null, on?: string | null): { years: number; months: number } | null {
    const b = parse(birth);
    const r = parse(on);
    if (!b || !r || birth! > on!) return null;
    let months = (r.y - b.y) * 12 + r.m - b.m;
    // A month is complete on the birth day, or on the last day of a month too short for it.
    if (r.d < Math.min(b.d, daysInMonth(r.y, r.m))) months--;
    return { years: Math.floor(months / 12), months: months % 12 };
}

export function formatAge(age: { years: number; months: number }): string {
    return `${age.years} ${age.years === 1 ? 'Year' : 'Years'}, ${age.months} ${age.months === 1 ? 'Month' : 'Months'}`;
}

/** YYYY-MM-DD → DD/MM/YYYY. */
export function formatDmy(value?: string | null): string {
    const d = parse(value?.slice(0, 10));
    return d ? `${String(d.d).padStart(2, '0')}/${String(d.m).padStart(2, '0')}/${d.y}` : '—';
}
