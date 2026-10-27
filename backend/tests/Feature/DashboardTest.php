<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Sale;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;

class DashboardTest extends CrmTestCase
{
    private function range(string $extra = ''): string
    {
        return '?preset=custom&start_date=2026-10-01&end_date=2026-10-26&timezone=UTC&currency=USD'.$extra;
    }

    public function test_anonymous_and_roleless_users_cannot_read_dashboard(): void
    {
        $this->getJson('/api/v1/dashboard/summary')->assertUnauthorized();
        $this->getJson('/api/v1/dashboard/analytics')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/v1/dashboard/summary')->assertForbidden();
    }

    public function test_summary_uses_real_status_cohorts_and_exact_currency_filtered_revenue(): void
    {
        $manager = $this->signIn();
        $this->travelTo('2026-10-26 12:00:00');
        $customer = Customer::factory()->create(['created_at' => '2026-10-02 00:00:00']);
        $archived = Customer::factory()->create(['created_at' => '2026-10-03 00:00:00']);
        $archived->delete();
        foreach (['new', 'qualified', 'won', 'lost'] as $slug) {
            Lead::factory()->create(['created_at' => '2026-10-04 00:00:00', 'pipeline_stage_id' => PipelineStage::where('slug', $slug)->value('id')]);
        }
        Lead::factory()->create(['created_at' => '2026-09-30 23:59:59']);
        Sale::factory()->for($customer)->create(['amount' => '0.10', 'closed_at' => '2026-10-04 00:00:00']);
        Sale::factory()->for($customer)->create(['amount' => '0.20', 'closed_at' => '2026-10-05 00:00:00']);
        Sale::factory()->for($customer)->create(['amount' => '100.00', 'currency' => 'EUR', 'closed_at' => '2026-10-05 00:00:00']);
        Sale::factory()->for($customer)->create(['amount' => '100.00', 'status' => 'voided', 'closed_at' => '2026-10-05 00:00:00']);
        Task::factory()->create(['created_at' => '2026-10-02', 'status' => 'pending', 'due_date' => '2026-10-25 00:00:00']);
        Task::factory()->create(['created_at' => '2026-10-02', 'status' => 'in_progress', 'due_date' => null]);
        Task::factory()->create(['created_at' => '2026-10-02', 'status' => 'completed', 'completed_at' => '2026-10-23', 'due_date' => '2026-10-01']);
        Appointment::factory()->for($customer)->create(['start_at' => '2026-10-26 13:00:00', 'end_at' => '2026-10-26 14:00:00']);
        Appointment::factory()->for($customer)->create(['start_at' => '2026-10-26 10:00:00', 'end_at' => '2026-10-26 11:00:00']);
        Appointment::factory()->for($customer)->create(['status' => 'cancelled', 'start_at' => '2026-10-26 15:00:00', 'end_at' => '2026-10-26 16:00:00']);
        $this->getJson('/api/v1/dashboard/summary'.$this->range())->assertOk()->assertJsonPath('data.metrics.total_customers', 1)->assertJsonPath('data.metrics.active_leads', 2)->assertJsonPath('data.metrics.qualified_leads', 1)->assertJsonPath('data.metrics.won_deals', 1)->assertJsonPath('data.metrics.total_recorded_sales', '0.30')->assertJsonPath('data.metrics.pending_tasks', 2)->assertJsonPath('data.metrics.overdue_tasks', 1)->assertJsonPath('data.metrics.upcoming_appointments', 1)->assertJsonPath('data.lead_conversion.won_rate', 25);
    }

    public function test_staff_aggregates_exclude_other_users_records_in_every_module(): void
    {
        $staff = $this->signIn('Staff');
        $this->travelTo('2026-10-26 12:00:00');
        $customer = Customer::factory()->create();
        $customer->assignees()->attach($staff);
        $own = Lead::factory()->for($staff, 'assignee')->for($customer)->create();
        $hidden = Lead::factory()->create();
        Sale::factory()->for($customer)->for($own)->create(['amount' => '15.25']);
        Sale::factory()->for($customer)->for($hidden)->create(['amount' => '9000.00']);
        Sale::factory()->for($customer)->create(['amount' => '5000.00']);
        Task::factory()->for($staff, 'assignee')->create(['due_date' => '2026-10-25']);
        Task::factory()->create(['due_date' => '2026-10-25']);
        Appointment::factory()->for($staff, 'assignee')->for($customer)->create();
        Appointment::factory()->create();
        $this->getJson('/api/v1/dashboard/summary'.$this->range())->assertOk()->assertJsonPath('data.metrics.total_customers', 1)->assertJsonPath('data.metrics.active_leads', 1)->assertJsonPath('data.metrics.total_recorded_sales', '15.25')->assertJsonPath('data.metrics.pending_tasks', 1)->assertJsonPath('data.metrics.overdue_tasks', 1)->assertJsonPath('data.metrics.upcoming_appointments', 1);
        $this->getJson('/api/v1/dashboard/analytics'.$this->range())->assertOk()->assertJsonPath('data.monthly_sales.0.amount', '15.25')->assertJsonPath('data.pipeline.0.count', 1)->assertJsonPath('data.customer_growth.25.total_customers', 1)->assertJsonPath('data.task_completion.25.created', 1);
    }

