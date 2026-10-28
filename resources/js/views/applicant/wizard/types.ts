/**
 * Shapes of the applicant wizard payload (ApplicationFormService::payload())
 * and of each step's data (Sections\*Section::data()). Each step posts back
 * the same shape it receives; server-only display fields are ignored by the
 * validator.
 */
import type { DocumentSummary, DocumentTypeInfo } from '@/components/FileUploader.vue';

export type { DocumentSummary, DocumentTypeInfo };

export type StepKey =
    | 'payment' | 'post' | 'personal' | 'ex_serviceman' | 'qualifications' | 'employment' | 'criminal'
    | 'merit' | 'training' | 'memberships' | 'suitability' | 'referees' | 'declarations' | 'achievements'
    | 'declaration' | 'endorsement' | 'checklist';

export interface StepInfo {
    key: StepKey;
    title: string;
    form_reference: string;
    applicable: boolean;
    issues: string[];
    visited: boolean;
}

export interface ApplicationMeta {
    uuid: string;
    reference_no: string | null;
    status: string;
    editable: boolean;
    editable_sections: string[] | null;
    reopen_reason: string | null;
    reopened_until: string | null;
    version: number;
    submitted_at: string | null;
    current_step: string | null;
    email_verified: boolean;
    fee: { amount: number; basis: string | null };
    advertisement: {
        id: number;
        number: string;
        title: string;
        closes_at: string;
        is_open: boolean;
        payment_methods: string[] | null;
        payment_instructions: string | null;
        fee_rules_text: string | null;
        referees_required: number;
        instructions: string | null;
    };
    post: { id: number; code: string; name: string; department: string | null; pay_level: string | null; pay_scale: string | null };
}

export interface Requirement {
    document_type: string;
    name: string;
    step: string;
    is_mandatory: boolean;
    instructions: string | null;
    satisfied: boolean;
}

export interface DeclarationQuestion { question: string; details_when: boolean | null }

export interface Options {
    categories: Record<string, string>;
    genders: Record<string, string>;
    marital_statuses: Record<string, string>;
    qualification_levels: Record<string, string>;
    employment_types: Record<string, string>;
    training_types: Record<string, string>;
    declaration_questions: Record<string, DeclarationQuestion>;
    posts: { id: number; code: string; name: string }[];
}

/* ---------- Step data ---------- */

export interface PaymentData {
    method: string | null;
    utr_number: string | null;
    transaction_date: string | null;
    amount: number | string | null;
    bank_name: string | null;
    status: string | null;
    amount_due: number;
    exemption_basis: string | null;
}

export interface PostData {
    advertisement_number: string;
    application_date: string;
    post_id: number | null;
    post_name: string;
}

export interface Address {
    line1: string | null;
    line2: string | null;
    city: string | null;
    district: string | null;
    state: string | null;
    pincode: string | null;
    country: string | null;
}

export interface PersonalData {
    salutation: string | null;
    full_name: string | null;
    relation_type: 'father' | 'husband' | null;
    relation_name: string | null;
    date_of_birth: string | null;
    place_of_birth: string | null;
    mobile: string | null;
    email: string | null;
    nationality: string | null;
    gender: string | null;
    marital_status: string | null;
    category: string | null;
    is_pwd: boolean | null;
    disability_type: string | null;
    disability_percentage: number | null;
    nearest_railway_station: string | null;
    permanent_same_as_correspondence: boolean;
    correspondence: Address;
    permanent: Address;
}

export interface ExServicemanData {
    is_ex_serviceman: boolean | null;
    ex_serviceman_category: string | null;
    ex_serviceman_details: string | null;
}

export interface QualificationRow {
    id: number | null;
    level: string | null;
    examination: string | null;
    board_university: string | null;
    duration: string | null;
    year_of_passing: number | null;
    division: string | null;
    percentage: number | null;
    subjects: string | null;
    specialization: string | null;
}
export interface QualificationsData { rows: QualificationRow[] }

