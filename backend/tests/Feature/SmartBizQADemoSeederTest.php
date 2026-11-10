<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\StaffAvailability;
use App\Models\StaffSchedule;
use App\Models\Task;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\Qa2026Audit;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\SmartBizQADemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SmartBizQADemoSeederTest extends CrmTestCase
{
    protected function prepareDemo(): User
    {
        Storage::fake('local');
        $actor = $this->signIn('Admin');
        $actor->update(['email' => 'qa2026.admin@example.test']);
        foreach (['Staff', 'Staff', 'Manager', 'Manager'] as $role) {
            User::factory()->create()->assignRole($role);
        }
        StaffSchedule::create(['user_id' => $actor->id, 'timezone' => 'Asia/Manila']);
        foreach (range(1, 5) as $day) {
            StaffAvailability::create(['user_id' => $actor->id, 'day_of_week' => $day, 'start_time' => '09:00', 'end_time' => '17:00']);
        }
        $this->travelTo('2026-10-28 04:00:00 UTC');

        return $actor;
    }

    public function test_exact_usd_batch_is_repeatable_preserves_existing_rows_and_has_valid_relations(): void
    {
        $actor = $this->prepareDemo();
        Customer::factory()->create(['created_by' => $actor->id]);
        $audit = app(Qa2026Audit::class);
        $before = $audit->snapshot(DB::getDefaultConnection());
        $this->seed(SmartBizQADemoSeeder::class);
        $manifestText = Storage::disk('local')->get(SmartBizQADemoSeeder::MANIFEST);
        $manifest = json_decode($manifestText, true, flags: JSON_THROW_ON_ERROR);
        foreach (['customers' => 100, 'leads' => 80, 'sales' => 200, 'tasks' => 60, 'appointments' => 40] as $key => $expected) {
            $this->assertCount($expected, $manifest[$key]);
        }
        $this->assertSame([], $audit->preserved($before, $audit->snapshot(DB::getDefaultConnection()), true));
        $this->assertSame(180, Sale::where('currency', 'USD')->where('status', 'completed')->count());
        $this->assertSame(20, Sale::where('status', 'voided')->count());
        $this->assertSame(0, Sale::where('status', 'completed')->where('closed_at', '>', now())->count());
        $this->assertGreaterThan(1, Task::distinct()->count('assigned_to'));
        $this->assertGreaterThan(1, Sale::distinct()->count('recorded_by'));
        foreach (Appointment::all() as $appointment) {
            $this->assertTrue(app(AvailabilityService::class)->fits(app(AvailabilityService::class)->currentSchedule($actor->id), $appointment->start_at, $appointment->end_at));
            $this->assertTrue($appointment->customer->created_at->lessThanOrEqualTo($appointment->start_at));
        }
        foreach (Task::all() as $task) {
            $this->assertTrue($task->customer->created_at->lessThanOrEqualTo($task->created_at));
        }
        $after = $audit->snapshot(DB::getDefaultConnection());
        $this->seed(SmartBizQADemoSeeder::class);
        $this->assertSame($manifestText, Storage::disk('local')->get(SmartBizQADemoSeeder::MANIFEST));
        $this->assertSame([], $audit->preserved($after, $audit->snapshot(DB::getDefaultConnection()), false));
    }

    public function test_default_usd_dashboard_and_monthly_reports_match_real_database_sums(): void
    {
        $this->prepareDemo();
        $this->seed(SmartBizQADemoSeeder::class);
        $filters = '?preset=custom&start_date=2026-01-01&end_date=2026-12-31&timezone=Asia%2FManila&currency=USD';
        $expected = (string) BigDecimal::of((string) Sale::where('status', 'completed')->sum('amount'))->toScale(2);
        $this->getJson('/api/v1/dashboard/summary'.$filters)->assertOk()->assertJsonPath('data.metrics.total_recorded_sales', $expected);
        $rows = $this->getJson('/api/v1/reports'.$filters.'&report=sales')->assertOk()->json('data.rows');
        foreach (array_slice($rows, 0, 10) as [$month, $amount]) {
            $from = CarbonImmutable::parse($month.'-01', 'Asia/Manila');
            $value = (string) BigDecimal::of((string) Sale::where('status', 'completed')->where('closed_at', '>=', $from->utc())->where('closed_at', '<', $from->addMonth()->utc())->sum('amount'))->toScale(2);
            $this->assertSame($value, $amount);
            $this->assertGreaterThan(0, (float) $amount);
        }
        $recent = $this->getJson('/api/v1/dashboard/summary?preset=last_30_days&timezone=America%2FNew_York')->assertOk()->json('data.metrics');
        foreach (['total_recorded_sales', 'pending_tasks', 'overdue_tasks', 'upcoming_appointments'] as $metric) {
            $this->assertGreaterThan(0, (float) $recent[$metric]);
        }
    }

    public function test_production_and_non_demo_targets_cannot_seed(): void
    {
        $this->prepareDemo();
        $this->app->instance('env', 'production');
        $this->expectException(RuntimeException::class);
        app(SmartBizQADemoSeeder::class)->run();
    }
}
