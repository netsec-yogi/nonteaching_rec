/** Shape of App\Services\ApplicationForm\SnapshotBuilder::build() — the frozen, human-readable application. */
export interface Snapshot {
    meta: { reference_no: string | null; uuid: string; version: number; status: string; submitted_at: string | null; generated_at: string; institute: string; institute_address: string };
    payment: { method: string | null; utr_number: string | null; transaction_date: string | null; amount: number | null; amount_due: number | null; bank_name: string | null; exemption_basis: string | null };
    application: { advertisement_number: string; advertisement_title: string; application_date: string | null; post: string; post_code: string; department: string | null };
    personal: Record<string, string | number | null> & { full_name: string | null; relation_label: string };
    qualifications: { level: string; examination: string; board_university: string; duration: string | null; year_of_passing: number; division: string | null; percentage: number | null; subjects: string | null; specialization: string | null }[];
    has_employment: boolean | null;
    is_in_service: boolean;
    employments: { organization: string; post_held: string; employment_type: string; from_date: string | null; to_date: string | null; is_current: boolean; duration: string; duration_months: number; pay_scale_level: string | null; basic_pay: number | null; gross_pay: number | null; nature_of_duties: string | null }[];
    total_experience_months: number;
    section20: Declaration;
    has_merit_records: boolean | null;
    merit_records: { title: string; awarded_by: string | null; awarded_on: string | null; description: string | null }[];
    has_training_records: boolean | null;
    trainings: { type: string; title: string; organization: string | null; from_date: string | null; to_date: string | null; duration: string | null; description: string | null }[];
    has_memberships: boolean | null;
    memberships: { institute_name: string; membership_category: string | null; membership_number: string | null; member_since: string | null }[];
    suitability_statement: string | null;
    referees: { position: number; name: string; designation: string; organization: string; address: string; phone: string; email: string; relationship: string | null; years_known: number | null; is_resident_of_india: boolean; is_not_relative: boolean; is_employer_or_superior: boolean }[];
    declarations: Record<string, Declaration>;
    other_achievements: string | null;
    candidate_declaration: { text: string; version: string | null; place: string | null; date: string | null; accepted_at: string | null };
    endorsement: Record<string, string | number | boolean | null> | null;
    checklist: { label: string; status: string; page_reference: string | null; document: string | null }[];
    documents: { id: number; uuid: string; type: string | null; label: string | null; original_name: string; size: number; uploaded_at: string | null }[];
}

export interface Declaration { code: string; question: string; answer: 'YES' | 'NO' | null; details: string | null }

/** Document row in staff views (Admin ApplicationController@show → documents). */
export interface StaffDocument {
    uuid: string; id: number; document_type: string | null; document_type_name: string | null; label: string | null;
    original_name: string; mime: string; size: number; documentable_type: string | null; documentable_id: number | null;
    verification_status: string; is_frozen: boolean; uploaded_at: string | null;
    verification_remarks?: string | null; verified_by?: string | null; verified_at?: string | null; clarification_response_id?: number | null;
}
