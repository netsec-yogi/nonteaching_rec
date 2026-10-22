<?php

namespace Tests\Feature;

use App\Models\Application;
use Database\Seeders\SampleFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ValidationAndUploadsTest extends TestCase
{
    private Application $draft;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->recruitment();
        $user = $this->applicant();
        $this->actingAs($user);
        $uuid = $this->postJson('/api/applications', ['post_id' => $this->post->id])->json('uuid');
        $this->draft = Application::where('uuid', $uuid)->first();
    }

    private function save(string $section, array $data)
    {
        return $this->putJson("/api/applications/{$this->draft->uuid}/sections/$section", $data);
    }

    public function test_suitability_statement_is_limited_to_500_words_on_the_server(): void
    {
        $this->save('suitability', ['suitability_statement' => str_repeat('word ', 501)])
            ->assertUnprocessable()->assertJsonValidationErrors('suitability_statement');
        $this->save('suitability', ['suitability_statement' => str_repeat('word ', 500)])->assertOk();
    }

    public function test_referee_emails_must_be_distinct_unless_an_exception_is_granted(): void
    {
        $referee = fn ($email) => ['name' => 'A', 'designation' => 'B', 'organization' => 'C', 'address' => 'D', 'phone' => '9876543210', 'email' => $email,
            'relationship' => 'Supervisor', 'years_known' => 3, 'is_resident_of_india' => true, 'is_not_relative' => true];
        $payload = ['confirm_accuracy' => true, 'referees' => [$referee('same@example.org'), $referee('same@example.org'), $referee('third@example.org')]];

        $this->save('referees', $payload)->assertUnprocessable()->assertJsonValidationErrors('referees.1.email');
        $this->save('referees', ['confirm_accuracy' => true, 'referees' => [$referee('a@example.org'), $referee('b@example.org')]])
            ->assertUnprocessable()->assertJsonValidationErrors('referees');
        $this->save('referees', ['confirm_accuracy' => true, 'referees' => [$referee('candidate@example.com'), $referee('b@example.org'), $referee('c@example.org')]])
            ->assertUnprocessable()->assertJsonValidationErrors('referees.0.email');

        $this->draft->forceFill(['referee_email_exception' => true])->save();
        $this->save('referees', $payload)->assertOk();
    }

    public function test_conditional_fields_are_required_only_when_applicable(): void
    {
        $this->save('criminal', ['sec20' => ['answer' => true]])->assertUnprocessable()->assertJsonValidationErrors('sec20.details');
        $this->save('criminal', ['sec20' => ['answer' => false]])->assertOk();

        $base = ['sec26a' => ['answer' => false], 'sec26b' => ['answer' => false], 'sec27' => ['answer' => false], 'sec28' => ['answer' => false], 'sec29' => ['answer' => true]];
        $this->save('declarations', $base + ['sec30' => ['answer' => false]])->assertUnprocessable()->assertJsonValidationErrors('sec30.details');
        $this->save('declarations', $base + ['sec30' => ['answer' => true]])->assertOk();

        $this->save('ex_serviceman', ['is_ex_serviceman' => true])->assertUnprocessable()->assertJsonValidationErrors('ex_serviceman_category');
    }

    public function test_employment_dates_and_current_employment_are_validated(): void
    {
        $row = ['organization' => 'X', 'post_held' => 'Clerk', 'employment_type' => 'contract', 'nature_of_duties' => 'Typing'];
        $this->save('employment', ['has_employment' => true, 'is_in_service' => false, 'rows' => [$row + ['from_date' => '2020-01-01', 'to_date' => '2019-01-01']]])
            ->assertUnprocessable()->assertJsonValidationErrors('rows.0.to_date');
        $this->save('employment', ['has_employment' => true, 'is_in_service' => false, 'rows' => [$row + ['from_date' => '2020-01-01']]])
            ->assertUnprocessable()->assertJsonValidationErrors('rows.0.to_date');

        $this->save('employment', ['has_employment' => true, 'is_in_service' => true, 'rows' => [
            $row + ['from_date' => '2015-01-01', 'to_date' => '2017-06-30'],
            ['from_date' => '2018-01-01', 'is_current' => true, 'post_held' => 'Assistant'] + $row,
        ]])->assertOk();
        // Stored most recent first, current post with no end date.
        $rows = $this->draft->employments()->get();
        $this->assertSame('Assistant', $rows[0]->post_held);
        $this->assertNull($rows[0]->to_date);
        $this->assertSame(30, $rows[1]->duration_months);
    }

    public function test_qualifications_must_start_from_class_10(): void
    {
        $this->save('qualifications', ['rows' => [['level' => 'graduation', 'examination' => 'B.A.', 'board_university' => 'Jiwaji', 'year_of_passing' => 2015]]])->assertOk();
        $issues = collect($this->getJson("/api/applications/{$this->draft->uuid}")->json('steps'))->firstWhere('key', 'qualifications')['issues'];
        $this->assertStringContainsString('Class 10', implode(' ', $issues));
    }

    public function test_uploads_are_checked_by_content_not_just_name(): void
    {
        $url = "/api/applications/{$this->draft->uuid}/documents";

        $fake = UploadedFile::fake()->createWithContent('certificate.pdf', '<?php echo "not a pdf"; ?>');
        $this->post($url, ['file' => $fake, 'document_type' => 'qualification_certificate'])->assertUnprocessable()->assertJsonValidationErrors('file');

        $scripted = UploadedFile::fake()->createWithContent('active.pdf', "%PDF-1.4\n1 0 obj << /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >>\n%%EOF");
        $this->post($url, ['file' => $scripted, 'document_type' => 'qualification_certificate'])->assertUnprocessable()->assertJsonValidationErrors('file');

        // Photographs must be images within the configured size.
        $this->post($url, ['file' => SampleFiles::pdf(), 'document_type' => 'photo'])->assertUnprocessable();
        $this->post($url, ['file' => SampleFiles::png(), 'document_type' => 'photo'])->assertCreated()->assertJsonMissingPath('path');

        // A document can only be attached to this application's own rows.
        $other = $this->completedDraft($this->applicant('someone@example.com'));
        $foreignRow = $other->qualifications()->first();
        $this->actingAs($this->draft->user)
            ->post($url, ['file' => SampleFiles::pdf(), 'document_type' => 'qualification_certificate', 'documentable_type' => 'qualification', 'documentable_id' => $foreignRow->id])
            ->assertNotFound();
    }

    public function test_fee_exemption_is_computed_from_the_configured_schedule(): void
    {
        $this->assertEquals(500, $this->getJson("/api/applications/{$this->draft->uuid}")->json('application.fee.amount'));
        $this->draft->personal()->update(['category' => 'SC']);
        $fee = $this->getJson("/api/applications/{$this->draft->uuid}")->json('application.fee');
        $this->assertEquals(0, $fee['amount']);
        $this->assertSame('SC', $fee['basis']);
        $this->save('payment', [])->assertOk();
        $this->assertSame('exempt', $this->draft->payment()->value('status'));
    }
}