export interface EmploymentRow {
    id: number | null;
    organization: string | null;
    post_held: string | null;
    employment_type: string | null;
    from_date: string | null;
    to_date: string | null;
    is_current: boolean;
    pay_scale_level: string | null;
    basic_pay: number | null;
    gross_pay: number | null;
    nature_of_duties: string | null;
    duration_months?: number | null;
}
export interface EmploymentData { has_employment: boolean | null; is_in_service: boolean | null; rows: EmploymentRow[] }

export interface YesNoDetails { answer: boolean | null; details: string | null }
export interface CriminalData { sec20: YesNoDetails }

export interface MeritRow { id: number | null; title: string | null; awarded_by: string | null; awarded_on: string | null; description: string | null }
export interface MeritData { has_merit_records: boolean | null; rows: MeritRow[] }

export interface TrainingRow {
    id: number | null; type: string | null; title: string | null; organization: string | null;
    from_date: string | null; to_date: string | null; duration: string | null; description: string | null;
}
export interface TrainingData { has_training_records: boolean | null; rows: TrainingRow[] }

export interface MembershipRow { id: number | null; institute_name: string | null; membership_category: string | null; membership_number: string | null; member_since: string | null }
export interface MembershipsData { has_memberships: boolean | null; rows: MembershipRow[] }

export interface SuitabilityData { suitability_statement: string | null; max_words: number }

export interface RefereeRow {
    position: number;
    name: string | null;
    designation: string | null;
    organization: string | null;
    address: string | null;
    phone: string | null;
    email: string | null;
    relationship: string | null;
    years_known: number | null;
    is_resident_of_india: boolean;
    is_not_relative: boolean;
    is_employer_or_superior: boolean;
}
export interface RefereesData { referees: RefereeRow[]; confirm_accuracy: boolean; required_count: number; email_exception: boolean }

export type DeclarationsData = Record<string, YesNoDetails>;

export interface AchievementsData { other_achievements: string | null }

export interface CandidateDeclarationData {
    text: string;
    version: string | null;
    affirm_true: boolean;
    affirm_read_instructions: boolean;
    affirm_not_concealed: boolean;
    affirm_consequences: boolean;
    place: string | null;
    date: string | null;
    accepted_at: string | null;
}

export interface EndorsementData {
    applicable: boolean;
    required: boolean;
    employer_organization: string | null;
    designation: string | null;
    employment_type: string | null;
    employment_start_date: string | null;
    pay_scale_level: string | null;
    basic_pay: number | null;
    next_increment_date: string | null;
    no_disciplinary_case_certified: boolean | null;
    no_objection_certified: boolean | null;
    forwarding_officer_name: string | null;
    forwarding_officer_designation: string | null;
    place: string | null;
    endorsed_on: string | null;
    instructions: string | null;
}

export interface ChecklistEntry {
    checklist_item_id: number;
    label: string;
    is_mandatory: boolean;
    document_type_code: string | null;
    candidate_status: 'yes' | 'no' | 'na' | null;
    page_reference: string | null;
    document_id: number | null;
}
export interface ChecklistData { entries: ChecklistEntry[] }

export interface StepDataMap {
    payment: PaymentData;
    post: PostData;
    personal: PersonalData;
    ex_serviceman: ExServicemanData;
    qualifications: QualificationsData;
    employment: EmploymentData;
    criminal: CriminalData;
    merit: MeritData;
    training: TrainingData;
    memberships: MembershipsData;
    suitability: SuitabilityData;
    referees: RefereesData;
    declarations: DeclarationsData;
    achievements: AchievementsData;
    declaration: CandidateDeclarationData;
    endorsement: EndorsementData;
    checklist: ChecklistData;
}

export interface ApplicationPayload {
    application: ApplicationMeta;
    steps: StepInfo[];
    data: StepDataMap;
    documents: DocumentSummary[];
    requirements: Requirement[];
    document_types: DocumentTypeInfo[];
    options: Options;
    privacy_notice: string | null;
    ready_to_submit: boolean;
}

/** Morph aliases accepted by the document upload endpoint for row attachments. */
export type DocumentableType = 'qualification' | 'employment' | 'merit_record' | 'training' | 'membership';
