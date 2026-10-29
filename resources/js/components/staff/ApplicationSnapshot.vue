<script setup lang="ts">
/**
 * Read-only rendering of a submitted application in the order of the paper
 * form. Used by the staff profile, the scrutiny workspace and the committee
 * workspace. Referee recommendations are never part of the snapshot.
 */
import { computed } from 'vue';
import { formatDate, formatDateTime, formatMoney, monthsToText } from '@/utils/format';
import type { Snapshot } from './types';

const props = withDefaults(defineProps<{ snapshot: Snapshot; sections?: string[] }>(), { sections: () => [] });
const show = (key: string) => !props.sections.length || props.sections.includes(key);
const p = computed(() => props.snapshot.personal);
const dash = (v: unknown) => (v === null || v === undefined || v === '' ? '—' : String(v));
const declLabel: Record<string, string> = { sec26a: '26 (a)', sec26b: '26 (b)', sec27: '27', sec28: '28', sec29: '29', sec30: '30' };
</script>

<template>
    <div class="vstack gap-3">
        <div v-if="show('application')" class="card section-card">
            <div class="card-header">Sections 1–4 · Fee and post</div>
            <div class="card-body">
                <table class="table table-sm kv-table mb-0">
                    <tbody>
                        <tr><th>Advertisement No.</th><td>{{ snapshot.application.advertisement_number }} — {{ snapshot.application.advertisement_title }}</td></tr>
                        <tr><th>Post applied for</th><td><strong>{{ snapshot.application.post }}</strong> ({{ snapshot.application.post_code }}){{ snapshot.application.department ? ', ' + snapshot.application.department : '' }}</td></tr>
                        <tr><th>Application date</th><td>{{ formatDate(snapshot.application.application_date) }}</td></tr>
                        <tr v-if="snapshot.payment.method === 'exempt'"><th>Fee</th><td>Exempted{{ snapshot.payment.exemption_basis ? ' (' + snapshot.payment.exemption_basis + ')' : '' }}</td></tr>
                        <template v-else>
                            <tr><th>NEFT UTR / DD No.</th><td>{{ dash(snapshot.payment.utr_number) }} <span class="text-muted small">({{ dash(snapshot.payment.method) }})</span></td></tr>
                            <tr><th>Date / Amount</th><td>{{ formatDate(snapshot.payment.transaction_date) }} · {{ formatMoney(snapshot.payment.amount) }} <span class="text-muted small">(due {{ formatMoney(snapshot.payment.amount_due) }})</span></td></tr>
                            <tr><th>Bank</th><td>{{ dash(snapshot.payment.bank_name) }}</td></tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="show('personal')" class="card section-card">
            <div class="card-header">Sections 5–17 · Personal information</div>
            <div class="card-body">
                <table class="table table-sm kv-table mb-0">
                    <tbody>
                        <tr><th>5. Name in full</th><td class="fw-semibold">{{ dash(p.full_name) }}</td></tr>
                        <tr><th>6. {{ p.relation_label }}</th><td>{{ dash(p.relation_name) }}</td></tr>
                        <tr><th>7. Date &amp; place of birth</th><td>{{ formatDate(p.date_of_birth as string) }}, {{ dash(p.place_of_birth) }}</td></tr>
                        <tr><th>8. Mobile / Email</th><td>{{ dash(p.mobile) }} / {{ dash(p.email) }}</td></tr>
                        <tr><th>9–11. Nationality / Gender / Marital status</th><td>{{ dash(p.nationality) }} / {{ dash(p.gender) }} / {{ dash(p.marital_status) }}</td></tr>
                        <tr><th>12. Category</th><td>{{ dash(p.category) }}</td></tr>
                        <tr><th>13. Person with disability</th><td>{{ dash(p.is_pwd) }}<span v-if="p.is_pwd === 'Yes'"> — {{ dash(p.disability_type) }}{{ p.disability_percentage ? ` (${p.disability_percentage}%)` : '' }}</span></td></tr>
                        <tr><th>14. Nearest railway station</th><td>{{ dash(p.nearest_railway_station) }}</td></tr>
                        <tr v-if="p.pan_masked || p.aadhaar_masked"><th>PAN / Aadhaar (masked)</th><td class="font-monospace">{{ [p.pan_masked ? `PAN ${p.pan_masked}` : null, p.aadhaar_masked ? `Aadhaar ${p.aadhaar_masked}` : null].filter(Boolean).join(' · ') }}</td></tr>
                        <tr><th>15. Correspondence address</th><td>{{ dash(p.correspondence_address) }}</td></tr>
                        <tr><th>16. Permanent address</th><td>{{ dash(p.permanent_address) }}</td></tr>
                        <tr><th>17. Ex-serviceman</th><td>{{ dash(p.is_ex_serviceman) }}<span v-if="p.is_ex_serviceman === 'Yes'"> — {{ dash(p.ex_serviceman_category) }}. {{ p.ex_serviceman_details }}</span></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="show('qualifications')" class="card section-card">
            <div class="card-header">Section 18 · Qualifications</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Examination</th><th>Board / University</th><th>Duration</th><th>Year</th><th>Division / %</th><th>Subjects</th><th>Specialization</th></tr></thead>
                    <tbody>
                        <tr v-for="(q, i) in snapshot.qualifications" :key="i">
                            <td><div class="fw-semibold">{{ q.examination }}</div><div class="small text-muted">{{ q.level }}</div></td>
                            <td>{{ q.board_university }}</td><td>{{ dash(q.duration) }}</td><td>{{ q.year_of_passing }}</td>
                            <td>{{ dash(q.division) }}<span v-if="q.percentage !== null"> / {{ q.percentage.toFixed(2) }}%</span></td>
                            <td class="small">{{ dash(q.subjects) }}</td><td class="small">{{ dash(q.specialization) }}</td>
                        </tr>
                        <tr v-if="!snapshot.qualifications.length"><td colspan="7" class="text-muted">None entered.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="show('employment')" class="card section-card">
            <div class="card-header d-flex justify-content-between">
                <span>Section 19 · Employment (most recent first)</span>
                <span class="small fw-normal">Total {{ monthsToText(snapshot.total_experience_months) }} · In service: <strong>{{ snapshot.is_in_service ? 'Yes' : 'No' }}</strong></span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Department / Institute</th><th>Post</th><th>Type</th><th>From – To</th><th>Period</th><th>Scale / Pay</th><th>Nature of duty</th></tr></thead>
                    <tbody>
                        <tr v-for="(e, i) in snapshot.employments" :key="i">
                            <td>{{ e.organization }}</td><td>{{ e.post_held }}</td><td>{{ e.employment_type }}</td>
                            <td class="text-nowrap">{{ formatDate(e.from_date) }} – {{ e.is_current ? 'till date' : formatDate(e.to_date) }}</td>
                            <td class="text-nowrap">{{ e.duration }}</td>
                            <td class="small">{{ dash(e.pay_scale_level) }}<br>{{ formatMoney(e.basic_pay) }} / {{ formatMoney(e.gross_pay) }}</td>
                            <td class="small text-pre">{{ dash(e.nature_of_duties) }}</td>
                        </tr>
                        <tr v-if="!snapshot.employments.length"><td colspan="7" class="text-muted">No employment history declared.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="show('declarations')" class="card section-card">
            <div class="card-header">Sections 20 and 26–30 · Declarations</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex gap-3">
                    <span class="text-muted small" style="min-width: 3rem">20</span>
                    <span class="flex-grow-1 small">{{ snapshot.section20.question }}<div v-if="snapshot.section20.details" class="text-pre mt-1 text-body">{{ snapshot.section20.details }}</div></span>
                    <span class="fw-bold" :class="snapshot.section20.answer === 'YES' ? 'text-danger' : ''">{{ dash(snapshot.section20.answer) }}</span>
                </li>
                <li v-for="(d, code) in snapshot.declarations" :key="code" class="list-group-item d-flex gap-3">
                    <span class="text-muted small" style="min-width: 3rem">{{ declLabel[code] }}</span>
                    <span class="flex-grow-1 small">{{ d.question }}<div v-if="d.details" class="text-pre mt-1 text-body">{{ d.details }}</div></span>
                    <span class="fw-bold">{{ dash(d.answer) }}</span>
                </li>
            </ul>
        </div>

        <div v-if="show('activities')" class="card section-card">
            <div class="card-header">Sections 21–23 · Merit, training and memberships</div>
            <div class="card-body small">
                <h6 class="small text-uppercase text-muted">21. Certificates of excellence / merit</h6>
                <ul v-if="snapshot.merit_records.length" class="mb-3"><li v-for="(m, i) in snapshot.merit_records" :key="i"><strong>{{ m.title }}</strong> — {{ dash(m.awarded_by) }}, {{ formatDate(m.awarded_on) }}<span v-if="m.description">. {{ m.description }}</span></li></ul>
                <p v-else class="text-muted">None.</p>
                <h6 class="small text-uppercase text-muted">22. Training and consultancy</h6>
                <ul v-if="snapshot.trainings.length" class="mb-3"><li v-for="(t, i) in snapshot.trainings" :key="i"><span class="badge text-bg-light border me-1">{{ t.type }}</span><strong>{{ t.title }}</strong>{{ t.organization ? ', ' + t.organization : '' }} ({{ formatDate(t.from_date) }} – {{ formatDate(t.to_date) }}{{ t.duration ? ', ' + t.duration : '' }})</li></ul>
                <p v-else class="text-muted">None.</p>
                <h6 class="small text-uppercase text-muted">23. Professional memberships</h6>
                <ul v-if="snapshot.memberships.length" class="mb-0"><li v-for="(m, i) in snapshot.memberships" :key="i"><strong>{{ m.institute_name }}</strong> — {{ dash(m.membership_category) }}, No. {{ dash(m.membership_number) }}, since {{ formatDate(m.member_since) }}</li></ul>
                <p v-else class="text-muted mb-0">None.</p>
            </div>
        </div>

        <div v-if="show('suitability')" class="card section-card">
            <div class="card-header">Section 24 · Suitability for the post</div>
            <div class="card-body text-pre">{{ dash(snapshot.suitability_statement) }}</div>
        </div>

        <div v-if="show('referees')" class="card section-card">
            <div class="card-header">Section 25 · Referees (as entered by the candidate)</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>#</th><th>Name / Designation</th><th>Organisation / Address</th><th>Contact</th><th>Relationship</th><th>Conditions</th></tr></thead>
                    <tbody>
                        <tr v-for="r in snapshot.referees" :key="r.position">
                            <td>{{ r.position }}</td>
                            <td><div class="fw-semibold">{{ r.name }}</div><div class="small">{{ r.designation }}</div></td>
                            <td class="small">{{ r.organization }}<br><span class="text-muted">{{ r.address }}</span></td>
                            <td class="small">{{ r.email }}<br>{{ r.phone }}</td>
                            <td class="small">{{ dash(r.relationship) }}<br><span class="text-muted">{{ r.years_known ?? '—' }} years</span></td>
                            <td class="small">
                                <div><i class="bi" :class="r.is_resident_of_india ? 'bi-check-circle text-success' : 'bi-x-circle text-danger'"></i> Resident of India</div>
                                <div><i class="bi" :class="r.is_not_relative ? 'bi-check-circle text-success' : 'bi-x-circle text-danger'"></i> Not a relative</div>
                                <div v-if="r.is_employer_or_superior"><i class="bi bi-briefcase"></i> Employer / superior</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="show('achievements')" class="card section-card">
            <div class="card-header">Section 31 · Other achievements</div>
            <div class="card-body text-pre">{{ dash(snapshot.other_achievements) }}</div>
        </div>

        <div v-if="show('endorsement')" class="card section-card">
            <div class="card-header">Section 32 · Employer endorsement</div>
            <div class="card-body">
                <table v-if="snapshot.endorsement" class="table table-sm kv-table mb-0">
                    <tbody>
                        <tr><th>Organisation / Designation</th><td>{{ dash(snapshot.endorsement.employer_organization) }} / {{ dash(snapshot.endorsement.designation) }}</td></tr>
                        <tr><th>Capacity / With effect from</th><td>{{ dash(snapshot.endorsement.employment_type) }} / {{ formatDate(snapshot.endorsement.employment_start_date as string) }}</td></tr>
                        <tr><th>Pay level / Basic pay</th><td>{{ dash(snapshot.endorsement.pay_scale_level) }} / {{ formatMoney(snapshot.endorsement.basic_pay as number) }}</td></tr>
                        <tr><th>Next increment</th><td>{{ formatDate(snapshot.endorsement.next_increment_date as string) }}</td></tr>
                        <tr><th>No disciplinary / vigilance case</th><td>{{ snapshot.endorsement.no_disciplinary_case_certified ? 'Certified' : 'Not certified' }}</td></tr>
                        <tr><th>No objection</th><td>{{ snapshot.endorsement.no_objection_certified ? 'Certified' : 'Not certified' }}</td></tr>
                        <tr><th>Forwarding officer</th><td>{{ dash(snapshot.endorsement.forwarding_officer_name) }}, {{ dash(snapshot.endorsement.forwarding_officer_designation) }} — {{ dash(snapshot.endorsement.place) }}, {{ formatDate(snapshot.endorsement.endorsed_on as string) }}</td></tr>
                    </tbody>
                </table>
                <p v-else class="text-muted mb-0">Not applicable.</p>
            </div>
        </div>

        <div v-if="show('declaration')" class="card section-card">
            <div class="card-header">Declaration and checklist</div>
            <div class="card-body">
                <p class="small text-muted mb-2">{{ snapshot.candidate_declaration.text }}</p>
                <p class="small">Accepted electronically on <strong>{{ formatDateTime(snapshot.candidate_declaration.accepted_at) }}</strong> at {{ dash(snapshot.candidate_declaration.place) }} (version {{ dash(snapshot.candidate_declaration.version) }}).</p>
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Checklist item</th><th class="text-center">✓ / X / NA</th><th>Page / document</th></tr></thead>
                    <tbody>
                        <tr v-for="(c, i) in snapshot.checklist" :key="i"><td class="small">{{ c.label }}</td><td class="text-center">{{ c.status }}</td><td class="small">{{ c.page_reference || c.document || '—' }}</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
