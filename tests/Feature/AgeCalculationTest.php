<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Support\Age;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Age in completed years and months from the date of birth, on the advertisement's or the application's date. */
class AgeCalculationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-15 11:00:00'));
        $this->recruitment();
    }

    public static function calendarCases(): array
    {
        return [
            'day before birthday' => ['1993-04-12', '2026-04-11', 32, 11],
            'on birthday' => ['1993-04-12', '2026-04-12', 33, 0],
            'part month' => ['1993-04-12', '2026-11-15', 33, 7],
            'across a year end' => ['2001-12-15', '2002-01-14', 0, 0],
            'month complete across a year end' => ['2001-12-15', '2002-01-15', 0, 1],
            '31st into February (common year)' => ['2001-01-31', '2001-02-27', 0, 0],
            '31st completes on 28 February' => ['2001-01-31', '2001-02-28', 0, 1],
            '31st waits for 29 February in a leap year' => ['2004-01-31', '2004-02-28', 0, 0],
            '31st completes on 29 February' => ['2004-01-31', '2004-02-29', 0, 1],
            '31st into a 30-day month' => ['2001-03-31', '2001-04-30', 0, 1],
            '31st in a 31-day month' => ['2001-03-31', '2001-05-30', 0, 1],
            '31st on the 31st' => ['2001-03-31', '2001-05-31', 0, 2],
            '29 February before 28 February' => ['2000-02-29', '2001-02-27', 0, 11],
            '29 February on 28 February (common year)' => ['2000-02-29', '2001-02-28', 1, 0],
            '29 February in a leap year waits for the 29th' => ['2000-02-29', '2004-02-28', 3, 11],
            '29 February on 29 February' => ['2000-02-29', '2004-02-29', 4, 0],
            'same day' => ['2000-06-01', '2000-06-01', 0, 0],
        ];
    }

    #[DataProvider('calendarCases')]
    public function test_age_counts_completed_calendar_years_and_months(string $birth, string $on, int $years, int $months): void
    {
        $this->assertSame(['years' => $years, 'months' => $months], Age::between(Carbon::parse($birth), Carbon::parse($on)));
    }

    public function test_age_is_formatted_and_undefined_without_a_valid_pair_of_dates(): void
    {
        $this->assertSame('33 Years, 7 Months', Age::format(33, 7));
        $this->assertSame('1 Year, 1 Month', Age::format(1, 1));
        $this->assertSame('0 Years, 0 Months', Age::format(0, 0));
        $this->assertNull(Age::between(Carbon::parse('2026-11-16'), Carbon::parse('2026-11-15')));
        $this->assertNull(Age::between(null, Carbon::parse('2026-11-15')));
    }

    private function savePersonal(Application $app, array $changes)
    {
        $data = $this->actingAs($app->user)->getJson("/api/applications/{$app->uuid}")->json('data.personal');

        return $this->putJson("/api/applications/{$app->uuid}/sections/personal", array_merge($data, $changes));
    }

    /** The markup PdfService renders for a version: the PDF is always drawn from the frozen snapshot. */
    private function pdfHtml(array $snapshot): string
    {
        return view('pdf.application', ['s' => $snapshot, 'version' => null, 'photo' => null, 'signature' => null,
            'institute' => 'ABV-IIITM Gwalior', 'instituteAddress' => 'Gwalior', 'logo' => null])->render();
    }

    private function previewPersonal(Application $app): array
    {
        return $this->actingAs($app->user)->getJson("/api/applications/{$app->uuid}/preview")->assertOk()->json('snapshot.personal');
    }

    public function test_the_advertisements_age_reckoning_date_is_used_when_configured(): void
    {
        $this->post->update(['age_cutoff_date' => '2026-04-11']);
        $app = $this->completedDraft(); // born 12 April 1993

        $this->actingAs($app->user)->getJson("/api/applications/{$app->uuid}")->assertOk()
            ->assertJsonPath('data.personal.age_reference', ['date' => '2026-04-11', 'basis' => 'advertisement']);
        $p = $this->previewPersonal($app);
        $this->assertSame(['32 Years, 11 Months', 32, 11, '2026-04-11', 'advertisement'],
            [$p['age'], $p['age_years'], $p['age_months'], $p['age_reference_date'], $p['age_reference_basis']]);
    }

    public function test_without_one_age_is_reckoned_on_the_application_date(): void
    {
        $this->post->update(['age_cutoff_date' => null]);
        $app = $this->completedDraft();

        // A draft has no application date yet: today stands in until submission fixes it.
        $this->actingAs($app->user)->getJson("/api/applications/{$app->uuid}")
            ->assertJsonPath('data.personal.age_reference', ['date' => '2026-11-15', 'basis' => 'today']);
        $this->assertSame('33 Years, 7 Months', $this->previewPersonal($app)['age']);

        $this->postJson("/api/applications/{$app->uuid}/submit", ['confirm' => true])->assertOk();
        $snapshot = $app->fresh()->versions()->first()->snapshot;
        $this->assertSame('2026-11-15', $snapshot['application']['application_date']);
        $this->assertSame(['33 Years, 7 Months', '2026-11-15', 'application_date'],
            [$snapshot['personal']['age'], $snapshot['personal']['age_reference_date'], $snapshot['personal']['age_reference_basis']]);
    }

    public function test_changing_the_date_of_birth_recalculates_the_age(): void
    {
        $this->post->update(['age_cutoff_date' => '2026-12-31']);
        $app = $this->completedDraft();
        $this->assertSame('33 Years, 8 Months', $this->previewPersonal($app)['age']);

        $this->savePersonal($app, ['date_of_birth' => '1996-02-29'])->assertOk();
        $this->assertSame('30 Years, 10 Months', $this->previewPersonal($app)['age']);
    }

    public function test_a_future_date_of_birth_is_rejected(): void
    {
        $app = $this->completedDraft();
        $res = $this->savePersonal($app, ['date_of_birth' => '2026-11-16'])->assertUnprocessable();
        $this->assertSame(['The date of birth cannot be in the future.'], $res->json('errors.date_of_birth'));
        $this->assertSame('1993-04-12', $app->personal()->first()->date_of_birth->toDateString());

        // Today is not in the future (the minimum-age check still applies, as before).
        $this->savePersonal($app, ['date_of_birth' => '2026-11-15'])->assertUnprocessable()
            ->assertJsonPath('errors.date_of_birth.0', 'Check the date of birth.');
    }

    public function test_an_age_sent_by_the_browser_is_ignored(): void
    {
        $this->post->update(['age_cutoff_date' => '2026-04-11']);
        $app = $this->completedDraft();
        $this->savePersonal($app, [
            'age' => '25 Years, 0 Months', 'age_years' => 25, 'age_months' => 0, 'age_reference_date' => '2018-04-12',
            'age_reference' => ['date' => '2018-04-12', 'basis' => 'today'],
        ])->assertOk();

        $p = $this->previewPersonal($app);
        $this->assertSame(['32 Years, 11 Months', '2026-04-11', 'advertisement'], [$p['age'], $p['age_reference_date'], $p['age_reference_basis']]);
        $this->actingAs($app->user)->getJson("/api/applications/{$app->uuid}")
            ->assertJsonPath('data.personal.age_reference', ['date' => '2026-04-11', 'basis' => 'advertisement']);
    }

    public function test_the_submitted_snapshot_and_its_pdf_keep_the_age_when_the_configuration_changes(): void
    {
        $this->post->update(['age_cutoff_date' => '2026-04-11']);
        $app = $this->submitted();
        $version = $app->versions()->first();
        $this->assertSame('32 Years, 11 Months', $version->snapshot['personal']['age']);

        // The advertisement's date is edited afterwards: the live calculation moves, the official record does not.
        $this->post->update(['age_cutoff_date' => '2027-04-12']);
        $this->assertSame('34 Years, 0 Months', $app->fresh()->age()['text']);

        $version = $version->fresh();
        $this->assertSame(['32 Years, 11 Months', '2026-04-11'], [$version->snapshot['personal']['age'], $version->snapshot['personal']['age_reference_date']]);
        $html = $this->pdfHtml($version->snapshot);
        $this->assertStringContainsString('Age as on 11/04/2026', $html);
        $this->assertStringContainsString('32 Years, 11 Months', $html);
        $this->assertStringNotContainsString('34 Years', $html);

        $this->actingAs($this->admin)->getJson("/api/admin/applications/{$app->uuid}")->assertOk()
            ->assertJsonPath('snapshot.personal.age', '32 Years, 11 Months')
            ->assertJsonPath('snapshot.personal.age_reference_date', '2026-04-11');
        $this->actingAs($app->user)->get("/api/applications/{$app->uuid}/preview.pdf")->assertOk();
    }

    public function test_versions_frozen_before_ages_were_recorded_still_render(): void
    {
        $snapshot = $this->submitted()->versions()->first()->snapshot;
        unset($snapshot['personal']['age'], $snapshot['personal']['age_reference_date']);

        $html = $this->pdfHtml($snapshot);
        $this->assertStringNotContainsString('Age as on', $html);
    }
}
