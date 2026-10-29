/**
 * Filter vocabularies for the staff application list and reports. Mirrors
 * App\Models\Application status constants and config/recruitment.php.
 */
import { api } from '@/api/client';

export const CATEGORIES: Record<string, string> = { UR: 'UR (Unreserved)', SC: 'SC', ST: 'ST', OBC: 'OBC (Non-Creamy Layer)', EWS: 'EWS' };
export const GENDERS: Record<string, string> = { male: 'Male', female: 'Female', transgender: 'Transgender' };

export const STATUS_OPTIONS: Record<string, string[]> = {
    status: ['draft', 'submitted', 'reopened', 'withdrawn'],
    scrutiny_status: ['submitted', 'received', 'assigned', 'in_progress', 'clarification_required', 'clarification_received', 'completed', 'under_review', 'decided'],
    fee_status: ['not_submitted', 'pending_verification', 'verified', 'rejected', 'exempt', 'not_required'],
    referee_status: ['not_started', 'pending', 'partial', 'complete', 'exception'],
    endorsement_status: ['not_applicable', 'pending', 'submitted', 'verified', 'rejected'],
    document_status: ['pending', 'verified', 'deficient'],
    outcome: ['recommended', 'incomplete', 'not_recommended'],
    committee_status: ['pending', 'recommended', 'not_recommended', 'further_review'],
};

export const STATUS_LABELS: Record<string, string> = {
    status: 'Application status', scrutiny_status: 'Scrutiny status', fee_status: 'Fee status', referee_status: 'Referee status',
    endorsement_status: 'Endorsement', document_status: 'Documents', outcome: 'Scrutiny outcome', committee_status: 'Committee status',
};

/** All filter keys accepted by App\Services\ApplicationFilter::FILTERS. */
export const FILTER_KEYS = [
    'advertisement_id', 'cycle_id', 'post_id', 'department', 'q', 'reference_no', 'category', 'gender',
    'date_from', 'date_to', ...Object.keys(STATUS_OPTIONS), 'submitted_only',
] as const;

export type Filters = Record<string, string>;

export function emptyFilters(): Filters {
    return Object.fromEntries(FILTER_KEYS.map((k) => [k, ''])) as Filters;
}

/** Only the filters that carry a value (for API params and URL query). */
export function activeFilters(f: Filters): Filters {
    return Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null && v !== undefined));
}

export interface AdOption {
    id: number; number: string; title: string; status: string; recruitment_cycle_id: number | null;
    posts: { id: number; advertisement_id: number; code: string; name: string; department: string | null }[];
}

export interface CycleOption { id: number; name: string }

export interface UserOption { id: number; name: string; email: string; designation: string | null; roles?: { id: number; slug: string }[] }

let adCache: Promise<AdOption[]> | null = null;
export function loadAdOptions(): Promise<AdOption[]> {
    adCache ??= api.get<AdOption[]>('/admin/advertisement-options').then((r) => r.data).catch((e) => { adCache = null; throw e; });
    return adCache;
}

/** Recruitment cycles (names only; available to all staff). */
export async function loadCycleOptions(ads: AdOption[]): Promise<CycleOption[]> {
    try {
        const { data } = await api.get<{ id: number; name: string; year?: string }[]>('/admin/cycle-options');
        return data.map((c) => ({ id: c.id, name: c.year ? `${c.name}` : c.name }));
    } catch {
        const ids = [...new Set(ads.map((a) => a.recruitment_cycle_id).filter((x): x is number => !!x))];
        return ids.map((id) => ({ id, name: `Cycle #${id}` }));
    }
}

export async function loadUserOptions(roles: string[]): Promise<UserOption[]> {
    const { data } = await api.get<UserOption[]>('/admin/user-options', { params: { roles } });
    return data;
}

export interface StageOption { id: number; sequence: number; code: string; name: string; role: string; is_decision_stage: boolean }
export interface CommitteeOption { id: number; name: string; post_id: number | null; members?: { id: number; name: string }[] }

/** Advertisement detail (visible to staff assigned to the advertisement) carries its stages and committees. */
export async function loadWorkflow(advertisementId: number): Promise<{ stages: StageOption[]; committees: CommitteeOption[]; committeeError: string | null }> {
    let data: { scrutiny_stages?: StageOption[]; committees?: CommitteeOption[] } = {};
    try {
        data = (await api.get<typeof data>(`/admin/advertisements/${advertisementId}`)).data;
    } catch {
        // Not assigned to this advertisement: fall through with what is available.
    }
    let committees = data.committees ?? [];
    let committeeError: string | null = null;
    if (!committees.length) {
        try {
            committees = (await api.get<CommitteeOption[]>(`/admin/advertisements/${advertisementId}/committees`)).data;
        } catch (e) {
            const status = (e as { response?: { status?: number } }).response?.status;
            committeeError = status === 403 ? 'You do not have permission to list committees for this advertisement. Enter the committee ID if you know it.' : 'Could not load committees.';
        }
    }
    return { stages: [...(data.scrutiny_stages ?? [])].sort((a, b) => a.sequence - b.sequence), committees, committeeError };
}
