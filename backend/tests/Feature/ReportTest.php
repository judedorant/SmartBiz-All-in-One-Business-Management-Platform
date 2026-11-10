<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Sale;
use App\Models\Task;
use App\Models\User;
use PHPUnit\Framework\Attributes\TestWith;

class ReportTest extends CrmTestCase
{
    private function filters(string $report): string
    {
        return '?report='.$report.'&preset=custom&start_date=2026-10-01&end_date=2026-10-02&timezone=UTC&currency=USD';
    }

    public function test_reports_and_downloads_require_authentication_and_report_permissions(): void
    {
        foreach (['/api/v1/reports', '/api/v1/reports/export'] as $path) {
            $this->getJson($path.$this->filters('sales'))->assertUnauthorized();
        }
        $this->signIn('Staff');
        foreach (['/api/v1/reports', '/api/v1/reports/export'] as $path) {
            $this->getJson($path.$this->filters('sales'))->assertForbidden();
        }
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/v1/reports'.$this->filters('sales'))->assertForbidden();
    }

    #[TestWith(['Manager'])]
    #[TestWith(['Admin'])]
    public function test_management_roles_can_preview_and_download_each_report(string $role): void
    {
        $this->signIn($role);
        foreach (['sales', 'customers', 'tasks', 'pipeline'] as $report) {
            $preview = $this->getJson('/api/v1/reports'.$this->filters($report))->assertOk()->assertJsonPath('data.report', $report);
            $download = $this->get('/api/v1/reports/export'.$this->filters($report))->assertOk()->assertDownload('smartbiz-'.$report.'-2026-10-01-2026-10-02.csv');
            $this->assertStringContainsString($preview->json('data.title'), $download->streamedContent());
            $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        }
    }

    public function test_sales_preserve_decimals_and_exclude_voids_other_currencies_and_end_boundary(): void
    {
        $this->signIn();
        Sale::factory()->create(['amount' => '0.10', 'closed_at' => '2026-10-01 00:00:00']);
        Sale::factory()->create(['amount' => '0.20', 'closed_at' => '2026-10-02 23:59:59']);
        Sale::factory()->create(['amount' => '999.00', 'currency' => 'EUR', 'closed_at' => '2026-10-01']);
        Sale::factory()->create(['amount' => '999.00', 'status' => 'voided', 'closed_at' => '2026-10-01']);
        Sale::factory()->create(['amount' => '999.00', 'closed_at' => '2026-10-03 00:00:00']);
        $this->getJson('/api/v1/reports'.$this->filters('sales'))->assertOk()->assertJsonPath('data.rows.0.1', '0.30');
        $csv = $this->get('/api/v1/reports/export'.$this->filters('sales'))->assertOk()->streamedContent();
        $this->assertStringContainsString('2026-10,0.30', $csv);
        $this->assertStringNotContainsString('999', $csv);
    }

    public function test_customer_and_task_reports_use_distinct_creation_and_completion_dates(): void
    {
        $this->signIn();
        Customer::factory()->create(['created_at' => '2026-09-01']);
        Customer::factory()->create(['created_at' => '2026-10-02']);
        $archived = Customer::factory()->create(['created_at' => '2026-10-02']);
        $archived->delete();
        Task::factory()->create(['created_at' => '2026-09-01', 'status' => 'completed', 'completed_at' => '2026-10-02']);
        $this->getJson('/api/v1/reports'.$this->filters('customers'))->assertOk()->assertJsonPath('data.rows', [['2026-10-01', 0, 1], ['2026-10-02', 1, 2]]);
        $this->getJson('/api/v1/reports'.$this->filters('tasks'))->assertOk()->assertJsonPath('data.rows', [['2026-10-01', 0, 0], ['2026-10-02', 0, 1]]);
    }

    public function test_pipeline_exports_escape_spreadsheet_formulas_and_csv_delimiters(): void
    {
        $this->signIn();
        $stage = PipelineStage::orderBy('position')->first();
        $stage->update(['name' => '=SUM(1,2)']);
        Lead::factory()->create(['pipeline_stage_id' => $stage->id, 'created_at' => '2026-10-01', 'estimated_value' => '123.45']);
        $this->getJson('/api/v1/reports'.$this->filters('pipeline'))->assertOk()->assertJsonPath('data.rows.0', ['=SUM(1,2)', 1, '123.45']);
        $csv = $this->get('/api/v1/reports/export'.$this->filters('pipeline'))->assertOk()->streamedContent();
        $this->assertStringContainsString('"\'=SUM(1,2)",1,123.45', $csv);
    }

    #[TestWith(['?report=unknown'])]
    #[TestWith(['?report=sales&preset=custom&start_date=2020-01-01&end_date=2026-10-01'])]
    #[TestWith(['?report=sales&currency=INVALID'])]
    #[TestWith(['?report=sales&timezone=Invalid/Zone'])]
    #[TestWith(['?report=sales&preset=custom&start_date=2026-10-02&end_date=2026-10-01'])]
    public function test_invalid_filters_are_rejected_for_preview_and_export(string $query): void
    {
        $this->signIn();
        $this->getJson('/api/v1/reports'.$query)->assertUnprocessable();
        $this->getJson('/api/v1/reports/export'.$query)->assertUnprocessable();
    }

    public function test_report_timezone_uses_local_calendar_boundaries(): void
    {
        $this->signIn();
        Sale::factory()->create(['amount' => '5.25', 'closed_at' => '2026-03-08 05:00:00']);
        Sale::factory()->create(['amount' => '999.00', 'closed_at' => '2026-03-09 04:00:00']);
        $this->getJson('/api/v1/reports?report=sales&preset=custom&start_date=2026-03-08&end_date=2026-03-08&timezone=America/New_York')->assertOk()->assertJsonPath('data.rows.0.1', '5.25');
    }
}
