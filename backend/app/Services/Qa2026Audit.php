<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\Qa2026ScenarioSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class Qa2026Audit
{
    public const TABLES = ['users', 'roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'customers', 'customer_user', 'customer_notes', 'customer_activities', 'leads', 'lead_activities', 'pipeline_stages', 'sales', 'appointments', 'appointment_reminders', 'staff_schedules', 'staff_availability', 'tasks', 'task_comments', 'notifications', 'automation_workflows', 'automation_events', 'automation_logs', 'automation_steps', 'integration_accounts', 'webhook_endpoints', 'webhook_deliveries', 'webhook_receipts'];

    public function snapshot(string $connection): array
    {
        $snapshot = [];
        foreach (self::TABLES as $table) {
            $rows = [];
            $stableUsers = [];
            foreach (DB::connection($connection)->table($table)->get() as $record) {
                $row = (array) $record;
                if ($table === 'users') {
                    unset($row['last_login_at'], $row['updated_at']);
                }
                ksort($row);
                $json = json_encode($row, JSON_THROW_ON_ERROR);
                $rows[(string) ($row['id'] ?? hash('sha256', $json))] = hash('sha256', $json);
                if ($table === 'users') {
                    unset($row['remember_token']);
                    $stableUsers[(string) $row['id']] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
                }
            }
            $snapshot[$table] = ['count' => count($rows), 'rows' => $rows];
            if ($table === 'users') {
                $snapshot[$table]['stable_user_rows'] = $stableUsers;
            }
        }

        return $snapshot;
    }

    public function preserved(array $baseline, array $current, bool $allowAdditions): array
    {
        $failures = [];
        foreach ($baseline as $table => $records) {
            foreach ($records['rows'] as $id => $hash) {
                $expected = $table === 'users' && isset($records['stable_user_rows']) ? $records['stable_user_rows'][$id] : $hash;
                $actual = $table === 'users' && isset($records['stable_user_rows']) ? ($current[$table]['stable_user_rows'][$id] ?? null) : ($current[$table]['rows'][$id] ?? null);
                if ($actual !== $expected) {
                    $failures[] = $table.':'.$id.' changed or disappeared';
                }
            }
            if (! $allowAdditions && count($current[$table]['rows']) !== $records['count']) {
                $failures[] = $table.' record count changed';
            }
        }

        return $failures;
    }

    public function validate(User $admin, string $currency = 'PHP'): array
    {
        $range = DateRange::from(['preset' => 'custom', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'timezone' => 'Asia/Manila', 'currency' => $currency]);
        $checks = [];
        $check = function (string $name, mixed $expected, mixed $actual) use (&$checks): void {
            $checks[] = ['name' => $name, 'expected' => $expected, 'actual' => $actual, 'passed' => $expected === $actual];
        };
        $summary = app(DashboardService::class)->summary($admin, $range);
        $m = $summary['metrics'];
        $check('dashboard.customers', $this->within('customers', 'created_at', $range)->count(), $m['total_customers']);
        $check('dashboard.revenue', $this->money($this->within('sales', 'closed_at', $range)->where('currency', $currency)->where('status', 'completed')->sum('amount')), $m['total_recorded_sales']);
        $check('dashboard.pending_tasks', $this->within('tasks', 'created_at', $range)->whereIn('status', ['pending', 'in_progress'])->count(), $m['pending_tasks']);
        $check('dashboard.overdue_tasks', $this->within('tasks', 'created_at', $range)->whereIn('status', ['pending', 'in_progress'])->where('due_date', '<', $range->now)->count(), $m['overdue_tasks']);
        $check('dashboard.upcoming', $this->within('appointments', 'start_at', $range)->whereIn('status', ['scheduled', 'confirmed'])->where('start_at', '>=', $range->now)->count(), $m['upcoming_appointments']);
        $won = $this->leadCount('won', $range);
        $lost = $this->leadCount('lost', $range);
        $qualified = $this->leadCount('qualified', $range);
        $total = $this->within('leads', 'created_at', $range)->count();
        $check('dashboard.won', $won, $m['won_deals']);
        $check('dashboard.qualified', $qualified, $m['qualified_leads']);
        $check('dashboard.open', $total - $won - $lost, $m['active_leads']);
        $check('dashboard.won_rate', $total ? round(100 * $won / $total, 2) : 0.0, (float) $summary['lead_conversion']['won_rate']);
        $check('completed_sales_after_reference', 0, DB::table('sales')->where('status', 'completed')->where('closed_at', '>', CarbonImmutable::parse(Qa2026ScenarioSeeder::REFERENCE)->utc())->count());
        $periods = [];
        foreach (['today' => '2026-10-28', 'last_7_days' => '2026-10-22', 'last_30_days' => '2026-09-29', 'this_month' => '2026-10-01'] as $preset => $start) {
            $from = CarbonImmutable::parse($start, 'Asia/Manila')->utc();
            $until = CarbonImmutable::parse('2026-10-29', 'Asia/Manila')->utc();
            $expected = $this->money(DB::table('sales')->where('status', 'completed')->where('currency', $currency)->where('closed_at', '>=', $from)->where('closed_at', '<', $until)->sum('amount'));
            $actual = app(DashboardService::class)->summary($admin, DateRange::from(['preset' => $preset, 'timezone' => 'Asia/Manila', 'currency' => $currency]))['metrics']['total_recorded_sales'];
            $check('revenue_period.'.$preset, $expected, $actual);
            $periods[$preset] = ['start_date' => $start, 'end_date' => '2026-10-28', 'expected' => $expected, 'actual' => $actual];
        }
        $reports = [];
        foreach (['sales', 'customers', 'tasks', 'pipeline'] as $type) {
            $report = app(ReportService::class)->generate($admin, $range, $type);
            $reports[$type] = $report;
            foreach ($report['rows'] as $row) {
                if ($type === 'pipeline') {
                    $query = $this->within('leads', 'created_at', $range)->whereIn('pipeline_stage_id', DB::table('pipeline_stages')->where('name', $row[0])->pluck('id'));
                    $check('pipeline.count.'.$row[0], $query->count(), $row[1]);
                    $check('pipeline.value.'.$row[0], $this->money($query->sum('estimated_value')), $row[2]);

                    continue;
                }
                $date = CarbonImmutable::parse($row[0], 'Asia/Manila');
                $from = $date->utc();
                $to = ($type === 'sales' ? $date->addMonth() : $date->addDay())->utc();
                if ($type === 'sales') {
                    $query = $this->within('sales', 'closed_at', $range)->where('closed_at', '>=', $from)->where('closed_at', '<', $to)->where('currency', $currency)->where('status', 'completed');
                    $check('sales.'.$row[0], $this->money($query->sum('amount')), $row[1]);
                } elseif ($type === 'customers') {
                    $check('customers.new.'.$row[0], $this->within('customers', 'created_at', $range)->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(), $row[1]);
                    $check('customers.total.'.$row[0], DB::table('customers')->whereNull('deleted_at')->where('created_at', '<', $to)->count(), $row[2]);
                } else {
                    $check('tasks.created.'.$row[0], $this->within('tasks', 'created_at', $range)->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(), $row[1]);
                    $check('tasks.completed.'.$row[0], $this->within('tasks', 'completed_at', $range)->where('completed_at', '>=', $from)->where('completed_at', '<', $to)->where('status', 'completed')->count(), $row[2]);
                }
            }
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            foreach (Schema::getTableListing(DB::connection()->getDatabaseName(), false) as $table) {
                foreach (Schema::getForeignKeys($table) as $foreign) {
                    $query = DB::table($table.' as child')->leftJoin($foreign['foreign_table'].' as parent', function ($join) use ($foreign): void {
                        foreach ($foreign['columns'] as $index => $column) {
                            $join->on('child.'.$column, '=', 'parent.'.$foreign['foreign_columns'][$index]);
                        }
                    })->whereNotNull('child.'.$foreign['columns'][0])->whereNull('parent.'.$foreign['foreign_columns'][0]);
                    $check('foreign_key.'.$table.'.'.$foreign['name'], 0, $query->count());
                }
            }
        }
        $manifest = json_decode(Storage::disk('local')->get(Qa2026ScenarioSeeder::MANIFEST), true, flags: JSON_THROW_ON_ERROR);
        $check('automation.succeeded', 50, DB::table('automation_logs')->whereIn('id', $manifest['automation_logs'])->where('execution_status', 'succeeded')->count());
        $check('automation.actual_tasks', 50, DB::table('tasks')->whereIn('automation_log_id', $manifest['automation_logs'])->count());
        $check('qa_workflow_disabled', false, (bool) DB::table('automation_workflows')->where('id', $manifest['workflow'])->value('is_active'));
        foreach (Appointment::whereIn('id', $manifest['appointments'])->get() as $appointment) {
            $check('availability.'.$appointment->id, true, app(AvailabilityService::class)->fits(app(AvailabilityService::class)->currentSchedule($appointment->assigned_to), $appointment->start_at, $appointment->end_at));
        }
        $check('active_appointment_overlaps', 0, DB::table('appointments as a')->join('appointments as b', 'a.assigned_to', '=', 'b.assigned_to')->whereColumn('a.id', '<', 'b.id')->whereNull('a.deleted_at')->whereNull('b.deleted_at')->whereIn('a.status', ['scheduled', 'confirmed'])->whereIn('b.status', ['scheduled', 'confirmed'])->whereColumn('a.start_at', '<', 'b.end_at')->whereColumn('a.end_at', '>', 'b.start_at')->count());
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return ['reference' => Qa2026ScenarioSeeder::REFERENCE, 'counts' => $counts, 'summary' => $summary, 'revenue_periods' => $periods, 'reports' => $reports, 'check_count' => count($checks), 'checks' => $checks, 'failures' => array_values(array_filter($checks, fn (array $check): bool => ! $check['passed']))];
    }

    private function within(string $table, string $column, DateRange $range): Builder
    {
        $query = DB::table($table)->where($column, '>=', $range->start)->where($column, '<', $range->end);
        if ($table !== 'sales') {
            $query->whereNull($table.'.deleted_at');
        }

        return $query;
    }

    private function leadCount(string $slug, DateRange $range): int
    {
        return $this->within('leads', 'created_at', $range)->whereIn('pipeline_stage_id', DB::table('pipeline_stages')->where('slug', $slug)->pluck('id'))->count();
    }

    private function money(mixed $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(2);
    }
}
