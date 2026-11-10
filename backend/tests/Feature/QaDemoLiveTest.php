<?php

namespace Tests\Feature;

use App\Models\AutomationWorkflow;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Services\Qa2026Audit;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class QaDemoLiveTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        if (getenv('SMARTBIZ_QA_LIVE') !== '1') {
            $this->markTestSkipped('Opt-in smartbiz_demo transaction-only integration tests; no migrations or resets.');
        }
        parent::setUp();
        if (AutomationWorkflow::where('is_active', true)->exists()) {
            $this->markTestSkipped('Enabled workflows require review before transaction-only mutation tests.');
        }
        $this->travelTo('2026-10-28 04:00:00 UTC');
        $this->actingAs(User::where('email', 'qa2026.admin@example.test')->firstOrFail(), 'web');
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['first_name' => 'QA transient', 'last_name' => 'Customer', 'email' => 'transaction-'.Str::uuid().'@example.test', 'phone' => '+63 000 000 9999', 'company' => 'QA transaction rollback fixture', 'address' => 'Fictional QA address', 'status' => 'active', 'assigned_user_ids' => []], $changes);
    }

    public function test_customer_crud_validation_search_filters_pagination_and_version_conflicts(): void
    {
        $payload = $this->payload();
        $customer = $this->postJson('/api/v1/customers', $payload)->assertCreated()->json('data');
        $this->postJson('/api/v1/customers', $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/customers', $this->payload(['first_name' => '', 'email' => 'invalid']))->assertUnprocessable();
        $this->getJson('/api/v1/customers?search='.urlencode($payload['email']).'&status=active&per_page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->putJson('/api/v1/customers/'.$customer['id'], [...$payload, 'company' => 'Edited rollback fixture', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson('/api/v1/customers/'.$customer['id'], [...$payload, 'version' => 1])->assertConflict();
        $this->deleteJson('/api/v1/customers/'.$customer['id'], ['version' => 2])->assertOk();
        $this->getJson('/api/v1/customers/'.$customer['id'])->assertNotFound();
    }

    public function test_sales_recording_idempotency_amount_validation_date_filter_and_voiding(): void
    {
        $customer = Customer::where('customer_code', 'like', 'QA2026-%')->firstOrFail();
        $payload = ['customer_id' => $customer->id, 'amount' => '1250.75', 'currency' => 'PHP', 'closed_at' => '2026-10-27T04:00:00Z', 'request_id' => (string) Str::uuid()];
        $id = $this->postJson('/api/v1/sales', $payload)->assertCreated()->assertJsonPath('data.amount', '1250.75')->json('data.id');
        $this->postJson('/api/v1/sales', $payload)->assertOk()->assertJsonPath('data.id', $id);
        foreach (['0', '-1', '0.001'] as $amount) {
            $this->postJson('/api/v1/sales', [...$payload, 'request_id' => (string) Str::uuid(), 'amount' => $amount])->assertUnprocessable();
        }
        $this->postJson('/api/v1/sales', [...$payload, 'request_id' => (string) Str::uuid(), 'closed_at' => '2026-11-01T00:00:00Z'])->assertUnprocessable();
        $this->patchJson('/api/v1/sales/'.$id.'/void', ['version' => 1, 'reason' => 'QA transaction rollback correction'])->assertOk()->assertJsonPath('data.status', 'voided');
        $this->assertSame('voided', Sale::findOrFail($id)->status);
        $this->getJson('/api/v1/sales?currency=PHP&status=voided&customer_id='.$customer->id)->assertOk();
    }

    public function test_appointment_conflict_edit_cancel_and_task_assignment_completion(): void
    {
        $admin = User::where('email', 'qa2026.admin@example.test')->firstOrFail();
        $customer = Customer::where('customer_code', 'like', 'QA2026-%')->firstOrFail();
        $payload = ['title' => 'QA transient appointment', 'assigned_to' => $admin->id, 'customer_id' => $customer->id, 'status' => 'scheduled', 'start_at' => '2026-11-23T02:00:00Z', 'end_at' => '2026-11-23T03:00:00Z'];
        $id = $this->postJson('/api/v1/appointments', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/appointments', [...$payload, 'start_at' => '2026-11-23T02:30:00Z', 'end_at' => '2026-11-23T03:30:00Z'])->assertConflict();
        $this->putJson('/api/v1/appointments/'.$id, [...$payload, 'title' => 'QA transient edited', 'version' => 1])->assertOk();
        $this->patchJson('/api/v1/appointments/'.$id.'/cancel', ['version' => 2])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson('/api/v1/appointments', [...$payload, 'start_at' => '2026-10-27T00:00:00Z', 'end_at' => '2026-10-27T01:00:00Z'])->assertUnprocessable();
        $staff = User::role('Staff')->firstOrFail();
        $task = $this->postJson('/api/v1/tasks', ['title' => 'QA transient task', 'assigned_to' => $staff->id, 'customer_id' => $customer->id, 'priority' => 'high', 'status' => 'pending', 'due_date' => '2026-10-27T00:00:00Z'])->assertCreated()->json('data');
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'web');
        $this->patchJson('/api/v1/tasks/'.$task['id'].'/status', ['version' => 1, 'status' => 'completed'])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_each_annual_report_csv_and_dashboard_matches_independent_sql(): void
    {
        $result = app(Qa2026Audit::class)->validate(User::where('email', 'qa2026.admin@example.test')->firstOrFail());
        $this->assertSame([], $result['failures']);
        $filters = '?preset=custom&start_date=2026-01-01&end_date=2026-12-31&timezone=Asia%2FManila&currency=PHP';
        foreach (['sales', 'customers', 'tasks', 'pipeline'] as $type) {
            $report = $this->getJson('/api/v1/reports'.$filters.'&report='.$type)->assertOk()->json('data');
            $csv = $this->get('/api/v1/reports/export'.$filters.'&report='.$type)->assertOk()->streamedContent();
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, $csv);
            rewind($stream);
            $rows = [];
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                $rows[] = $row;
            }
            fclose($stream);
            $header = array_search($report['columns'], $rows, true);
            $this->assertNotFalse($header);
            $this->assertSame(array_map(fn (array $row): array => array_map(strval(...), $row), $report['rows']), array_slice($rows, $header + 1));
        }
        $this->getJson('/api/v1/dashboard/summary'.$filters)->assertOk()->assertJsonPath('data.metrics.total_recorded_sales', $result['summary']['metrics']['total_recorded_sales']);
    }

    public function test_logout_changes_only_expected_authentication_metadata(): void
    {
        $user = User::where('email', 'qa2026.admin@example.test')->firstOrFail();
        $before = $user->getRawOriginal();
        $roles = $user->getRoleNames()->all();
        $this->withHeaders(['Origin' => config('cors.allowed_origins')[0]])->postJson('/api/v1/logout')->assertOk();
        $after = $user->fresh()->getRawOriginal();
        $changed = array_keys(array_filter($after, fn (mixed $value, string $field): bool => ($before[$field] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
        $this->assertContains('remember_token', $changed);
        $this->assertSame([], array_values(array_diff($changed, ['remember_token', 'updated_at', 'last_login_at'])));
        $this->assertSame($before['password'], $after['password']);
        $this->assertSame($roles, $user->fresh()->getRoleNames()->all());
    }

    #[TestWith(['today', '2026-10-28'])]
    #[TestWith(['last_7_days', '2026-10-22'])]
    #[TestWith(['last_30_days', '2026-09-29'])]
    #[TestWith(['this_month', '2026-10-01'])]
    public function test_daily_weekly_and_monthly_revenue_filters_match_fixed_local_sql_bounds(string $preset, string $start): void
    {
        $from = CarbonImmutable::parse($start, 'Asia/Manila')->utc();
        $until = CarbonImmutable::parse('2026-10-29', 'Asia/Manila')->utc();
        $expected = (string) BigDecimal::of((string) DB::table('sales')->where('status', 'completed')->where('currency', 'PHP')->where('closed_at', '>=', $from)->where('closed_at', '<', $until)->sum('amount'))->toScale(2);
        $this->getJson('/api/v1/dashboard/summary?preset='.$preset.'&timezone=Asia%2FManila&currency=PHP')->assertOk()->assertJsonPath('data.metrics.total_recorded_sales', $expected);
        if ($preset === 'today') {
            $this->assertGreaterThan(0, (float) $expected);
        }
    }

    #[TestWith(['Admin', true, true])]
    #[TestWith(['Manager', false, true])]
    #[TestWith(['Staff', false, false])]
    public function test_existing_role_boundaries_on_preserved_demo(string $role, bool $users, bool $reports): void
    {
        $this->app['auth']->forgetGuards();
        $actor = User::role($role)->firstOrFail();
        $this->actingAs($actor, 'web');
        $this->getJson('/api/v1/dashboard/summary')->assertOk();
        $this->getJson('/api/v1/dashboard/analytics')->assertOk();
        $this->getJson('/api/v1/users')->assertStatus($users ? 200 : 403);
        $this->getJson('/api/v1/reports?report=sales')->assertStatus($reports ? 200 : 403);
    }
}
