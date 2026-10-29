/**
 * State shared between the wizard shell and its step components, passed
 * with provide/inject so steps need only their own form object as a prop.
 */
import { inject, type ComputedRef, type InjectionKey, type Ref } from 'vue';
import type { ValidationErrors } from '@/api/client';
import type { ApplicationPayload, DocumentableType, DocumentSummary, StepKey } from './types';

export interface WizardContext {
    uuid: string;
    payload: Ref<ApplicationPayload>;
    errors: ValidationErrors;
    /** True when the current step cannot be edited (submitted, or not reopened for correction). */
    readonly: ComputedRef<boolean>;
    setDocuments: (docs: DocumentSummary[]) => void;
}

export const wizardKey: InjectionKey<WizardContext> = Symbol('wizard');

export function useWizard(): WizardContext {
    const ctx = inject(wizardKey);
    if (!ctx) throw new Error('Wizard context missing');
    return ctx;
}

/** Document types attached to rows of a repeatable step. */
export const ROW_DOCUMENTS: Partial<Record<StepKey, { type: string; documentable: DocumentableType }>> = {
    qualifications: { type: 'qualification_certificate', documentable: 'qualification' },
    employment: { type: 'experience_certificate', documentable: 'employment' },
    merit: { type: 'merit_certificate', documentable: 'merit_record' },
    training: { type: 'training_certificate', documentable: 'training' },
    memberships: { type: 'membership_certificate', documentable: 'membership' },
};

export const PAYMENT_METHOD_LABELS: Record<string, string> = {
    neft: 'NEFT / RTGS',
    upi: 'UPI / QR code',
    demand_draft: 'Demand Draft',
    online: 'Online',
};

export const DECLARATION_LABELS: Record<string, string> = {
    sec26a: '26 (a)', sec26b: '26 (b)', sec27: '27', sec28: '28', sec29: '29', sec30: '30',
};

export function clone<T>(value: T): T {
    return JSON.parse(JSON.stringify(value)) as T;
}

/** Today's date as Y-m-d in local time (for max= on date inputs). */
export function today(): string {
    const d = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/**
 * Whole months between two Y-m-d dates, counting a part month of half or more
 * as a full month — mirrors EmploymentSection::months() on the server.
 */
export function monthsBetween(from?: string | null, to?: string | null): number | null {
    if (!from) return null;
    const a = new Date(from + 'T00:00:00');
    const b = to ? new Date(to + 'T00:00:00') : new Date();
    if (Number.isNaN(a.getTime()) || Number.isNaN(b.getTime()) || b < a) return null;
    let months = (b.getFullYear() - a.getFullYear()) * 12 + (b.getMonth() - a.getMonth());
    let anchor = new Date(a.getFullYear(), a.getMonth() + months, a.getDate());
    if (anchor > b) {
        months -= 1;
        anchor = new Date(a.getFullYear(), a.getMonth() + months, a.getDate());
    }
    const next = new Date(a.getFullYear(), a.getMonth() + months + 1, a.getDate());
    const fraction = (b.getTime() - anchor.getTime()) / (next.getTime() - anchor.getTime());
    return Math.max(0, months + (fraction >= 0.5 ? 1 : 0));
}

/** All error messages whose key starts with `prefix` (e.g. "rows.2."). */
export function errorsUnder(errors: ValidationErrors, prefix: string): string[] {
    return Object.entries(errors).filter(([k]) => k.startsWith(prefix)).flatMap(([, v]) => v);
}

const rowKeys = new WeakMap<object, number>();
let rowSeq = 0;
/** Stable v-for key for rows that may not have a database id yet. */
export function rowKey(row: object): number {
    let k = rowKeys.get(row);
    if (k === undefined) {
        k = ++rowSeq;
        rowKeys.set(row, k);
    }
    return k;
}
