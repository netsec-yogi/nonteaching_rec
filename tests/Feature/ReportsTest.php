<?php

namespace Tests\Feature;

use App\Models\ApplicationPersonalDetail;
use App\Services\ReportService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->recruitment();
        $this->submitted();
    }

    public function test_every_report_builds_for_the_super_administrator(): void
    {
        $this->actingAs($this->super);
        foreach (array_keys(ReportService::REPORTS) as $key) {
            $this->getJson("/api/admin/reports/$key")->assertOk()->assertJsonStructure(['title', 'headings', 'rows', 'truncated']);
        }
        $rows = $this->getJson('/api/admin/reports/by_category')->json('rows');
        $this->assertSame([['OBC', 1]], $rows);
    }

    public function test_exports_stream_files_and_sensitive_exports_are_logged(): void
    {
        $this->actingAs($this->admin);
        $csv = $this->get('/api/admin/reports/consolidated/export/csv')->assertOk();
        $this->assertStringContainsString('IIITM/NT/26-03/00001', $csv->streamedContent());
        $this->assertStringContainsString('Referee 3 recommendation status', $csv->streamedContent());
        $this->assertStringNotContainsString('Good candidate', $csv->streamedContent());
        $this->assertDatabaseHas('export_logs', ['user_id' => $this->admin->id, 'report' => 'consolidated', 'is_sensitive' => true, 'row_count' => 1]);

        $this->get('/api/admin/reports/fee_status/export/xlsx')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->get('/api/admin/reports/vacancy_stats/export/pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_referee_content_export_requires_its_own_permission(): void
    {
        $this->actingAs($this->admin)->get('/api/admin/reports/referee_content/export/csv')->assertForbidden();
        $this->actingAs($this->super)->get('/api/admin/reports/referee_content/export/csv')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'export.sensitive']);
    }

    public function test_spreadsheet_formula_injection_is_neutralised(): void
    {
        ApplicationPersonalDetail::query()->update(['full_name' => '=HYPERLINK("http://evil")']);
        $csv = $this->actingAs($this->super)->get('/api/admin/reports/consolidated/export/csv')->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }
}
