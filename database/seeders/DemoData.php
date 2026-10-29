<?php

namespace Database\Seeders;

use App\Models\Advertisement;
use App\Models\Application;
use App\Models\ChecklistTemplate;
use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Models\EvaluationFormTemplate;
use App\Models\RecruitmentCycle;
use App\Models\RefereeFormTemplate;
use App\Models\User;
use App\Services\AdvertisementService;
use App\Services\ApplicationForm\ApplicationFormService;
use App\Services\DocumentService;
use App\Services\FeePaymentConfigService;
use App\Services\RequirementResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * A realistic advertisement and a fully completed application, built through
 * the real services. Shared by DemoSeeder and the test suite.
 */
class DemoData
{
    public static function advertisement(RecruitmentCycle $cycle, User $super): Advertisement
    {
        $service = app(AdvertisementService::class);
        $ad = $service->create([
            'recruitment_cycle_id' => $cycle->id,
            'number' => 'IIITM/NT/2026/03',
            'title' => 'Recruitment to Non-Teaching Posts on Direct Recruitment / Deputation Basis',
            'publication_date' => now()->subDays(10)->toDateString(),
            'department' => 'Establishment Section',
            'summary' => 'Applications are invited from eligible Indian nationals for the following non-teaching posts at ABV-IIITM Gwalior.',
            'instructions' => "1. Read the advertisement carefully before applying.\n2. Only online applications through this portal will be accepted.\n3. In-service candidates must submit the application through proper channel with the employer endorsement (Section 32).\n4. Upload self-attested copies of all certificates.\n5. The Institute reserves the right to fix higher criteria for shortlisting.",
            'reservation_details' => 'Reservation for SC / ST / OBC (NCL) / EWS / PwBD / ESM as per Government of India rules.',
            'age_relaxation_rules' => 'Upper age limit relaxable by 5 years for SC/ST, 3 years for OBC (NCL), 10 years for PwBD, and for Government servants as per DoPT orders.',
            'fee_rules_text' => 'Rs. 500 for UR / OBC / EWS candidates. SC / ST / PwBD / women / ex-servicemen candidates are exempted.',
            'payment_instructions' => "Pay the fee by NEFT to:\nAccount name: ABV-IIITM Gwalior\nAccount No.: 0000000000000 (demo)\nIFSC: SBIN0000000 (demo)\nRetain the UTR number and upload the transaction proof.",
            'opens_at' => now()->subDays(7),
            'closes_at' => now()->addDays(30),
            'reference_prefix' => 'IIITM/NT/26-03',
            'fee_schedule' => ['default' => 500, 'exemptions' => ['SC' => 0, 'ST' => 0, 'PWD' => 0, 'FEMALE' => 0, 'ESM' => 0]],
            'payment_methods' => ['neft', 'demand_draft'],
            'referees_required' => 3,
            'referee_recommendations_mandatory' => false,
            'referee_deadline_days' => 14,
            'referee_reminder_days' => [5, 10],
            'referee_final_reminder_days_before' => 2,
            'referee_max_reminders' => 3,
            'referee_extra_verification' => false,
            'referee_form_template_id' => RefereeFormTemplate::value('id'),
            'endorsement_mode' => 'required_for_in_service',
            'endorsement_instructions' => 'In-service candidates must upload the endorsement in the prescribed format (Section 32), signed and sealed by the Head of Department or employer.',
            'committee_evaluation_mode' => 'individual',
            'evaluation_form_template_id' => EvaluationFormTemplate::value('id'),
            'allow_withdrawal' => true,
            'allow_clarification_uploads' => true,
            'retention_years' => 5,
            'contact_email' => 'recruitment@iiitm.ac.in',
            'contact_phone' => '0751-2449801',
        ], $super);

        $posts = [
            ['JAO', 'Junior Accounts Officer', 'Accounts Section', 'Group B', 'Level 7', 'Rs. 44,900 – 1,42,400', 2, 35,
                "Master's degree in Commerce / Finance with at least 55% marks, or equivalent.",
                'SAS / CA / ICWA qualification. Working knowledge of Tally / ERP.',
                'At least 3 years’ experience in accounts in a Government / autonomous body.',
                [['UR', 1], ['OBC', 1]],
                [['min_percentage', 'Post-graduate degree with at least 55% marks', '55', 'post_graduation', true], ['min_experience_months', 'Minimum 3 years’ relevant experience', '36', null, true], ['max_age', 'Not more than 35 years on the closing date', '35', null, true]]],
            ['TA-CSE', 'Technical Assistant (Computer Science)', 'Computer Science & Engineering', 'Group C', 'Level 6', 'Rs. 35,400 – 1,12,400', 3, 30,
                'B.E. / B.Tech. in Computer Science / IT with first class, or MCA / M.Sc. (Computer Science) with first class.',
                'Experience in managing Linux servers and computer laboratories.',
                'Not required for B.E./B.Tech./MCA; 2 years for others.',
                [['UR', 1], ['SC', 1], ['EWS', 1]],
                [['qualification_level', 'Graduate degree in the relevant discipline', null, 'graduation', true], ['max_age', 'Not more than 30 years on the closing date', '30', null, true]]],
            ['ASST', 'Assistant', 'Administration', 'Group C', 'Level 4', 'Rs. 25,500 – 81,100', 4, 30,
                "Bachelor's degree with proficiency in computer applications.", 'Experience in office administration.', null,
                [['UR', 2], ['OBC', 1], ['ST', 1]],
                [['qualification_level', 'Bachelor’s degree', null, 'graduation', true], ['max_age', 'Not more than 30 years', '30', null, true]]],
        ];
        foreach ($posts as $i => [$code, $name, $dept, $group, $level, $scale, $vac, $age, $ess, $des, $exp, $vacancies, $criteria]) {
            $service->savePost($ad, [
                'code' => $code, 'name' => $name, 'department' => $dept, 'group_classification' => $group,
                'pay_level' => $level, 'pay_scale' => $scale, 'total_vacancies' => $vac, 'age_max' => $age,
                'age_cutoff_date' => $ad->closes_at->toDateString(), 'essential_qualifications' => $ess,
                'desirable_qualifications' => $des, 'experience_requirements' => $exp, 'status' => 'open', 'sort_order' => $i,
                'vacancies' => array_map(fn ($v) => ['category' => $v[0], 'count' => $v[1]], $vacancies),
                'criteria' => array_map(fn ($c) => ['type' => $c[0], 'label' => $c[1], 'value' => $c[2], 'qualification_level' => $c[3], 'is_essential' => $c[4]], $criteria),
            ], null, $super);
        }

        $type = fn ($code) => DocumentType::where('code', $code)->value('id');
        foreach ([
            ['photo', 'always', true, 'Recent passport-size photograph, JPEG/PNG up to 200 KB.'],
            ['id_proof', 'always', true, null],
            ['fee_receipt', 'fee_payable', true, 'NEFT transaction receipt showing the UTR number.'],
            ['category_certificate', 'reserved_category', true, 'In the format prescribed by the Government of India; OBC certificate must be NCL and current.'],
            ['disability_certificate', 'pwd', true, null],
            ['esm_certificate', 'ex_serviceman', true, null],
            ['qualification_certificate', 'always', true, 'Upload certificates and mark sheets against each qualification row.'],
            ['experience_certificate', 'has_employment', true, 'Upload against each employment row.'],
            ['employer_endorsement', 'in_service', true, null],
            ['noc', 'in_service', false, null],
            ['case_details', 'criminal_disclosure', false, null],
        ] as [$code, $condition, $mandatory, $instructions]) {
            DocumentRequirement::create(['advertisement_id' => $ad->id, 'document_type_id' => $type($code), 'condition' => $condition, 'is_mandatory' => $mandatory, 'instructions' => $instructions]);
        }

        ChecklistTemplate::first()->assignments()->create(['advertisement_id' => $ad->id]);
        app(FeePaymentConfigService::class)->save([
            'advertisement_id' => $ad->id, 'post_id' => null, 'label' => 'Recruitment fee account (demo)',
            'account_holder' => 'ABV-IIITM Gwalior (demo)', 'bank_name' => 'State Bank of India', 'branch_name' => 'IIITM Campus Branch',
            'account_number' => '00000000000000', 'account_type' => 'savings', 'ifsc' => 'SBIN0000000',
            'branch_address' => 'Morena Link Road, Gwalior – 474015', 'upi_id' => null,
            'instructions' => 'Pay the fee by NEFT / RTGS to the account above from any bank. Demo details — do not transfer money.',
            'reference_instructions' => 'Write your registered email address in the remarks of the transfer, and keep the UTR number.',
            'is_active' => true,
        ], null, $super);
        $service->transition($ad, Advertisement::PUBLISHED, $super);

        return $ad->fresh();
    }

