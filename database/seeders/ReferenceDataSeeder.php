<?php

namespace Database\Seeders;

use App\Models\ChecklistTemplate;
use App\Models\DocumentType;
use App\Models\EmailTemplate;
use App\Models\EvaluationFormTemplate;
use App\Models\Permission;
use App\Models\RefereeFormTemplate;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/**
 * Reference data every installation needs. Idempotent: safe to re-run, and it
 * never overwrites rows an administrator has since edited (templates and
 * forms are created only when missing).
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->roles();
        $this->documentTypes();
        $this->checklist();
        $this->refereeForm();
        $this->evaluationForm();
        $this->emailTemplates();
    }

    private function roles(): void
    {
        $roles = [
            Role::SUPER_ADMIN => ['Super Administrator', 'Complete control of the recruitment system', true],
            Role::RECRUITMENT_ADMIN => ['Recruitment Administrator', 'Manages assigned advertisements and applications', true],
            Role::SCRUTINY_USER => ['Scrutiny User', 'Scrutinises applications assigned to them', true],
            Role::COMMITTEE_MEMBER => ['Screening Committee Member', 'Evaluates applications assigned to their committee', true],
            Role::APPLICANT => ['Applicant', 'Candidate applying for advertised posts', false],
        ];
        foreach ($roles as $slug => [$name, $description, $staff]) {
            Role::updateOrCreate(['slug' => $slug], ['name' => $name, 'description' => $description, 'is_staff' => $staff]);
        }

        foreach (Permissions::ALL as $slug => [$name, $group, $sensitive, $defaults]) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'group' => $group, 'is_sensitive' => $sensitive]);
            if ($permission->wasRecentlyCreated) {
                $permission->roles()->syncWithoutDetaching(Role::whereIn('slug', $defaults)->pluck('id'));
            }
        }
    }

    private function documentTypes(): void
    {
        $pdf = ['application/pdf'];
        $img = ['image/jpeg', 'image/png'];
        $any = ['application/pdf', 'image/jpeg', 'image/png'];

        $types = [
            ['fee_receipt', 'Application fee receipt / NEFT transaction proof', $any, 1024, 'payment'],
            ['photo', 'Passport-size photograph', $img, 200, 'personal'],
            ['signature', "Candidate's Signature", $img, 100, 'personal'],
            ['id_proof', 'Photo identity card (Govt. agency / last attended institution)', $any, 1024, 'personal'],
            ['category_certificate', 'Category certificate (SC / ST / OBC-NCL / EWS)', $pdf, 2048, 'personal'],
            ['disability_certificate', 'Disability (PwBD) certificate', $pdf, 2048, 'personal'],
            ['esm_certificate', 'Ex-servicemen certificate from the competent authority', $pdf, 2048, 'ex_serviceman'],
            ['qualification_certificate', 'Certificate / mark sheet', $pdf, 2048, 'qualifications'],
            ['experience_certificate', 'Experience certificate / appointment or relieving letter', $pdf, 2048, 'employment'],
            ['analogous_post_proof', 'Proof of analogous post', $pdf, 2048, 'employment'],
            ['case_details', 'Details of case / proceedings', $pdf, 2048, 'criminal'],
            ['merit_certificate', 'Certificate of excellence / merit', $pdf, 2048, 'merit'],
            ['training_certificate', 'Training / consultancy certificate', $pdf, 2048, 'training'],
            ['membership_certificate', 'Professional membership certificate', $pdf, 2048, 'memberships'],
            ['employer_testimonial', 'Testimonial from present / most recent employer', $pdf, 2048, 'referees'],
            ['declaration_details', 'Separate sheet with details (Sections 26–28)', $pdf, 2048, 'declarations'],
            ['apar_copies', 'Certified copies of ACRs / APARs for the last five years(If applicable)', $pdf, 10240, 'declarations'],
            ['achievement_document', 'Supporting document for other achievements', $pdf, 4096, 'achievements'],
            ['employer_endorsement', 'Employer endorsement signed and sealed by the forwarding officer', $pdf, 2048, 'endorsement'],
            ['noc', 'No Objection Certificate', $pdf, 2048, 'endorsement'],
            ['desirable_qualification_proof', 'Proof of desirable qualification', $pdf, 2048, 'checklist'],
            ['supporting_sheet', 'Additional sheet / supporting document', $pdf, 10240, 'checklist'],
            ['clarification_document', 'Document submitted in response to a clarification', $any, 10240, null],
        ];
        // Default requirement level on a fresh install (advertisement requirements may override it).
        $undertaking = ['qualification_certificate', 'experience_certificate', 'category_certificate', 'disability_certificate',
            'esm_certificate', 'analogous_post_proof', 'noc', 'desirable_qualification_proof'];
        $optional = ['signature', 'analogous_post_proof', 'case_details', 'merit_certificate', 'training_certificate', 'membership_certificate',
            'achievement_document', 'noc', 'desirable_qualification_proof', 'supporting_sheet', 'clarification_document'];
        foreach ($types as [$code, $name, $mimes, $kb, $step]) {
            DocumentType::firstOrCreate(['code' => $code], ['name' => $name, 'allowed_mimes' => $mimes, 'max_kb' => $kb, 'step' => $step,
                'is_mandatory' => ! in_array($code, $optional, true), 'accepts_undertaking' => in_array($code, $undertaking, true), 'is_active' => true]);
        }
    }

    /** The check list on page 5 of the paper form, plus scrutiny-only items. */
    private function checklist(): void
    {
        if (ChecklistTemplate::where('name', 'Standard non-teaching checklist (Form page 5)')->exists()) {
            return;
        }
        $t = ChecklistTemplate::create([
            'name' => 'Standard non-teaching checklist (Form page 5)',
            'description' => 'Items from the check list on page 5 of the application form, with additional scrutiny-only verification items.',
        ]);
        $doc = fn (string $code) => DocumentType::where('code', $code)->value('id');

        $items = [
            // [code, label, section, document type, applicant visible, instructions]
            ['matriculation', 'Matriculation / 10th Standard / Secondary or equivalent certificate', 'qualification', 'qualification_certificate', true, 'Check name, date of birth and year of passing against the application.'],
            ['higher_secondary', 'Higher Secondary / Class XII (or equivalent) Board marks sheet', 'qualification', 'qualification_certificate', true, null],
            ['degree', 'Degree certificate along with mark sheets pertaining to all the academic years as proof of educational qualification claimed', 'qualification', 'qualification_certificate', true, 'All years’ mark sheets must be present.'],
            ['post_graduation', 'Post-Graduation certificate', 'qualification', 'qualification_certificate', true, null],
            ['analogous_post', 'Proof of analogous post (if applicable)', 'employment', 'analogous_post_proof', true, null],
            ['pg_55_percent', 'A Post Graduate Degree with at least 55% marks or its equivalent with excellent academic record (where applicable to the post)', 'qualification', 'qualification_certificate', true, 'Compare the percentage with the essential qualification for the post.'],
            ['desirable_qualification', 'Proof of Desirable Qualification (if any)', 'qualification', 'desirable_qualification_proof', true, null],
            ['photo_id', 'Photo identity card (issued by Govt. agency / last attended Institution / University)', 'identity', 'id_proof', true, null],
            ['fee_receipt', 'Application fee receipt / Transaction details of fee payment made', 'fee', 'fee_receipt', true, 'Match UTR, date and amount against the bank statement before marking verified.'],
            ['category_certificate', 'Category Certificate (EWS, OBC, SC, ST & PwD)', 'category', 'category_certificate', true, 'Check format, validity date and issuing authority.'],
            ['noc', 'No Objection Certificate', 'endorsement', 'noc', true, 'Required for in-service candidates.'],
            // Scrutiny-only verification items
            ['photograph', 'Photograph uploaded is recent and matches the identity document', 'personal', 'photo', false, null],
            ['age_eligibility', 'Age within the prescribed limit on the cut-off date (with applicable relaxation)', 'personal', null, false, 'Use the advisory eligibility panel; apply relaxations as per the advertisement.'],
            ['experience_claims', 'Employment history and experience claims supported by certificates', 'employment', 'experience_certificate', false, null],
            ['disability_certificate', 'Disability certificate (where claimed)', 'disability', 'disability_certificate', false, null],
            ['esm_certificate', 'Ex-servicemen certificate (where claimed)', 'ex_serviceman', 'esm_certificate', false, null],
            ['employer_endorsement', 'Employer endorsement (Section 32) for in-service candidates', 'endorsement', 'employer_endorsement', false, null],
            ['declarations_reviewed', 'Declarations in Sections 20 and 26–30 reviewed', 'declaration', null, false, 'Any YES answer requires the details to be examined.'],
            ['referee_recommendations', 'Referee recommendation status reviewed', 'referee', null, false, 'Missing recommendations do not by themselves make an application ineligible.'],
        ];
        foreach ($items as $i => [$code, $label, $section, $docType, $visible, $instructions]) {
            $t->items()->create([
                'code' => $code, 'label' => $label, 'section' => $section, 'document_type_id' => $docType ? $doc($docType) : null,
                'applicant_visible' => $visible, 'verification_instructions' => $instructions,
                'is_mandatory' => true, 'requires_reason_if_not_verified' => true, 'sort_order' => $i,
            ]);
        }
    }

    private function refereeForm(): void
    {
        if (RefereeFormTemplate::exists()) {
            return;
        }
        $f = RefereeFormTemplate::create([
            'name' => 'Standard confidential referee recommendation',
            'introduction' => 'The applicant named below has applied for a non-teaching post at ABV-IIITM Gwalior and has nominated you as a referee. Your recommendation is confidential: it will be seen only by the authorised Recruitment Section and the Screening Committee, and not by the applicant.',
            'recommendation_options' => ['Strongly Recommended', 'Recommended', 'Recommended with Reservations', 'Not Recommended'],
            'good_faith_statement' => 'I confirm that the information given above is accurate to the best of my knowledge and is submitted in good faith.',
            'is_active' => true,
        ]);
        $rating = ['min' => 1, 'max' => 5, 'labels' => ['1' => 'Poor', '2' => 'Fair', '3' => 'Good', '4' => 'Very good', '5' => 'Excellent']];
        $questions = [
            ['capacity', 'In what capacity do you know the applicant?', 'choice', ['choices' => ['Present employer / supervisor', 'Former employer / supervisor', 'Colleague', 'Teacher / academic supervisor', 'Other professional association']], true],
            ['known_for', 'How long have you known the applicant?', 'short_text', null, true],
            ['competence', 'How would you assess the applicant’s professional competence?', 'rating', $rating, true],
            ['integrity', 'How would you assess the applicant’s integrity and character?', 'rating', $rating, true],
            ['reliability', 'How would you assess the applicant’s reliability and sense of responsibility?', 'rating', $rating, true],
            ['independence', 'How would you assess the applicant’s ability to work independently?', 'rating', $rating, true],
            ['teamwork', 'How would you assess the applicant’s ability to work as part of a team?', 'rating', $rating, true],
            ['suitability', 'How would you assess the applicant’s suitability for the advertised post?', 'rating', $rating, true],
            ['would_recommend', 'Would you recommend the applicant for the position?', 'yes_no', null, true],
            ['observations', 'Are there any additional observations relevant to the applicant’s suitability?', 'long_text', null, false],
        ];
        foreach ($questions as $i => [$code, $label, $type, $options, $required]) {
            $f->questions()->create(['code' => $code, 'label' => $label, 'type' => $type, 'options' => $options, 'is_required' => $required, 'sort_order' => $i]);
        }
    }

    private function evaluationForm(): void
    {
        if (EvaluationFormTemplate::exists()) {
            return;
        }
        $f = EvaluationFormTemplate::create([
            'name' => 'Standard screening evaluation',
            'description' => 'Screening Committee assessment of eligibility, qualifications, experience and referee feedback.',
            'recommendation_options' => ['Recommended', 'Not Recommended', 'Requires Further Review'],
            'is_active' => true,
        ]);
        $fields = [
            ['eligibility', 'Eligibility assessment', 'choice', ['choices' => ['Eligible', 'Not eligible', 'Doubtful — refer']], true],
            ['essential_qualification', 'Essential qualification verified', 'yes_no', null, true],
            ['experience', 'Experience assessment', 'rating', ['min' => 1, 'max' => 5, 'labels' => ['1' => 'Insufficient', '3' => 'Adequate', '5' => 'Outstanding']], true],
            ['referee_feedback', 'Referee feedback review', 'long_text', null, false],
            ['observations', 'Observations', 'long_text', null, false],
        ];
        foreach ($fields as $i => [$code, $label, $type, $options, $required]) {
            $f->fields()->create(['code' => $code, 'label' => $label, 'type' => $type, 'options' => $options, 'is_required' => $required, 'sort_order' => $i]);
        }
    }

    private function emailTemplates(): void
    {
        $sign = "\n\nRecruitment Section  \n{{ institute_short_name }}  \n{{ recruitment_email }} · {{ recruitment_phone }}";

        $templates = [
            'email_verification' => ['Applicant registration verification', 'Verify your email address — {{ institute_short_name }} Recruitment Portal',
                "Dear {{ name }},\n\nThank you for registering on the Non-Teaching Recruitment Portal of {{ institute_name }}.\n\nPlease verify your email address by opening the link below. You must verify your email before you can submit an application.\n\n[Verify my email address]({{ verification_url }})\n\nThe link is valid for 48 hours. If you did not create this account, you can ignore this email.".$sign,
                ['name', 'verification_url']],
            'password_reset' => ['Password reset', 'Reset your password — {{ institute_short_name }} Recruitment Portal',
                "Dear {{ name }},\n\nWe received a request to reset the password for your account.\n\n[Reset my password]({{ reset_url }})\n\nThis link expires in {{ minutes }} minutes. If you did not request a reset, no action is needed.".$sign,
                ['name', 'reset_url', 'minutes']],
            'application_submitted' => ['Application submission confirmation', 'Application submitted — {{ reference_no }} ({{ post_name }})',
                "Dear {{ applicant_name }},\n\nYour application for the post of **{{ post_name }}** under Advertisement No. **{{ advertisement_number }}** has been submitted successfully.\n\n- Application Reference No.: **{{ reference_no }}**\n- Submitted on: {{ submitted_at }}\n- Version: {{ version }}\n\nA copy of the submitted application is attached. You can download the application and the acknowledgment receipt, and track the status of your application, from your dashboard:\n\n[Open my application]({{ dashboard_url }})\n\nYour referees have been sent a secure link to submit their confidential recommendations directly to the Institute. Please quote your reference number in all correspondence.".$sign,
                ['applicant_name', 'post_name', 'advertisement_number', 'reference_no', 'submitted_at', 'version', 'dashboard_url']],
            'application_acknowledgment' => ['Application acknowledgment (received by Recruitment Section)', 'Application received — {{ reference_no }}',
                "Dear {{ applicant_name }},\n\nThe Recruitment Section has received your application **{{ reference_no }}** for the post of {{ post_name }} (Advertisement No. {{ advertisement_number }}). It will now be scrutinised.\n\nThis acknowledgment confirms receipt only and does not confirm eligibility.".$sign,
                ['applicant_name', 'reference_no', 'post_name', 'advertisement_number']],
            'application_reopened' => ['Application reopened for correction', 'Your application {{ reference_no }} has been reopened for correction',
                "Dear {{ applicant_name }},\n\nYour application **{{ reference_no }}** has been reopened so that you can make the following correction:\n\n> {{ reason }}\n\nPlease make the correction and resubmit the application by **{{ until }}**. Your previously submitted application is retained on record.\n\n[Open my application]({{ dashboard_url }})".$sign,
                ['applicant_name', 'reference_no', 'reason', 'until', 'dashboard_url']],
            'referee_invitation' => ['Referee invitation', 'Request for Confidential Referee Recommendation — ABV-IIITM Gwalior Non-Teaching Recruitment',
                "Dear {{ referee_name }},\n\n**{{ applicant_name }}** has applied for the post of **{{ post_name }}** under Advertisement No. **{{ advertisement_number }}** at {{ institute_short_name }} and has nominated you as a referee.\n\nYou are requested to provide your confidential recommendation using the secure link below. No registration or login is needed; the link is personal to you, so please do not forward it.\n\n[Submit Referee Recommendation]({{ referee_link }})\n\nPlease complete and submit the form by **{{ deadline }}**.\n\nThe recommendation will be made available to the authorised University Administration and Screening Committee for recruitment purposes. It will not be shared with the applicant.\n\nThank you for your cooperation.".$sign,
                ['referee_name', 'applicant_name', 'post_name', 'advertisement_number', 'reference_no', 'referee_link', 'deadline']],
            'referee_reminder' => ['Referee reminder', 'Reminder: Confidential Referee Recommendation for {{ applicant_name }} — ABV-IIITM Gwalior',
                "Dear {{ referee_name }},\n\nThis is a reminder that we have not yet received your confidential recommendation for **{{ applicant_name }}**, who has applied for the post of **{{ post_name }}** (Advertisement No. {{ advertisement_number }}).\n\n[Submit Referee Recommendation]({{ referee_link }})\n\nThe deadline is **{{ deadline }}**. If you have already submitted your recommendation, please ignore this message.".$sign,
                ['referee_name', 'applicant_name', 'post_name', 'advertisement_number', 'referee_link', 'deadline']],
            'referee_verification_code' => ['Referee email verification code', 'Your verification code — ABV-IIITM Gwalior referee recommendation',
                "Dear {{ referee_name }},\n\nYour verification code is: **{{ code }}**\n\nIt is valid for {{ minutes }} minutes. If you did not request it, please ignore this email.".$sign,
                ['referee_name', 'code', 'minutes']],
            'referee_completed' => ['Referee recommendation completion (to administrators)', 'Referee recommendation received — {{ reference_no }}',
                "Dear {{ admin_name }},\n\nReferee {{ referee_position }} (**{{ referee_name }}**) has submitted a confidential recommendation for application **{{ reference_no }}** ({{ applicant_name }}, {{ post_name }}).\n\n[Open the application]({{ application_url }})".$sign,
                ['admin_name', 'referee_position', 'referee_name', 'reference_no', 'applicant_name', 'post_name', 'application_url']],
            'referee_delivery_failed' => ['Failed referee invitation delivery (to administrators)', 'Referee invitation could not be delivered — {{ reference_no }}',
                "Dear {{ admin_name }},\n\nThe referee invitation for application **{{ reference_no }}** ({{ applicant_name }}) to **{{ referee_name }}** <{{ referee_email }}> could not be delivered after repeated attempts.\n\nError: {{ error }}\n\nYou can correct the email address and issue a replacement invitation from the application's referee panel.".$sign,
                ['admin_name', 'reference_no', 'applicant_name', 'referee_name', 'referee_email', 'error']],
            'clarification_requested' => ['Application clarification request', 'Clarification required — application {{ reference_no }}',
                "Dear {{ applicant_name }},\n\nThe Recruitment Section requires a clarification on your application **{{ reference_no }}**.\n\n**{{ subject }}**\n\n{{ message }}\n\nPlease respond through your dashboard by **{{ due_date }}**. Your submitted application will not be altered; your response and any documents are recorded separately.\n\n[Respond now]({{ url }})".$sign,
                ['applicant_name', 'reference_no', 'subject', 'message', 'due_date', 'url']],
            'clarification_received' => ['Clarification response receipt', 'Clarification received — application {{ reference_no }}',
                "Dear {{ applicant_name }},\n\nWe have received your response to the clarification request “{{ subject }}” on application **{{ reference_no }}**. It will be reviewed by the Recruitment Section.".$sign,
                ['applicant_name', 'reference_no', 'subject']],
            'scrutiny_assigned' => ['Scrutiny assignment', 'Application assigned for {{ stage_name }} — {{ reference_no }}',
                "Dear {{ officer_name }},\n\nApplication **{{ reference_no }}** ({{ applicant_name }}, {{ post_name }}) has been assigned to you for **{{ stage_name }}**.\n\n[Open the scrutiny workspace]({{ url }})".$sign,
                ['officer_name', 'reference_no', 'applicant_name', 'post_name', 'stage_name', 'url']],
            'scrutiny_completed' => ['Scrutiny completion', '{{ stage_name }} completed — {{ reference_no }}',
                "Dear {{ admin_name }},\n\n**{{ officer_name }}** has submitted the {{ stage_name }} report for application **{{ reference_no }}**.\n\nRecommendation: **{{ recommendation }}**\n\n[Open the application]({{ url }})".$sign,
                ['admin_name', 'officer_name', 'stage_name', 'reference_no', 'recommendation', 'url']],
            'decision_notification' => ['Administrative decision notification', 'Status of your application {{ reference_no }}',
                "Dear {{ applicant_name }},\n\nOn scrutiny, your application **{{ reference_no }}** for the post of {{ post_name }} has been {{ decision }}.\n\n{{ reasons }}".$sign,
                ['applicant_name', 'reference_no', 'post_name', 'decision', 'reasons']],
            'referee_submission_receipt' => ['Referee submission receipt (to the referee)', 'Recommendation received — thank you ({{ recommendation_reference }})',
                "Dear {{ referee_name }},\n\nThank you. Your confidential recommendation for **{{ applicant_name }}**, who has applied for the post of {{ post_name }} (Advertisement No. {{ advertisement_number }}), was received on **{{ submitted_at }}**.\n\nRecommendation reference: **{{ recommendation_reference }}**\n\nThe recommendation will be seen only by the authorised Recruitment Section and the Screening Committee. The link you used can no longer be used to submit or change the recommendation. If you need to correct something, write to {{ recruitment_email }} quoting the reference above.".$sign,
                ['referee_name', 'applicant_name', 'post_name', 'advertisement_number', 'submitted_at', 'recommendation_reference']],
            'retention_due' => ['Records retention period ended (to administrators)', 'Retention period ended — {{ count }} advertisement(s) archived for review',
                "Dear {{ admin_name }},\n\nThe records-retention period has ended for the following advertisement(s). They have been archived (read-only) and flagged as due for disposal:\n\n{{ advertisements }}\n\nNothing has been deleted. Disposal is a separate, deliberate step that must be authorised in accordance with the Institute's records policy and performed by the system administrator (see the Operations guide, \"Data retention and archival\").".$sign,
                ['admin_name', 'count', 'advertisements']],
            'deadline_reminder' => ['Recruitment deadline reminder', 'Your application for {{ post_name }} is not yet submitted — closes {{ closes_at }}',
                "Dear {{ applicant_name }},\n\nYour application for the post of **{{ post_name }}** (Advertisement No. {{ advertisement_number }}) is still a draft. Applications close on **{{ closes_at }}**; drafts cannot be submitted after that.\n\n[Continue my application]({{ url }})".$sign,
                ['applicant_name', 'post_name', 'advertisement_number', 'closes_at', 'url']],
        ];

        foreach ($templates as $key => [$name, $subject, $body, $placeholders]) {
            EmailTemplate::firstOrCreate(['key' => $key], [
                'name' => $name, 'subject' => $subject, 'body' => $body, 'is_active' => true,
                'placeholders' => array_merge($placeholders, ['institute_name', 'institute_short_name', 'recruitment_email', 'recruitment_phone', 'portal_url']),
            ]);
        }
    }
}
