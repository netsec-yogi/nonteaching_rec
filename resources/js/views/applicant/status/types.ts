/** Shapes returned by Applicant\ApplicationController (dashboard, status). */

export interface DashboardApplication {
    uuid: string; reference_no: string | null; status: 'draft' | 'submitted' | 'reopened' | 'withdrawn' | string;
    post: string; post_code: string; advertisement: string; advertisement_title: string;
    closes_at: string; submitted_at: string | null; updated_at: string | null; editable: boolean;
    reopened_until: string | null; stage: string; fee_status: string; referee_status: string;
    open_clarifications: number; version: number;
}

export interface DashboardOpenAd {
    id: number; number: string; title: string; closes_at: string;
    posts: { id: number; code: string; name: string; total_vacancies: number; pay_level: string | null; applied: boolean }[];
}

export interface AppNotification {
    id: string; created_at: string;
    data: { title?: string; message?: string; link?: string | null };
}

export interface DashboardPayload {
    email_verified: boolean;
    applications: DashboardApplication[];
    open_advertisements: DashboardOpenAd[];
    notifications: AppNotification[];
}

export interface StatusReferee { position: number; name: string; received: boolean; status: string }
export interface StatusVersion { version: number; created_at: string | null; reason: string | null; has_pdf: boolean }
export interface StatusHistory { field: 'status' | 'fee_status' | string; to: string | null; remarks: string | null; at: string | null }
export interface StatusClarification {
    id: number; subject: string; message: string; status: string; due_at: string | null;
    allow_uploads: boolean; can_respond: boolean; created_at: string | null;
    responses: { message: string; at: string | null }[];
}

export interface StatusPayload {
    uuid: string; reference_no: string | null; status: string; stage: string; post: string; advertisement: string;
    submitted_at: string | null; fee_status: string; endorsement_status: string; fee_remarks?: string | null; endorsement_remarks?: string | null; can_withdraw: boolean;
    referees: StatusReferee[]; versions: StatusVersion[]; history: StatusHistory[]; clarifications: StatusClarification[];
}

export const feeLabels: Record<string, string> = {
    not_submitted: 'Not yet submitted', pending_verification: 'Awaiting verification', verified: 'Verified',
    rejected: 'Not accepted — see remarks', exempt: 'Exempted',
};

export const endorsementLabels: Record<string, string> = {
    not_applicable: 'Not applicable', pending: 'Awaiting endorsement', submitted: 'Submitted — awaiting verification',
    verified: 'Verified', rejected: 'Not accepted — see remarks',
};

export const refereeLabels: Record<string, string> = {
    not_started: 'Not started', pending: 'Awaiting recommendations', partial: 'Some received',
    complete: 'All received', exception: 'Exception approved',
};

export const statusLabels: Record<string, string> = {
    draft: 'Draft', submitted: 'Submitted', reopened: 'Reopened', withdrawn: 'Withdrawn',
};

/** Whole days from now until `iso` (negative when past). */
export function daysUntil(iso?: string | null): number | null {
    if (!iso) return null;
    const t = new Date(iso).getTime();
    if (Number.isNaN(t)) return null;
    return Math.floor((t - Date.now()) / 86_400_000);
}
