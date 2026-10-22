<?php

namespace Database\Seeders;

use App\Models\Advertisement;
use App\Models\Application;
use App\Models\Committee;
use App\Models\Post;
use App\Models\RecruitmentCycle;
use App\Models\Role;
use App\Models\ScrutinyTeam;
use App\Models\User;
use App\Services\ApplicationForm\ApplicationFormService;
use App\Services\RefereeService;
use App\Services\SubmissionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Demonstration data built through the real services, so it looks exactly like
 * data the application would produce. Password for every account: Password@123
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Password@123';

    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        $super = $this->user('Super Administrator', 'superadmin@iiitm.ac.in', [Role::SUPER_ADMIN], 'Registrar');
        $ra = $this->user('Recruitment Officer', 'recruitment@iiitm.ac.in', [Role::RECRUITMENT_ADMIN], 'Deputy Registrar (Establishment)');
        $s1 = $this->user('Scrutiny Officer One', 'scrutiny1@iiitm.ac.in', [Role::SCRUTINY_USER], 'Assistant Registrar');
        $s2 = $this->user('Scrutiny Officer Two', 'scrutiny2@iiitm.ac.in', [Role::SCRUTINY_USER], 'Section Officer');
        $c1 = $this->user('Prof. Committee Chair', 'committee1@iiitm.ac.in', [Role::COMMITTEE_MEMBER], 'Professor');
        $c2 = $this->user('Dr. Committee Member', 'committee2@iiitm.ac.in', [Role::COMMITTEE_MEMBER], 'Associate Professor');

        Auth::login($super);

        $cycle = RecruitmentCycle::firstOrCreate(['name' => 'Non-Teaching Recruitment 2026-27'], ['year' => '2026-27', 'is_active' => true]);
        $ad = Advertisement::where('number', 'IIITM/NT/2026/03')->first() ?? DemoData::advertisement($cycle, $super);
        $ad->administrators()->syncWithoutDetaching([$ra->id]);

        $team = ScrutinyTeam::firstOrCreate(['advertisement_id' => $ad->id, 'name' => 'Scrutiny Team A']);
        $team->members()->syncWithoutDetaching([$s1->id, $s2->id]);
        $committee = Committee::firstOrCreate(['advertisement_id' => $ad->id, 'name' => 'Screening Committee — Non-Teaching 2026']);
        $committee->members()->syncWithoutDetaching([$c1->id => ['is_chair' => true], $c2->id => ['is_chair' => false]]);

        $applicant = $this->user('Ravi Kumar Sharma', 'applicant@example.com', [Role::APPLICANT]);
        $applicant2 = $this->user('Priya Verma', 'applicant2@example.com', [Role::APPLICANT]);

        $jao = Post::where('advertisement_id', $ad->id)->where('code', 'JAO')->first();
        $ta = Post::where('advertisement_id', $ad->id)->where('code', 'TA-CSE')->first();

        if (! Application::where('user_id', $applicant->id)->exists()) {
            $this->submittedApplication($applicant, $jao);
        }
        if (! Application::where('user_id', $applicant2->id)->exists()) {
            $this->draftApplication($applicant2, $ta);
        }
        Auth::logout();
    }

    private function user(string $name, string $email, array $roles, ?string $designation = null): User
    {
        $user = User::firstOrCreate(['email' => $email], [
            'name' => $name, 'password' => self::PASSWORD, 'is_active' => true,
            'email_verified_at' => now(), 'designation' => $designation, 'mobile' => '9876543210',
        ]);
        $user->roles()->syncWithoutDetaching(Role::whereIn('slug', $roles)->pluck('id'));

        return $user;
    }

    private function submittedApplication(User $applicant, Post $post): void
    {
        Auth::login($applicant);
        $app = app(ApplicationFormService::class)->create($applicant, $post);
        DemoData::fill($app, true);
        $app = app(SubmissionService::class)->submit($app->fresh(), $applicant);
        Auth::logout();

        // One referee responds, so the demo shows a mix of statuses.
        $invitation = $app->refereeInvitations()->with('referee')->orderBy('id')->first();
        app(RefereeService::class)->submit($invitation, [
            'referee_name' => $invitation->referee->name, 'referee_designation' => $invitation->referee->designation,
            'referee_organization' => $invitation->referee->organization, 'referee_email' => $invitation->email,
            'referee_phone' => $invitation->referee->phone, 'relationship' => 'Head of Office where the applicant works',
            'known_duration' => '5 years', 'nature_of_association' => 'The applicant has worked under my administrative control since 2019.',
            'answers' => ['capacity' => 'Present employer / supervisor', 'known_for' => '5 years', 'competence' => 4, 'integrity' => 5,
                'reliability' => 5, 'independence' => 4, 'teamwork' => 4, 'suitability' => 4, 'would_recommend' => 'yes',
                'observations' => 'Diligent and well versed in Government accounting procedures.'],
            'overall_recommendation' => 'Strongly Recommended', 'comments' => 'I recommend the applicant without reservation.',
            'confirm_good_faith' => true,
        ]);
    }

    private function draftApplication(User $applicant, Post $post): void
    {
        Auth::login($applicant);
        $app = app(ApplicationFormService::class)->create($applicant, $post);
        DemoData::fill($app, false);
        Auth::logout();
    }
}
