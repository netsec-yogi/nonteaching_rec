<?php

namespace Tests\Feature;

use App\Models\Advertisement;
use App\Models\Application;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Application reference numbers are unique across all applications, while each advertisement keeps
 * its own counter. A number already issued under the same prefix must be skipped, never reused.
 */
class ReferenceNumberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->recruitment();
    }

    public function test_numbers_follow_the_advertisement_prefix_in_sequence(): void
    {
        $this->assertSame('IIITM/NT/26-03/00001', $this->submitted($this->applicant('one@example.com'))->reference_no);
        $this->assertSame('IIITM/NT/26-03/00002', $this->submitted($this->applicant('two@example.com'))->reference_no);
        $this->assertSame(2, $this->ad->fresh()->reference_sequence);
    }

    public function test_a_counter_behind_the_numbers_in_use_skips_them_instead_of_failing(): void
    {
        $this->submitted($this->applicant('one@example.com'));
        $this->submitted($this->applicant('two@example.com'));
        // A reset or restored counter: the next number it would issue is already taken.
        Advertisement::whereKey($this->ad->id)->update(['reference_sequence' => 0]);

        $third = $this->submitted($this->applicant('three@example.com'));
        $this->assertSame('IIITM/NT/26-03/00003', $third->reference_no);
        $this->assertSame(3, $this->ad->fresh()->reference_sequence);
    }

    public function test_numbers_held_by_withdrawn_or_deleted_applications_are_not_reused(): void
    {
        $first = $this->submitted($this->applicant('one@example.com'));
        $first->delete();
        Advertisement::whereKey($this->ad->id)->update(['reference_sequence' => 0]);

        $this->assertSame('IIITM/NT/26-03/00002', $this->submitted($this->applicant('two@example.com'))->reference_no);
    }

    public function test_a_second_advertisement_sharing_the_prefix_does_not_collide(): void
    {
        $this->assertSame('IIITM/NT/26-03/00001', $this->submitted($this->applicant('one@example.com'))->reference_no);

        // An advertisement created before prefixes had to be unique, reusing the same one.
        $second = $this->ad->replicate(['number', 'reference_sequence'])->fill(['number' => 'IIITM/NT/2026/04', 'reference_sequence' => 0]);
        $second->save();
        $post = $this->post->replicate()->fill(['advertisement_id' => $second->id]);
        $post->save();
        $this->post = $post;
        $this->ad = $second;

        $other = $this->submitted($this->applicant('two@example.com'));
        $this->assertSame($second->id, $other->advertisement_id);
        $this->assertSame('IIITM/NT/26-03/00002', $other->reference_no);
    }

    public function test_two_advertisements_cannot_be_given_the_same_reference_prefix(): void
    {
        $payload = fn (array $extra) => collect($this->ad->only(['recruitment_cycle_id', 'title', 'payment_methods', 'referees_required', 'referee_max_reminders', 'endorsement_mode', 'committee_evaluation_mode']))
            ->merge(['opens_at' => now()->toDateTimeString(), 'closes_at' => now()->addMonth()->toDateTimeString()])->merge($extra)->all();
        $this->actingAs($this->super);

        $this->postJson('/api/admin/advertisements', $payload(['number' => 'IIITM/NT/2026/05', 'reference_prefix' => 'IIITM/NT/26-03']))
            ->assertUnprocessable()->assertJsonValidationErrors('reference_prefix');
        $this->postJson('/api/admin/advertisements', $payload(['number' => 'IIITM/NT/2026/05', 'reference_prefix' => 'iiitm/nt/26-03']))
            ->assertUnprocessable()->assertJsonValidationErrors('reference_prefix');
        $this->postJson('/api/admin/advertisements', $payload(['number' => 'IIITM/NT/2026/05', 'reference_prefix' => 'IIITM/NT/26-05']))->assertCreated();

        // An advertisement keeps its own prefix when saved again.
        $this->putJson("/api/admin/advertisements/{$this->ad->id}", $payload(['number' => $this->ad->number, 'reference_prefix' => $this->ad->reference_prefix]))
            ->assertOk();
        $this->assertSame(2, Advertisement::count());
        $this->assertSame(0, Application::count());
    }
}
