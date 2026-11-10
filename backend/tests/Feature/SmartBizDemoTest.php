<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardService;
use App\Services\DateRange;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\SmartBizDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class SmartBizDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-10-27 12:00:00 UTC');
    }

    public function test_demo_seeding_is_repeatable_and_keeps_exact_counts_passwords_and_existing_data(): void
    {
        $this->seed(SmartBizDemoSeeder::class);
        $credentials = Storage::disk('local')->get('demo-accounts-testing.json');
        $manifest = json_decode($credentials, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(14, $manifest['accounts']);
        foreach (SmartBizDemoSeeder::COUNTS as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        foreach (['Admin' => 1, 'Manager' => 3, 'Staff' => 10] as $role => $count) {
            $this->assertSame($count, User::role($role)->count());
        }
        foreach ($manifest['accounts'] as $account) {
            $this->assertTrue(Hash::check($account['password'], User::where('email', $account['email'])->value('password')));
        }
        $this->assertSame(500, DB::table('customer_user')->count());
        $this->assertDatabaseCount('staff_schedules', 10);
        $this->assertDatabaseCount('staff_availability', 50);
        $this->assertDatabaseCount('task_comments', 30);
        $this->assertDatabaseCount('notifications', 10);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('automation_events', 0);
        $this->assertSame(0, DB::table('automation_workflows')->where('is_active', true)->count());
        $summary = app(DashboardService::class)->summary(User::role('Admin')->firstOrFail(), DateRange::from(['currency' => 'PHP', 'timezone' => 'Asia/Manila']));
        $this->assertGreaterThan(0, $summary['metrics']['pending_tasks']);
        $this->assertGreaterThan(0, $summary['metrics']['overdue_tasks']);
        $this->seed(SmartBizDemoSeeder::class);
        foreach (SmartBizDemoSeeder::COUNTS as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertSame($credentials, Storage::disk('local')->get('demo-accounts-testing.json'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'demo.seed.v1')->count());
    }

    public function test_demo_api_reports_csv_and_dashboard_match_independent_database_aggregates(): void
    {
        $this->seed(SmartBizDemoSeeder::class);
        $this->actingAs(User::role('Admin')->firstOrFail(), 'web');
        $filters = '?preset=custom&start_date=2025-11-01&end_date=2026-10-27&timezone=Asia%2FManila&currency=PHP';
        $start = CarbonImmutable::parse('2025-11-01', 'Asia/Manila')->utc();
        $end = CarbonImmutable::parse('2026-10-28', 'Asia/Manila')->utc();
        $sales = DB::table('sales')->where('currency', 'PHP')->where('status', 'completed')->where('closed_at', '>=', $start)->where('closed_at', '<', $end);
        $revenue = (string) BigDecimal::of((string) $sales->sum('amount'))->toScale(2);
        $this->getJson('/api/v1/dashboard/summary'.$filters)->assertOk()->assertJsonPath('data.metrics.total_customers', 500)->assertJsonPath('data.metrics.total_recorded_sales', $revenue)->assertJsonPath('data.lead_conversion.total', 300);
        foreach (['sales', 'customers', 'tasks', 'pipeline'] as $report) {
            $data = $this->getJson('/api/v1/reports'.$filters.'&report='.$report)->assertOk()->json('data');
            $csv = $this->get('/api/v1/reports/export'.$filters.'&report='.$report)->assertOk()->streamedContent();
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, $csv);
            rewind($stream);
            $rows = [];
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                $rows[] = $row;
            }
            fclose($stream);
            $header = array_search($data['columns'], $rows, true);
            $this->assertNotFalse($header);
            $this->assertSame(array_map(fn (array $row): array => array_map(strval(...), $row), $data['rows']), array_slice($rows, $header + 1));
            if ($report === 'sales') {
                foreach ($data['rows'] as [$month, $amount]) {
                    $from = CarbonImmutable::parse($month.'-01', 'Asia/Manila')->utc();
                    $to = $from->setTimezone('Asia/Manila')->addMonth()->utc();
                    $this->assertSame((string) BigDecimal::of((string) (clone $sales)->where('closed_at', '>=', $from)->where('closed_at', '<', $to)->sum('amount'))->toScale(2), $amount);
                }
            } elseif ($report === 'customers') {
                $this->assertSame(500, $data['rows'][array_key_last($data['rows'])][2]);
                $this->assertSame(500, array_sum(array_column($data['rows'], 1)));
            } elseif ($report === 'tasks') {
                $this->assertSame(300, array_sum(array_column($data['rows'], 1)));
                $this->assertSame(DB::table('tasks')->where('status', 'completed')->count(), array_sum(array_column($data['rows'], 2)));
            } else {
                $this->assertSame(300, array_sum(array_column($data['rows'], 1)));
            }
        }
        $this->getJson('/api/v1/dashboard/summary?preset=today&currency=USD')->assertOk()->assertJsonPath('data.metrics.total_recorded_sales', '0.00');
        $before = DB::table('sales')->where('status', 'completed')->sum('amount');
        $sale = DB::table('sales')->where('status', 'completed')->first();
        DB::table('sales')->where('id', $sale->id)->update(['amount' => DB::raw('amount + 100.25')]);
        $after = (string) BigDecimal::of((string) $before)->plus('100.25')->toScale(2);
        $this->getJson('/api/v1/dashboard/summary'.$filters)->assertOk()->assertJsonPath('data.metrics.total_recorded_sales', $after);
    }

    public function test_staff_data_is_scoped_and_management_only_reports_are_blocked_on_demo_data(): void
    {
        $this->seed(SmartBizDemoSeeder::class);
        $staff = User::role('Staff')->firstOrFail();
        $this->actingAs($staff, 'web');
        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonPath('meta.total', DB::table('tasks')->where('assigned_to', $staff->id)->count());
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.kind', 'task_assigned');
        $this->getJson('/api/v1/reports?report=sales')->assertForbidden();
        $this->getJson('/api/v1/reports/export?report=sales')->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_production_seeding_is_refused_before_any_record_is_inserted(): void
    {
        $this->app->instance('env', 'production');
        try {
            app(SmartBizDemoSeeder::class)->run();
            $this->fail('Production seeding must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('dedicated', $exception->getMessage());
            $this->assertDatabaseCount('users', 0);
        }
    }

    public function test_non_demo_database_and_nonempty_database_are_refused_without_changing_records(): void
    {
        $this->app->instance('env', 'local');
        try {
            app(SmartBizDemoSeeder::class)->run();
            $this->fail('Nondemo seeding must be refused.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('users', 0);
        }
        $this->app->instance('env', 'testing');
        $existing = User::factory()->create();
        try {
            app(SmartBizDemoSeeder::class)->run();
            $this->fail('Nonempty seeding must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nonempty', $exception->getMessage());
            $this->assertDatabaseCount('users', 1);
            $this->assertSame($existing->email, $existing->fresh()->email);
        }
    }
}
