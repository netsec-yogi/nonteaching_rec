/** Shapes returned by the Super Administrator configuration endpoints. */

export interface FeeSchedule { default: number | string; exemptions?: Record<string, number | string> | null }

export interface Cycle { id: number; name: string; year: string; description: string | null; is_active: boolean; advertisements_count?: number }

export interface Vacancy { id?: number; category: string; count: number | string; remarks: string | null }

export interface Criterion {
    id?: number; type: string; label: string; value: string | null; qualification_level: string | null;
    is_essential: boolean; description: string | null;
}

export interface Post {
    id: number; advertisement_id: number; code: string; name: string; department: string | null;
    group_classification: string | null; pay_level: string | null; pay_scale: string | null; total_vacancies: number;
    age_min: number | null; age_max: number | null; age_cutoff_date: string | null; age_relaxation: string | null;
    essential_qualifications: string | null; desirable_qualifications: string | null; experience_requirements: string | null;
    other_conditions: string | null; fee_schedule: FeeSchedule | null; endorsement_mode: string | null;
    status: 'open' | 'closed'; sort_order: number; vacancies: Vacancy[]; eligibility_criteria: Criterion[];
}

export interface AdDocument { id: number; title: string; original_name: string; size: number; is_public: boolean; created_at: string }

export interface DocumentType {
    id: number; code: string; name: string; description: string | null; allowed_mimes: string[]; max_kb: number;
    step: string | null; is_mandatory: boolean; condition: string; accepts_undertaking: boolean; is_active: boolean;
    advertisement_overrides?: number;
}

export interface Requirement {
    id: number; post_id: number | null; document_type_id: number; condition: string;
    /** null = follow the document type's default; effective_mandatory is the resulting value. */
    is_mandatory: boolean | null; effective_mandatory: boolean;
    accepts_undertaking: boolean | null; effective_accepts_undertaking: boolean;
    instructions: string | null; document_type?: DocumentType; post?: { id: number; code: string; name?: string } | null;
}

export interface Stage {
    id?: number | null; sequence?: number; code: string; name: string; role: string;
    is_decision_stage: boolean; allow_same_reviewer: boolean;
}

export interface Member { id: number; name: string; email?: string; pivot?: { is_chair?: number | boolean } }

export interface Team { id: number; name: string; post_id: number | null; members: Member[]; post?: { id: number; code: string } | null }

export interface Committee {
    id: number; name: string; post_id: number | null; evaluation_mode: string | null; evaluation_form_template_id: number | null;
    members: Member[]; post?: { id: number; code: string } | null; evaluation_form?: { id: number; name: string } | null; assignments_count?: number;
}

export interface ChecklistAssignment {
    id: number; checklist_template_id: number; advertisement_id: number; post_id: number | null;
    template?: { id: number; name: string }; post?: { id: number; code: string } | null;
    advertisement?: { id: number; number: string; title?: string };
}

export interface AdvertisementDetail {
    id: number; recruitment_cycle_id: number; number: string; title: string; publication_date: string | null;
    department: string | null; summary: string | null; instructions: string | null; reservation_details: string | null;
    age_relaxation_rules: string | null; fee_rules_text: string | null; payment_instructions: string | null;
    opens_at: string; closes_at: string; status: string; reference_prefix: string; fee_schedule: FeeSchedule | null;
    payment_methods: string[]; referees_required: number; referee_recommendations_mandatory: boolean;
    referee_deadline_days: number; referee_reminder_days: number[] | null; referee_final_reminder_days_before: number | null;
    referee_max_reminders: number; referee_extra_verification: boolean; referee_form_template_id: number | null;
    endorsement_mode: string; endorsement_instructions: string | null; committee_evaluation_mode: string;
    identity_number_requirement?: string;
    evaluation_form_template_id: number | null; allow_withdrawal: boolean; allow_clarification_uploads: boolean;
    retention_years: number | null; contact_email: string | null; contact_phone: string | null;
    published_at: string | null; archived_at: string | null;
    cycle?: Cycle; posts: Post[]; documents: AdDocument[]; scrutiny_stages: Stage[];
    administrators: { id: number; name: string; email: string }[]; document_requirements: Requirement[];
    checklist_assignments: ChecklistAssignment[]; scrutiny_teams: Team[]; committees: Committee[];
    publish_problems: string[]; allowed_transitions: string[]; application_counts: Record<string, number>;
}

export interface UserOption { id: number; name: string; email: string; designation: string | null }

export interface FormQuestion {
    id?: number; code: string; label: string; help_text: string | null; type: string;
    options: { min?: number; max?: number; labels?: Record<string, string>; choices?: string[] } | null; is_required: boolean;
}

export interface FormTemplate {
    id: number; name: string; introduction?: string | null; description?: string | null; good_faith_statement?: string | null;
    recommendation_options: string[]; is_active: boolean; questions?: FormQuestion[]; fields?: FormQuestion[];
}

export interface ChecklistItem {
    id?: number; code: string; label: string; verification_instructions: string | null; section: string;
    document_type_id: number | null; is_mandatory: boolean; requires_reason_if_not_verified: boolean; applicant_visible: boolean;
}

