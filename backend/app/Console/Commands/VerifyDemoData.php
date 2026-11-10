<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\DashboardService;
use App\Services\DateRange;
use App\Services\ReportService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\SmartBizDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class VerifyDemoData extends Command
{
    protected $signature = 'smartbiz:qa-data {--database=smartbiz_demo}';

    protected $description = 'Read-only reconciliation of demo relationships, dashboard and every existing report cell against independent SQL';

    private array $checks = [];

    public function handle(DashboardService $dashboard, ReportService $reports, AvailabilityService $availability): int
    {
        $database = (string) $this->option('database');
        $source = config('database.connections.'.config('database.default'));
        if (! app()->environment(['local', 'testing']) || ($source['driver'] ?? '') !== 'mysql' || ! in_array($source['host'], ['localhost', '127.0.0.1', '::1'], true) || ! preg_match('/^smartbiz_demo(?:_[a-z0-9]+)?$/D', $database) || ! empty($source['url'])) {
            $this->error('QA reconciliation requires a dedicated local MySQL demo database.');

            return self::FAILURE;
        }
        config(['database.connections.demo' => [...$source, 'database' => $database], 'database.default' => 'demo', 'cache.default' => 'array']);
        DB::purge('demo');
        $manifest = json_decode(Storage::disk('local')->get('demo-accounts-'.$database.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $range = DateRange::from(['preset' => 'custom', 'start_date' => $manifest['start_date'], 'end_date' => $manifest['reference_date'], 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
        $admin = User::role('Admin')->firstOrFail();
        $counts = [];
        foreach (SmartBizDemoSeeder::COUNTS as $table => $count) {
            $counts[$table] = DB::table($table)->count();
            $this->check('count.'.$table, $count, $counts[$table]);
        }
        foreach (['Admin' => 1, 'Manager' => 3, 'Staff' => 10] as $role => $count) {
            $this->check('roles.'.$role, $count, User::role($role)->count());
        }
        $tables = Schema::getTableListing($database, false);
        foreach ($tables as $table) {
            foreach (Schema::getForeignKeys($table) as $foreign) {
                $query = DB::table($table.' as child')->leftJoin($foreign['foreign_table'].' as parent', function ($join) use ($foreign): void {
                    foreach ($foreign['columns'] as $index => $column) {
                        $join->on('child.'.$column, '=', 'parent.'.$foreign['foreign_columns'][$index]);
                    }
                })->whereNotNull('child.'.$foreign['columns'][0])->whereNull('parent.'.$foreign['foreign_columns'][0]);
                $this->check('foreign_key.'.$table.'.'.$foreign['name'], 0, $query->count());
            }
        }
        $this->check('customer_assignments', 500, DB::table('customer_user')->distinct()->count('customer_id'));
        $this->check('sales.php', 200, DB::table('sales')->where('currency', 'PHP')->whereBetween('amount', [1000, 250000])->count());
        $this->check('sales.valid_won_links', 200, DB::table('sales')->join('leads', 'sales.lead_id', '=', 'leads.id')->join('pipeline_stages', 'leads.pipeline_stage_id', '=', 'pipeline_stages.id')->whereColumn('sales.customer_id', 'leads.customer_id')->where('pipeline_stages.slug', 'won')->count());
        $this->check('tasks.completion_dates', 0, DB::table('tasks')->where(fn (Builder $query) => $query->where('status', 'completed')->whereNull('completed_at'))->orWhere(fn (Builder $query) => $query->where('status', '!=', 'completed')->whereNotNull('completed_at'))->count());
        foreach (Appointment::all() as $appointment) {
            $this->check('availability.'.$appointment->id, true, $availability->fits($availability->currentSchedule($appointment->assigned_to), $appointment->start_at, $appointment->end_at));
        }
        $this->check('appointments.active_overlaps', 0, DB::table('appointments as a')->join('appointments as b', 'a.assigned_to', '=', 'b.assigned_to')->whereColumn('a.id', '<', 'b.id')->whereIn('a.status', ['scheduled', 'confirmed'])->whereIn('b.status', ['scheduled', 'confirmed'])->whereColumn('a.start_at', '<', 'b.end_at')->whereColumn('a.end_at', '>', 'b.start_at')->count());
        $summary = $dashboard->summary($admin, $range);
        $expected = [
            'total_customers' => $this->within('customers', 'created_at', $range)->count(),
            'active_leads' => $this->within('leads', 'created_at', $range)->whereIn('pipeline_stage_id', DB::table('pipeline_stages')->whereNotIn('slug', ['won', 'lost'])->pluck('id'))->count(),
            'qualified_leads' => $this->within('leads', 'created_at', $range)->whereIn('pipeline_stage_id', DB::table('pipeline_stages')->where('slug', 'qualified')->pluck('id'))->count(),
            'won_deals' => $this->within('leads', 'created_at', $range)->whereIn('pipeline_stage_id', DB::table('pipeline_stages')->where('slug', 'won')->pluck('id'))->count(),
            'total_recorded_sales' => $this->money($this->within('sales', 'closed_at', $range)->where('currency', 'PHP')->where('status', 'completed')->sum('amount')),
            'pending_tasks' => $this->within('tasks', 'created_at', $range)->whereIn('status', ['pending', 'in_progress'])->count(),
            'overdue_tasks' => $this->within('tasks', 'created_at', $range)->whereIn('status', ['pending', 'in_progress'])->where('due_date', '<', $range->now)->count(),
            'upcoming_appointments' => $this->within('appointments', 'start_at', $range)->whereIn('status', ['scheduled', 'confirmed'])->where('start_at', '>=', $range->now)->count(),
        ];
        foreach ($expected as $metric => $value) {
            $this->check('dashboard.'.$metric, $value, $summary['metrics'][$metric]);
        }
        $lost = $this->within('leads', 'created_at', $range)->whereIn('pipeline_stage_id', DB::table('pipeline_stages')->where('slug', 'lost')->pluck('id'))->count();
        $total = $this->within('leads', 'created_at', $range)->count();
        $this->check('dashboard.lost', $lost, $summary['lead_conversion']['lost']);
        $this->check('dashboard.won_rate', $total ? round($expected['won_deals'] / $total * 100, 2) : 0.0, (float) $summary['lead_conversion']['won_rate']);
        foreach (['sales', 'customers', 'tasks', 'pipeline'] as $type) {
            $report = $reports->generate($admin, $range, $type);
            foreach ($report['rows'] as $row) {
                $date = $type === 'pipeline' ? null : CarbonImmutable::parse($row[0], 'Asia/Manila');
                $from = $date?->utc();
                $to = $type === 'sales' ? $date->addMonth()->utc() : $date?->addDay()->utc();
                if ($type === 'sales') {
                    $value = $this->within('sales', 'closed_at', $range)->where('closed_at', '>=', $from)->where('closed_at', '<', $to)->where('currency', 'PHP')->where('status', 'completed')->sum('amount');
                    $this->check('report.sales.'.$row[0], $this->money($value), $row[1]);
                } elseif ($type === 'customers') {
                    $this->check('report.customers.new.'.$row[0], $this->within('customers', 'created_at', $range)->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(), $row[1]);
                    $this->check('report.customers.total.'.$row[0], DB::table('customers')->whereNull('deleted_at')->where('created_at', '<', $to)->count(), $row[2]);
                } elseif ($type === 'tasks') {
                    $this->check('report.tasks.created.'.$row[0], $this->within('tasks', 'created_at', $range)->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(), $row[1]);
                    $this->check('report.tasks.completed.'.$row[0], $this->within('tasks', 'completed_at', $range)->where('completed_at', '>=', $from)->where('completed_at', '<', $to)->where('status', 'completed')->count(), $row[2]);
                } else {
                    $query = $this->within('leads', 'created_at', $range)->whereIn('pipeline_stage_id', DB::table('pipeline_stages')->where('name', $row[0])->pluck('id'));
                    $this->check('report.pipeline.count.'.$row[0], $query->count(), $row[1]);
                    $this->check('report.pipeline.value.'.$row[0], $this->money($query->sum('estimated_value')), $row[2]);
                }
            }
        }
        $future = DateRange::from(['preset' => 'custom', 'start_date' => $manifest['reference_date'], 'end_date' => CarbonImmutable::parse($manifest['reference_date'])->addDays(30)->toDateString(), 'timezone' => 'Asia/Manila', 'currency' => 'PHP']);
        $this->check('dashboard.future_appointments', $this->within('appointments', 'start_at', $future)->whereIn('status', ['scheduled', 'confirmed'])->where('start_at', '>=', $future->now)->count(), $dashboard->summary($admin, $future)['metrics']['upcoming_appointments']);
        $staff = User::role('Staff')->firstOrFail();
        $staffSummary = $dashboard->summary($staff, $range);
        $this->check('staff.pending_tasks', $this->within('tasks', 'created_at', $range)->where('assigned_to', $staff->id)->whereIn('status', ['pending', 'in_progress'])->count(), $staffSummary['metrics']['pending_tasks']);
        $this->check('staff.revenue', $this->money($this->within('sales', 'sales.closed_at', $range)->join('leads', 'sales.lead_id', '=', 'leads.id')->whereNull('leads.deleted_at')->where('leads.assigned_to', $staff->id)->where('sales.currency', 'PHP')->where('sales.status', 'completed')->sum('sales.amount')), $staffSummary['metrics']['total_recorded_sales']);
        $failures = array_values(array_filter($this->checks, fn (array $check): bool => ! $check['passed']));
        $result = ['database' => $database, 'generated_at' => now()->toISOString(), 'tables' => $tables, 'counts' => $counts, 'summary' => $summary, 'checks' => count($this->checks), 'failures' => $failures];
        Storage::disk('local')->put('qa-results-'.$database.'.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $failures ? self::FAILURE : self::SUCCESS;
    }

    private function within(string $table, string $column, DateRange $range): Builder
    {
        $query = DB::table($table)->where($column, '>=', $range->start)->where($column, '<', $range->end);
        if ($table !== 'sales') {
            $query->whereNull($table.'.deleted_at');
        }

        return $query;
    }

    private function check(string $name, mixed $expected, mixed $actual): void
    {
        $this->checks[] = ['name' => $name, 'passed' => $expected === $actual, 'expected' => $expected, 'actual' => $actual];
    }

    private function money(mixed $amount): string
    {
        return (string) BigDecimal::of((string) $amount)->toScale(2);
    }
}
