/** Shape of Admin\ApplicationController@show. */
import type { Snapshot, StaffDocument } from '@/components/staff/types';

export interface ProfileCan {
    verify: boolean; assign: boolean; reopen: boolean; correspond: boolean; download: boolean; referee_status: boolean;
    recommendations: boolean; manage_referees: boolean; referee_exceptions: boolean; history: boolean; audit: boolean; scrutinize: boolean;
}

export interface RefereeRow {
    position: number; referee_id: number; name: string; designation: string; email: string; invitation_uuid: string | null;
    delivery_status: 'not_sent' | 'queued' | 'sent' | 'failed' | string;
    recommendation_status: 'not_invited' | 'pending' | 'completed' | 'expired' | 'revoked' | 'replaced' | string;
    sent_at: string | null; expires_at: string | null; reminder_count: number; opened: boolean; delivery_error: string | null;
    submitted_at: string | null; response_id: number | null; response_reference: string | null;
    history: { uuid: string; email: string; state: string; delivery_status: string; created_at: string | null; expires_at: string | null; revocation_reason: string | null }[];
}

export interface ProfileApplication {
    uuid: string; reference_no: string | null; status: string; scrutiny_status: string | null; outcome: string | null; committee_status: string | null;
    fee_status: string | null; endorsement_status: string | null; referee_status: string | null; document_status: string | null; version: number;
    is_in_service: boolean; referee_email_exception: boolean; referee_requirement_waived: boolean; referee_waiver_reason: string | null;
    withdrawal_reason: string | null; submitted_at: string | null; first_submitted_at: string | null; reopened_until: string | null;
    current_stage: { id: number; name: string; code: string; is_decision_stage: boolean } | null;
    advertisement: { id: number; number: string; title: string; referees_required: number; referee_recommendations_mandatory: boolean };
    post: { id: number; code: string; name: string; department: string | null };
    candidate_email_verified: boolean;
}

export interface ChecklistRow {
    checklist_item_id: number; code: string | null; label: string; section: string | null; instructions: string | null; is_mandatory: boolean;
    requires_reason: boolean; document_type: string | null; candidate_status: string | null; candidate_page_reference: string | null;
    candidate_document_id: number | null; status: string; remarks: string | null; discrepancy_reason: string | null; document_id: number | null;
    verified_by: string | null; verified_at: string | null;
}

export interface Profile {
    application: ProfileApplication;
    snapshot: Snapshot;
    live_differs: boolean;
    versions: { version: number; reason: string | null; created_at: string | null; by: string | null; has_pdf: boolean; pdf_sha256: string | null }[];
    documents: StaffDocument[];
    payment: null | {
        method: string | null; utr_number: string | null; bank_name: string | null; status: string; remarks: string | null; exemption_basis: string | null;
        amount: number; amount_due: number; transaction_date: string | null; verified_at: string | null; config_version: number | null;
        paid_to: { account_holder: string | null; bank_name: string | null; ifsc: string | null; account_number_last4: string | null; upi_id: string | null } | null;
        history: { status: string; remarks: string | null; by: string | null; at: string | null }[];
    };
    identity: { pan: string | null; aadhaar: string | null; can_reveal: boolean };
    endorsement: null | { verification_status: string | null; remarks: string | null; verified_by: string | null; verified_at: string | null };
    referees: RefereeRow[] | null;
    recommendations: { id: number; position: number; reference_no: string; referee_name: string; overall_recommendation: string; submitted_at: string | null }[] | null;
    scrutiny: {
        stages: { id: number; sequence: number; code: string; name: string; role: string; is_decision_stage: boolean; allow_same_reviewer: boolean }[];
        assignments: { id: number; user: string | null; user_id: number; stage: string | null; stage_id: number; status: string; assigned_at: string | null; submitted_at: string | null }[];
        reviews: { stage: string; by: string | null; completeness: string | null; recommendation: string | null; decision: string | null; reasons: string | null; summary: string | null; submitted_at: string | null }[];
        checklist: ChecklistRow[];
        observations: { id: number; section: string | null; observation: string; is_deficiency: boolean; by: string | null; at: string | null; resolved_at: string | null }[];
        eligibility: { label: string; type: string; is_essential: boolean; result: 'met' | 'not_met' | 'manual' | string; basis: string }[];
    };
    clarifications: {
        id: number; subject: string; message: string; status: string; by: string | null; due_at: string | null; created_at: string | null; resolution_remarks: string | null;
        responses: { message: string | null; at: string | null; documents: StaffDocument[] }[];
    }[];
    committee: {
        assignments: { committee: string | null; at: string | null }[];
        decisions: { committee: string | null; decision: string; reasons: string | null; by: string | null; at: string | null }[];
    };
    reopenings: { reason: string; by: string | null; sections: string[] | null; from_version: number | null; until: string | null; resubmitted_at: string | null; at: string | null }[];
    section_keys: { key: string; title: string; form_reference: string | null }[];
    can: ProfileCan;
}

export interface Recommendation {
    id: number; reference_no: string; referee_name: string; referee_designation: string; referee_organization: string; referee_email: string;
    referee_phone: string | null; relationship: string; known_duration: string; nature_of_association: string | null;
    candidate_entered: Record<string, string | number | null>;
    answers: { code: string; label: string; type: string; options: unknown; answer: unknown }[];
    overall_recommendation: string; comments: string | null; content_hash: string; position: number; submitted_at: string;
    amendments: { field: string; original: string | null; amended: string | null; reason: string; by: string | null; at: string | null }[];
}

export interface AuditEntry {
    id: number; user_id: number | null; actor_type: string; actor_label: string | null; action: string; auditable_type: string | null; auditable_id: number | null;
    application_id: number | null; old_values: Record<string, unknown> | null; new_values: Record<string, unknown> | null; meta: Record<string, unknown> | null;
    ip_address: string | null; user_agent: string | null; created_at: string; user?: { id: number; name: string; email?: string } | null;
}