export interface ChecklistTemplate {
    id: number; name: string; description: string | null; copied_from_id: number | null; archived_at: string | null;
    items_count?: number; items?: ChecklistItem[]; assignments?: ChecklistAssignment[]; in_use?: boolean; updated_at?: string;
}

export const ENDORSEMENT_MODES: Record<string, string> = {
    not_applicable: 'Not applicable',
    optional: 'Optional',
    required_for_in_service: 'Required for in-service candidates',
};

export const IDENTITY_REQUIREMENTS: Record<string, string> = {
    optional: 'Optional (neither required)', pan: 'PAN required', aadhaar: 'Aadhaar required', pan_or_aadhaar: 'PAN or Aadhaar required',
};

export const PAYMENT_METHODS: Record<string, string> = { neft: 'NEFT / RTGS', upi: 'UPI / bank QR code', demand_draft: 'Demand Draft', online: 'Online (transaction reference recorded; no gateway)' };

export const FEE_CATEGORIES = ['SC', 'ST', 'PWD', 'FEMALE', 'TRANSGENDER', 'ESM', 'OBC', 'EWS'];

export const STAGE_ROLES: Record<string, string> = {
    scrutiny_user: 'Scrutiny User',
    recruitment_admin: 'Recruitment Administrator',
    super_admin: 'Super Administrator',
};

export const QUALIFICATION_LEVELS: Record<string, string> = {
    class_10: 'Class 10 / Matriculation',
    class_12: 'Class 12 / Higher Secondary',
    diploma: 'Diploma',
    graduation: 'Graduation',
    post_graduation: 'Post Graduation',
    doctorate: 'Doctorate',
    professional: 'Professional Qualification',
    technical: 'Technical Qualification / Certificate',
    training: 'Training',
    other: 'Other',
};

export const CRITERION_TYPES: Record<string, string> = {
    min_percentage: 'Minimum percentage',
    min_experience_months: 'Minimum experience (months)',
    max_age: 'Maximum age (years)',
    qualification_level: 'Qualification level',
    custom: 'Custom (manual check)',
};

export const QUESTION_TYPES: Record<string, string> = {
    yes_no: 'Yes / No',
    rating: 'Rating scale',
    choice: 'Single choice',
    short_text: 'Short text',
    long_text: 'Long text',
};

export const STAFF_ROLES: Record<string, string> = {
    super_admin: 'Super Administrator',
    recruitment_admin: 'Recruitment Administrator',
    scrutiny_user: 'Scrutiny User',
    committee_member: 'Screening Committee Member',
};

/** Move an array element up (-1) or down (+1) in place. */
export function move<T>(list: T[], index: number, delta: number): void {
    const to = index + delta;
    if (to < 0 || to >= list.length) return;
    const [item] = list.splice(index, 1);
    list.splice(to, 0, item);
}

/** Nested validation errors for one row, e.g. prefix "criteria.2." → { type: [...] }. */
export function rowErrors(errors: Record<string, string[]>, prefix: string): Record<string, string[]> {
    const out: Record<string, string[]> = {};
    for (const [k, v] of Object.entries(errors)) if (k.startsWith(prefix)) out[k.slice(prefix.length)] = v;
    return out;
}

/** Normalise a fee schedule for submission (numbers; empty → null). */
export function cleanFee(fee: FeeSchedule | null): FeeSchedule | null {
    if (!fee || fee.default === '' || fee.default === null || fee.default === undefined) return null;
    const exemptions: Record<string, number> = {};
    for (const [k, v] of Object.entries(fee.exemptions ?? {})) {
        if (k.trim() && v !== '' && v !== null) exemptions[k.trim().toUpperCase()] = Number(v);
    }
    return { default: Number(fee.default), exemptions };
}

export interface FeeConfigSummary {
    id: number;
    advertisement: { id: number; number: string; title: string };
    post: { id: number; code: string; name: string } | null;
    label: string | null; bank_name: string | null; account_number_masked: string | null; ifsc: string | null; upi_id: string | null;
    has_qr: boolean; is_active: boolean; version: number; fee_schedule: FeeSchedule | null; payments_count: number;
    updated_by: string | null; updated_at: string | null;
}

export interface FeeConfigDetail extends FeeConfigSummary {
    account_holder: string | null; branch_name: string | null; account_number: string | null; account_type: string | null;
    branch_address: string | null; instructions: string | null; reference_instructions: string | null; payment_deadline: string | null;
    payment_methods: string[];
    qr: { mime: string; size: number; width: number; height: number; available: boolean } | null;
    activation_problems: string[]; affected_applications: number;
    revisions: { version: number; event: string; reason: string | null; by: string | null; at: string | null }[];
}

export interface FeeConfigAdvertisement {
    id: number; number: string; title: string; status: string; payment_methods: string[]; fee_schedule: FeeSchedule | null;
    posts: { id: number; code: string; name: string; fee_schedule: FeeSchedule | null }[];
}