    public function test_monthly_sales_and_growth_zero_fill_missing_calendar_buckets(): void
    {
        $this->signIn();
        $this->travelTo('2026-10-26 12:00:00');
        Customer::factory()->create(['created_at' => '2026-08-01']);
        Customer::factory()->create(['created_at' => '2026-10-02 00:00:00']);
        Sale::factory()->create(['amount' => '123.45', 'closed_at' => '2026-10-03 00:00:00']);
        Task::factory()->create(['created_at' => '2026-08-01', 'status' => 'completed', 'completed_at' => '2026-10-02 18:00:00']);
        $response = $this->getJson('/api/v1/dashboard/analytics?preset=custom&start_date=2026-09-30&end_date=2026-10-03&timezone=UTC')->assertOk();
        $response->assertJsonPath('data.monthly_sales.0.month', '2026-09')->assertJsonPath('data.monthly_sales.0.amount', '0.00')->assertJsonPath('data.monthly_sales.1.amount', '123.45')->assertJsonCount(4, 'data.customer_growth')->assertJsonPath('data.customer_growth.0.new_customers', 0)->assertJsonPath('data.customer_growth.2.new_customers', 1)->assertJsonPath('data.task_completion.2.completed', 1)->assertJsonPath('data.task_completion.2.created', 0);
    }

    #[TestWith(['America/New_York', '2026-03-08', '2026-03-08 05:00:00', '2026-03-09 04:00:00'])]
    #[TestWith(['America/New_York', '2026-11-01', '2026-11-01 04:00:00', '2026-11-02 05:00:00'])]
    #[TestWith(['America/Havana', '2026-03-08', '2026-03-08 05:00:00', '2026-03-09 04:00:00'])]
    public function test_custom_local_days_handle_dst_and_exclusive_midnight(string $timezone, string $date, string $start, string $end): void
    {
        $this->signIn();
        $this->travelTo('2027-01-01 12:00:00');
        Customer::factory()->create(['created_at' => $start]);
        Customer::factory()->create(['created_at' => $end]);
        $query = '?preset=custom&start_date='.$date.'&end_date='.$date.'&timezone='.urlencode($timezone);
        $this->getJson('/api/v1/dashboard/summary'.$query)->assertOk()->assertJsonPath('data.metrics.total_customers', 1);
        $this->getJson('/api/v1/dashboard/analytics'.$query)->assertOk()->assertJsonCount(1, 'data.customer_growth')->assertJsonPath('data.customer_growth.0.new_customers', 1)->assertJsonPath('data.customer_growth.0.date', $date);
    }

    #[TestWith(['today', '2026-10-26', '2026-10-26'])]
    #[TestWith(['last_7_days', '2026-10-20', '2026-10-26'])]
    #[TestWith(['last_30_days', '2026-09-27', '2026-10-26'])]
    #[TestWith(['this_month', '2026-10-01', '2026-10-26'])]
    public function test_presets_use_inclusive_local_calendar_dates(string $preset, string $start, string $end): void
    {
        $this->signIn();
        $this->travelTo('2026-10-27 01:00:00');
        $this->getJson('/api/v1/dashboard/summary?preset='.$preset.'&timezone=America%2FLos_Angeles')->assertOk()->assertJsonPath('data.filters.start_date', $start)->assertJsonPath('data.filters.end_date', $end);
    }

    #[TestWith(['?preset=custom&start_date=2026-10-20&end_date=2026-10-01', 'end_date'])]
    #[TestWith(['?preset=custom&start_date=2020-01-01&end_date=2026-10-01', 'end_date'])]
    #[TestWith(['?preset=custom', 'start_date'])]
    #[TestWith(['?timezone=Invalid%2FZone', 'timezone'])]
    #[TestWith(['?currency=FAKE', 'currency'])]
    #[TestWith(['?preset=all_time', 'preset'])]
    public function test_invalid_filters_return_422(string $query, string $field): void
    {
        $this->signIn();
        $this->getJson('/api/v1/dashboard/analytics'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    #[TestWith(['Asia/Calcutta'])]
    #[TestWith(['Europe/Kiev'])]
    public function test_browser_timezone_aliases_are_supported(string $timezone): void
    {
        $this->signIn();
        $this->getJson('/api/v1/dashboard/summary?preset=today&timezone='.urlencode($timezone))
            ->assertOk()->assertJsonPath('data.filters.timezone', $timezone);
    }

    public function test_empty_dashboard_is_computed_without_nan_or_missing_buckets(): void
    {
        $this->signIn();
        $this->travelTo('2026-10-26 12:00:00');
        $this->getJson('/api/v1/dashboard/summary?preset=today')->assertOk()->assertJsonPath('data.metrics.total_recorded_sales', '0.00')->assertJsonPath('data.lead_conversion.won_rate', 0);
        $this->getJson('/api/v1/dashboard/analytics?preset=today')->assertOk()->assertJsonCount(1, 'data.monthly_sales')->assertJsonCount(1, 'data.task_completion')->assertJsonCount(7, 'data.pipeline');
    }

    public function test_analytics_query_count_does_not_scale_with_calendar_days(): void
    {
        $this->signIn();
        $this->travelTo('2026-10-26 12:00:00');
        DB::enableQueryLog();
        $this->getJson('/api/v1/dashboard/analytics?preset=today')->assertOk();
        $short = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->getJson('/api/v1/dashboard/analytics?preset=custom&start_date=2025-10-27&end_date=2026-10-26')->assertOk();
        $long = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($short + 1, $long);
    }
}