    public static function upload(Application $application, string $type, ?Model $row = null, ?string $label = null): void
    {
        $file = $type === 'photo' ? SampleFiles::png('photo.png') : SampleFiles::pdf($type.'.pdf', ucfirst(str_replace('_', ' ', $type)));
        app(DocumentService::class)->store($application, DocumentType::where('code', $type)->firstOrFail(), $file, $application->user, $row, $label);
    }

    public static function fill(Application $app, bool $complete): void
    {
        $form = app(ApplicationFormService::class);
        $save = fn (string $section, array $data) => $form->saveSection($app->fresh(), $section, $data);

        $save('personal', [
            'full_name' => mb_strtoupper($app->user->name), 'relation_type' => 'father', 'relation_name' => 'SURESH KUMAR SHARMA',
            'date_of_birth' => '1993-04-12', 'place_of_birth' => 'Gwalior, Madhya Pradesh', 'mobile' => '9876543210',
            'email' => $app->user->email, 'nationality' => 'Indian', 'gender' => $complete ? 'male' : 'female', 'marital_status' => 'married',
            'category' => $complete ? 'OBC' : 'UR', 'is_pwd' => false, 'nearest_railway_station' => 'Gwalior Junction',
            'permanent_same_as_correspondence' => true,
            'correspondence' => ['line1' => '24, Shanti Nagar', 'line2' => 'Near City Centre', 'city' => 'Gwalior', 'district' => 'Gwalior', 'state' => 'Madhya Pradesh', 'pincode' => '474011', 'country' => 'India'],
        ]);
        self::upload($app, 'photo');
        self::upload($app, 'id_proof');
        if ($complete) {
            self::upload($app, 'category_certificate');
        }

        $save('payment', ['method' => 'neft', 'utr_number' => 'SBIN226030012345', 'transaction_date' => now()->subDays(2)->toDateString(), 'amount' => 500, 'bank_name' => 'State Bank of India']);
        if (! $complete) {
            return; // the draft stops part-way through
        }
        self::upload($app, 'fee_receipt');

        $save('ex_serviceman', ['is_ex_serviceman' => false]);
        $save('qualifications', ['rows' => [
            ['level' => 'class_10', 'examination' => 'High School (Class X)', 'board_university' => 'MP Board of Secondary Education', 'duration' => '1 year', 'year_of_passing' => 2008, 'division' => 'First', 'percentage' => 81.4, 'subjects' => 'Hindi, English, Mathematics, Science, Social Science'],
            ['level' => 'class_12', 'examination' => 'Higher Secondary (Class XII)', 'board_university' => 'MP Board of Secondary Education', 'duration' => '1 year', 'year_of_passing' => 2010, 'division' => 'First', 'percentage' => 76.2, 'subjects' => 'Accountancy, Business Studies, Economics, English'],
            ['level' => 'graduation', 'examination' => 'B.Com.', 'board_university' => 'Jiwaji University, Gwalior', 'duration' => '3 years', 'year_of_passing' => 2013, 'division' => 'First', 'percentage' => 68.9, 'subjects' => 'Accounting, Taxation, Business Law', 'specialization' => 'Accounting'],
            ['level' => 'post_graduation', 'examination' => 'M.Com.', 'board_university' => 'Jiwaji University, Gwalior', 'duration' => '2 years', 'year_of_passing' => 2015, 'division' => 'First', 'percentage' => 64.5, 'subjects' => 'Advanced Accounting, Financial Management', 'specialization' => 'Finance'],
        ]]);
        foreach ($app->fresh()->qualifications as $q) {
            self::upload($app, 'qualification_certificate', $q, $q->examination);
        }

        $save('employment', ['has_employment' => true, 'is_in_service' => true, 'rows' => [
            ['organization' => 'Rajiv Gandhi Technical University, Bhopal', 'post_held' => 'Accountant', 'employment_type' => 'regular', 'from_date' => '2019-07-01', 'is_current' => true, 'pay_scale_level' => 'Level 6', 'basic_pay' => 41100, 'gross_pay' => 68500, 'nature_of_duties' => 'Maintenance of accounts, preparation of annual accounts and budget, GST and TDS compliance, audit support.'],
            ['organization' => 'Madhya Pradesh State Cooperative Bank', 'post_held' => 'Accounts Assistant', 'employment_type' => 'contract', 'from_date' => '2015-08-01', 'to_date' => '2019-06-30', 'is_current' => false, 'pay_scale_level' => 'Consolidated', 'gross_pay' => 25000, 'nature_of_duties' => 'Voucher processing, bank reconciliation, ledger maintenance.'],
        ]]);
        foreach ($app->fresh()->employments as $e) {
            self::upload($app, 'experience_certificate', $e, $e->post_held);
        }

        $save('criminal', ['sec20' => ['answer' => false]]);
        $save('merit', ['has_merit_records' => true, 'rows' => [['title' => 'Certificate of Appreciation for timely closure of accounts', 'awarded_by' => 'Registrar, RGPV Bhopal', 'awarded_on' => '2023-04-15']]]);
        $save('training', ['has_training_records' => true, 'rows' => [['type' => 'attended', 'title' => 'Training on PFMS and GeM procurement', 'organization' => 'NIFM Faridabad', 'from_date' => '2022-09-05', 'to_date' => '2022-09-09', 'duration' => '5 days']]]);
        $save('memberships', ['has_memberships' => false]);
        $save('suitability', ['suitability_statement' => 'I hold an M.Com. in Finance and have over nine years of experience in public-sector accounting, including five years as a regular Accountant at a State technical university. My work covers annual accounts, budget preparation, GST/TDS compliance and audit support, which match the duties of a Junior Accounts Officer at the Institute. I have been trained in PFMS and GeM procurement and am familiar with GFR provisions.']);
        $save('referees', ['confirm_accuracy' => true, 'referees' => [
            ['name' => 'Dr. A. K. Gupta', 'designation' => 'Registrar', 'organization' => 'RGPV Bhopal', 'address' => 'RGPV Campus, Airport Bypass Road, Bhopal 462033', 'phone' => '0755-2678800', 'email' => 'referee1@example.org', 'relationship' => 'Present employer (Head of Office)', 'years_known' => 5, 'is_resident_of_india' => true, 'is_not_relative' => true, 'is_employer_or_superior' => true],
            ['name' => 'Shri M. L. Jain', 'designation' => 'Finance Officer', 'organization' => 'RGPV Bhopal', 'address' => 'RGPV Campus, Bhopal 462033', 'phone' => '0755-2678811', 'email' => 'referee2@example.org', 'relationship' => 'Immediate superior', 'years_known' => 5, 'is_resident_of_india' => true, 'is_not_relative' => true, 'is_employer_or_superior' => true],
            ['name' => 'Prof. S. Mishra', 'designation' => 'Professor of Commerce', 'organization' => 'Jiwaji University, Gwalior', 'address' => 'School of Commerce, Jiwaji University, Gwalior 474011', 'phone' => '0751-2442800', 'email' => 'referee3@example.org', 'relationship' => 'Former teacher and M.Com. supervisor', 'years_known' => 11, 'is_resident_of_india' => true, 'is_not_relative' => true, 'is_employer_or_superior' => false],
        ]]);
        $save('declarations', [
            'sec26a' => ['answer' => false], 'sec26b' => ['answer' => false], 'sec27' => ['answer' => false],
            'sec28' => ['answer' => false], 'sec29' => ['answer' => true], 'sec30' => ['answer' => false, 'details' => 'APARs will be forwarded by the employer along with the endorsement.'],
        ]);
        $save('achievements', ['other_achievements' => 'Implemented a Tally-to-PFMS reconciliation workflow adopted across the university.']);
        $save('endorsement', [
            'employer_organization' => 'Rajiv Gandhi Technical University, Bhopal', 'designation' => 'Accountant', 'employment_type' => 'regular',
            'employment_start_date' => '2019-07-01', 'pay_scale_level' => 'Level 6', 'basic_pay' => 41100, 'next_increment_date' => now()->addMonths(4)->startOfMonth()->toDateString(),
            'no_disciplinary_case_certified' => true, 'no_objection_certified' => true, 'forwarding_officer_name' => 'Dr. A. K. Gupta',
            'forwarding_officer_designation' => 'Registrar', 'place' => 'Bhopal', 'endorsed_on' => now()->subDay()->toDateString(),
        ]);
        self::upload($app, 'employer_endorsement');
        self::upload($app, 'noc');

        $app = $app->fresh();
        $entries = app(RequirementResolver::class)->checklistItems($app, true)->map(fn ($item) => [
            'checklist_item_id' => $item->id,
            'candidate_status' => in_array($item->code, ['analogous_post', 'desirable_qualification'], true) ? 'na' : 'yes',
            'page_reference' => null,
        ])->all();
        $save('checklist', ['entries' => $entries]);
        $save('declaration', ['affirm_true' => true, 'affirm_read_instructions' => true, 'affirm_not_concealed' => true, 'affirm_consequences' => true, 'place' => 'Bhopal', 'date' => now()->toDateString()]);
    }
}
