/**
 * Shapes returned by the staff application profile (Admin ApplicationController@show),
 * the scrutiny context (ScrutinyController@context) and the consolidated
 * scrutiny report (ScrutinyService::report). Shared by the scrutiny and
 * committee workspaces.
 */
import type { Snapshot, StaffDocument } from '@/components/staff/types';

export type ItemStatus = 'pending' | 'verified' | 'not_verified' | 'not_applicable';

export interface ChecklistEntry {
    checklist_item_id: number;
    code: string;
    label: string;
    section: string | null;
    instructions: string | null;
    is_mandatory: boolean;
    requires_reason: boolean;
    document_type: string | null;
    candidate_status: string | null;
    candidate_page_reference: string | null;
    candidate_document_id: number | null;
    status: ItemStatus;
    remarks: string | null;
    discrepancy_reason: string | null;
    document_id: number | null;
    verified_by: string | null;
    verified_at: string | null;
}

export interface EligibilityRow { label: string; type: string; is_essential: boolean; result: 'met' | 'not_met' | 'manual' | string; basis: string }

export interface Observation { id: number; section: string | null; observation: string; is_deficiency: boolean; by: string | null; at: string | null; resolved_at: string | null }

export interface ClarificationDocument {
    uuid: string; id: number; document_type: string | null; document_type_name: string | null; label: string | null;
    original_name: string; mime: string; size: number; uploaded_at: string | null;
}

export interface Clarification {
    id: number; subject: string; message: string; status: string; by: string | null;
    due_at: string | null; created_at: string | null; resolution_remarks: string | null;
    responses: { message: string; at: string | null; documents: ClarificationDocument[] }[];
}

export interface RefereeRow {
    position: number; referee_id: number; name: string; designation: string | null; email: string;
    delivery_status: string; recommendation_status: string; sent_at: string | null; expires_at: string | null;
    reminder_count: number; opened: boolean; submitted_at: string | null; response_id: number | null; response_reference: string | null;
}

export interface RecommendationSummary { id: number; position: number; reference_no: string; referee_name: string; overall_recommendation: string; submitted_at: string | null }

export interface ScrutinyReviewRow {
    stage: string; by: string | null; completeness: string | null; recommendation: string | null; decision: string | null;
    reasons: string | null; summary: string | null; submitted_at: string | null;
}

export interface ScrutinyStageInfo { id: number; sequence?: number; code: string; name: string; role?: string; is_decision_stage: boolean; allow_same_reviewer: boolean }

export interface ApplicationProfile {
    application: {
        uuid: string; reference_no: string | null; status: string; scrutiny_status: string | null; outcome: string | null;
        committee_status: string | null; fee_status: string | null; endorsement_status: string | null; referee_status: string | null;
        document_status: string | null; version: number; is_in_service: boolean; submitted_at: string | null;
        current_stage: { id: number; name: string; code: string; is_decision_stage: boolean } | null;
        advertisement: { id: number; number: string; title: string; referees_required: number; referee_recommendations_mandatory: boolean };
        post: { id: number; code: string; name: string; department: string | null };
    };
    snapshot: Snapshot;
    live_differs: boolean;
    documents: StaffDocument[];
    referees: RefereeRow[] | null;
    recommendations: RecommendationSummary[] | null;
    scrutiny: {
        stages: ScrutinyStageInfo[];
        assignments: { id: number; user: string | null; user_id: number; stage: string | null; stage_id: number; status: string; assigned_at: string | null; submitted_at: string | null }[];
        reviews: ScrutinyReviewRow[];
        checklist: ChecklistEntry[];
        observations: Observation[];
        eligibility: EligibilityRow[];
    };
    clarifications: Clarification[];
    committee: {
        assignments: { committee: string | null; at: string | null }[];
        decisions: { committee: string | null; decision: string; reasons: string | null; by: string | null; at: string | null }[];
    };
    section_keys: { key: string; title: string; form_reference: string | null }[];
    can: Record<string, boolean>;
}

export interface RequiredDocument {
    document_type: string; name: string; mandatory: boolean; condition: string; uploaded: number; missing_rows: number | null;
    status: 'missing' | 'not_provided' | 'verified' | 'rejected' | 'pending' | 'not_applicable';
    documents: { id: number; uuid: string; name: string; verification_status: string; remarks: string | null }[];
}

export interface ScrutinyContext {
    assignment: { id: number; status: string } | null;
    stage: { id: number; name: string; code: string; is_decision_stage: boolean; allow_same_reviewer: boolean } | null;
    can_act: boolean;
    can_correspond: boolean;
    checklist: ChecklistEntry[];
    documents: RequiredDocument[];
    draft_review: { completeness: string | null; recommendation: string | null; decision: string | null; reasons: string | null; summary: string | null; status: string } | null;
}

export interface ScrutinyReport {
    generated_at: string;
    header: { candidate: string | null; reference_no: string | null; advertisement_number: string; post: string; category: string | null };
    status: Record<string, string | null>;
    items: ChecklistEntry[];
    eligibility: EligibilityRow[];
    observations: { section: string | null; text: string; deficiency: boolean; by: string | null; at: string | null; resolved_at: string | null }[];
    deficiencies: string[];
    clarifications: { subject: string; issued_at: string | null; due_at: string | null; status: string; resolution: string | null }[];
    reviews: { stage: string; by: string | null; completeness: string | null; recommendation: string | null; decision: string | null; reasons: string | null; submitted_at: string | null }[];
}

export const ITEM_STATUSES: { value: ItemStatus; label: string; icon: string; btn: string }[] = [
    { value: 'verified', label: 'Verified', icon: 'bi-check-circle', btn: 'btn-outline-success' },
    { value: 'not_verified', label: 'Not verified', icon: 'bi-x-circle', btn: 'btn-outline-danger' },
    { value: 'not_applicable', label: 'Not applicable', icon: 'bi-dash-circle', btn: 'btn-outline-secondary' },
    { value: 'pending', label: 'Pending', icon: 'bi-hourglass', btn: 'btn-outline-warning' },
];

/** URL of an uploaded document for openInline()/download(). */
export const documentUrl = (applicationUuid: string, documentUuid: string) => `/applications/${applicationUuid}/documents/${documentUuid}`;
