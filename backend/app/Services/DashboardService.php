<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\Sale;
use App\Models\Task;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

class DashboardService
{
    public function summary(User $user, DateRange $range): array
    {
        $pipeline = $this->pipeline($user, $range);
        $conversion = $this->conversion($pipeline);
        $tasks = $this->within(Task::visibleTo($user), 'tasks.created_at', $range)->selectRaw("SUM(CASE WHEN status IN ('pending','in_progress') THEN 1 ELSE 0 END) AS pending")
            ->selectRaw("SUM(CASE WHEN status IN ('pending','in_progress') AND due_date < ? THEN 1 ELSE 0 END) AS overdue", [$range->now->format('Y-m-d H:i:s')])->first();

        return ['filters' => $range->metadata(), 'metrics' => [
            'total_customers' => $this->within(Customer::visibleTo($user), 'customers.created_at', $range)->count(),
            'active_leads' => $conversion['open'], 'qualified_leads' => collect($pipeline)->firstWhere('slug', 'qualified')['count'] ?? 0, 'won_deals' => $conversion['won'],
            'total_recorded_sales' => $this->money($this->sales($user, $range)->sum('amount')),
            'pending_tasks' => (int) $tasks->pending, 'overdue_tasks' => (int) $tasks->overdue,
            'upcoming_appointments' => $this->within(Appointment::visibleTo($user), 'appointments.start_at', $range)->where('start_at', '>=', $range->now)->whereIn('status', ['scheduled', 'confirmed'])->count(),
        ], 'lead_conversion' => $conversion];
    }

    public function analytics(User $user, DateRange $range): array
    {
        $monthly = $this->aggregate($this->sales($user, $range), 'sales.closed_at', $range, true, 'SUM(amount)');
        $new = $this->aggregate($this->within(Customer::visibleTo($user), 'customers.created_at', $range), 'customers.created_at', $range);
        $opening = Customer::visibleTo($user)->where('created_at', '<', $range->start)->count();
        $running = $opening;
        $growth = [];
        $created = $this->aggregate($this->within(Task::visibleTo($user), 'tasks.created_at', $range), 'tasks.created_at', $range);
        $completed = $this->aggregate($this->within(Task::visibleTo($user)->where('status', 'completed'), 'tasks.completed_at', $range), 'tasks.completed_at', $range);
        $tasks = [];
        foreach ($range->buckets() as $bucket) {
            $date = $bucket['label'];
            $count = (int) ($new[$date] ?? 0);
            $running += $count;
            $growth[] = ['date' => $date, 'new_customers' => $count, 'total_customers' => $running];
            $tasks[] = ['date' => $date, 'created' => (int) ($created[$date] ?? 0), 'completed' => (int) ($completed[$date] ?? 0)];
        }
        $sales = [];
        foreach ($range->buckets(true) as $bucket) {
            $sales[] = ['month' => $bucket['label'], 'amount' => $this->money($monthly[$bucket['label']] ?? '0')];
        }
        $pipeline = $this->pipeline($user, $range);
        $conversion = $this->conversion($pipeline);

        return ['filters' => $range->metadata(), 'monthly_sales' => $sales, 'customer_growth' => $growth, 'task_completion' => $tasks,
            'lead_conversion' => [['name' => 'Open', 'count' => $conversion['open']], ['name' => 'Won', 'count' => $conversion['won']], ['name' => 'Lost', 'count' => $conversion['lost']]], 'pipeline' => $pipeline];
    }

    private function pipeline(User $user, DateRange $range): array
    {
        $totals = $this->within(Lead::visibleTo($user), 'leads.created_at', $range)->selectRaw('pipeline_stage_id, COUNT(*) AS total, SUM(estimated_value) AS estimated')->groupBy('pipeline_stage_id')->get()->keyBy('pipeline_stage_id');

        return PipelineStage::orderBy('position')->get()->map(fn (PipelineStage $stage) => ['id' => $stage->id, 'name' => $stage->name, 'slug' => $stage->slug, 'count' => (int) ($totals->get($stage->id)?->total ?? 0), 'estimated_value' => $this->money($totals->get($stage->id)?->estimated ?? '0')])->all();
    }

    private function conversion(array $pipeline): array
    {
        $rows = collect($pipeline);
        $total = (int) $rows->sum('count');
        $won = (int) ($rows->firstWhere('slug', 'won')['count'] ?? 0);
        $lost = (int) ($rows->firstWhere('slug', 'lost')['count'] ?? 0);

        return ['total' => $total, 'won' => $won, 'lost' => $lost, 'open' => $total - $won - $lost, 'won_rate' => $total > 0 ? round($won / $total * 100, 2) : 0.0];
    }

    private function sales(User $user, DateRange $range): Builder
    {
        return $this->within(Sale::visibleTo($user)->where('status', 'completed')->where('currency', $range->currency), 'sales.closed_at', $range);
    }

    private function within(Builder $query, string $column, DateRange $range): Builder
    {
        return $query->where($column, '>=', $range->start)->where($column, '<', $range->end);
    }

    /** SQL receives bounded calendar bucket ends; no record lists or per-bucket queries are loaded. */
    private function aggregate(Builder $query, string $column, DateRange $range, bool $monthly = false, string $expression = 'COUNT(*)'): array
    {
        $case = 'CASE ';
        $bindings = [];
        foreach ($range->buckets($monthly) as $bucket) {
            $case .= "WHEN {$column} < ? THEN '".$bucket['label']."' ";
            $bindings[] = $bucket['end'];
        }$case .= 'END';

        return $query->selectRaw($case.' AS bucket', $bindings)->selectRaw($expression.' AS aggregate_value')->groupBy('bucket')->get()->pluck('aggregate_value', 'bucket')->all();
    }

    private function money(mixed $amount): string
    {
        return (string) BigDecimal::of((string) ($amount ?? '0'))->toScale(2, RoundingMode::HalfUp);
    }
}
