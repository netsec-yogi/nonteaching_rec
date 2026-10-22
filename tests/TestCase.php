<?php

namespace Tests;

use App\Models\Advertisement;
use App\Models\Application;
use App\Models\Committee;
use App\Models\Post;
use App\Models\RecruitmentCycle;
use App\Models\RefereeInvitation;
use App\Models\Role;
use App\Models\ScrutinyTeam;
use App\Models\User;
use App\Services\ApplicationForm\ApplicationFormService;
use App\Services\SubmissionService;
use Database\Seeders\DemoData;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected User $super;

    protected User $admin;

    protected User $scrutiny;

    protected User $scrutiny2;

    protected User $chair;

    protected User $member;

    protected Advertisement $ad;

    protected Post $post;

    protected function setUp(): void
    {
        parent::setUp();
        // Backend tests must not depend on a built frontend (public/build/manifest.json).
        $this->withoutVite();
        Storage::fake('testing');
        $this->seed(ReferenceDataSeeder::class);
        $this->withHeaders(['Accept' => 'application/json']);
    }

    /** Send the request as the SPA does: from the stateful domain, with a session. */
    protected function fromSpa(): static
    {
        return $this->withHeader('Referer', config('app.url'));
    }

    protected function staff(string $role, string $email): User
    {
        $user = User::create(['name' => ucfirst($role).' User', 'email' => $email, 'password' => 'Password@123', 'is_active' => true, 'email_verified_at' => now()]);
        $user->roles()->attach(Role::where('slug', $role)->value('id'));

        return $user;
    }

    protected function applicant(string $email = 'candidate@example.com'): User
    {
        $user = User::create(['name' => 'Ravi Kumar Sharma', 'email' => $email, 'mobile' => '9876543210', 'password' => 'Password@123', 'is_active' => true, 'email_verified_at' => now()]);
        $user->roles()->attach(Role::where('slug', Role::APPLICANT)->value('id'));

        return $user;
    }

    /** Staff, a published advertisement with posts and requirements, a scrutiny team and a committee. */
    protected function recruitment(): void
    {
        $this->super = $this->staff(Role::SUPER_ADMIN, 'super@iiitm.test');
        $this->admin = $this->staff(Role::RECRUITMENT_ADMIN, 'ra@iiitm.test');
        $this->scrutiny = $this->staff(Role::SCRUTINY_USER, 'scrutiny1@iiitm.test');
        $this->scrutiny2 = $this->staff(Role::SCRUTINY_USER, 'scrutiny2@iiitm.test');
        $this->chair = $this->staff(Role::COMMITTEE_MEMBER, 'chair@iiitm.test');
        $this->member = $this->staff(Role::COMMITTEE_MEMBER, 'member@iiitm.test');

        Auth::guard('web')->setUser($this->super);
        $cycle = RecruitmentCycle::create(['name' => 'Test cycle', 'year' => '2026-27']);
        $this->ad = DemoData::advertisement($cycle, $this->super);
        Auth::guard('web')->forgetUser();

        $this->ad->administrators()->attach($this->admin->id);
        $this->post = Post::where('advertisement_id', $this->ad->id)->where('code', 'JAO')->firstOrFail();
    }

    protected function team(?array $members = null): ScrutinyTeam
    {
        $team = ScrutinyTeam::create(['advertisement_id' => $this->ad->id, 'name' => 'Team A']);
        $team->members()->attach($members ?? [$this->scrutiny->id]);

        return $team;
    }

    protected function committee(): Committee
    {
        $committee = Committee::create(['advertisement_id' => $this->ad->id, 'name' => 'Screening Committee']);
        $committee->members()->attach([$this->chair->id => ['is_chair' => true], $this->member->id => ['is_chair' => false]]);

        return $committee;
    }

    /** A complete application, ready to submit, built through the real services. */
    protected function completedDraft(?User $applicant = null): Application
    {
        $applicant ??= $this->applicant();
        Auth::guard('web')->setUser($applicant);
        $application = app(ApplicationFormService::class)->create($applicant, $this->post);
        DemoData::fill($application, true);
        Auth::guard('web')->forgetUser();

        return $application->fresh();
    }

    protected function submitted(?User $applicant = null): Application
    {
        $application = $this->completedDraft($applicant);
        Auth::guard('web')->setUser($application->user);
        $application = app(SubmissionService::class)->submit($application, $application->user);
        Auth::guard('web')->forgetUser();

        return $application->fresh();
    }

    /** The raw link token of a pending invitation (kept encrypted only for reminders). */
    protected function rawToken(RefereeInvitation $invitation): string
    {
        return $invitation->fresh()->token_encrypted;
    }

    protected function refereeAnswers(array $overrides = []): array
    {
        return array_merge([
            'referee_name' => 'Dr. A. K. Gupta', 'referee_designation' => 'Registrar', 'referee_organization' => 'RGPV Bhopal',
            'referee_email' => 'referee1@example.org', 'referee_phone' => '0755-2678800', 'relationship' => 'Head of Office',
            'known_duration' => '5 years', 'nature_of_association' => 'Supervisor',
            'answers' => ['capacity' => 'Present employer / supervisor', 'known_for' => '5 years', 'competence' => 4, 'integrity' => 5,
                'reliability' => 5, 'independence' => 4, 'teamwork' => 4, 'suitability' => 4, 'would_recommend' => 'yes'],
            'overall_recommendation' => 'Recommended', 'comments' => 'Good candidate.', 'confirm_good_faith' => true,
        ], $overrides);
    }
}
