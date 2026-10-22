/** Shapes returned by PublicController. */

export interface AdPostSummary {
    id: number; code: string; name: string; department: string | null; pay_level: string | null; total_vacancies: number; status: string;
}

export interface AdDocument { id: number; title: string | null; original_name: string; size: number | null }

export interface AdvertisementSummary {
    id: number; number: string; title: string; department: string | null; summary: string | null;
    publication_date: string | null; opens_at: string; closes_at: string; status: string; is_open: boolean;
    contact_email: string | null; posts: AdPostSummary[]; documents: AdDocument[];
}

export interface FeeSchedule { default?: number | string | null; exemptions?: Record<string, number | string> | unknown[] }

export interface AdPostDetail {
    id: number; code: string; name: string; department: string | null; group_classification: string | null;
    pay_level: string | null; pay_scale: string | null; total_vacancies: number;
    age_min: number | null; age_max: number | null; age_relaxation: string | null; age_cutoff_date: string | null;
    essential_qualifications: string | null; desirable_qualifications: string | null;
    experience_requirements: string | null; other_conditions: string | null; status: string;
    vacancies: { category: string; count: number; remarks: string | null }[];
    fee: FeeSchedule; accepting: boolean;
}

export interface AdvertisementDetail extends Omit<AdvertisementSummary, 'posts'> {
    instructions: string | null; reservation_details: string | null; age_relaxation_rules: string | null;
    fee_rules_text: string | null; payment_instructions: string | null;
    endorsement_mode: 'not_applicable' | 'optional' | 'required_for_in_service' | string;
    endorsement_instructions: string | null; referees_required: number | null; cycle: string | null;
    posts: AdPostDetail[];
}

export type PublicSettings = Partial<Record<
    'institute.name' | 'institute.short_name' | 'institute.address' | 'institute.recruitment_email' | 'institute.recruitment_phone' | 'privacy.notice',
    string | null
>>;

export const documentUrl = (adId: number, docId: number) => `/api/public/advertisements/${adId}/documents/${docId}`;
