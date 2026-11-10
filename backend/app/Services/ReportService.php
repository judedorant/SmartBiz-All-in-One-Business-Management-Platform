<?php

namespace App\Services;

use App\Models\User;

class ReportService
{
    public function __construct(private DashboardService $dashboard) {}

    public function generate(User $user, DateRange $range, string $report): array
    {
        $analytics = $this->dashboard->analytics($user, $range);
        [$title, $columns, $rows, $description] = match ($report) {
            'sales' => ['Monthly sales', ['Month', 'Recorded sales'], array_map(fn (array $row): array => [$row['month'], $row['amount']], $analytics['monthly_sales']), 'Completed sales by close date. Voided sales are excluded. Amounts use only the selected currency.'],
            'customers' => ['Customer growth', ['Date', 'New customers', 'Total customers'], array_map(fn (array $row): array => [$row['date'], $row['new_customers'], $row['total_customers']], $analytics['customer_growth']), 'Customers by creation date. Running totals include customers created before the range. Archived customers are excluded.'],
            'tasks' => ['Task completion', ['Date', 'Created', 'Completed'], array_map(fn (array $row): array => [$row['date'], $row['created'], $row['completed']], $analytics['task_completion']), 'Tasks created and currently completed tasks by completion date. Completed tasks may have been created before the range. Archived tasks are excluded.'],
            'pipeline' => ['Lead pipeline', ['Stage', 'Lead count', 'Estimated value'], array_map(fn (array $row): array => [$row['name'], $row['count'], $row['estimated_value']], $analytics['pipeline']), 'Leads created in range grouped by current stage. Estimated values are pipeline estimates, not recorded revenue. Archived leads are excluded.'],
        };

        return ['report' => $report, 'title' => $title, 'description' => $description, 'filters' => $range->metadata(), 'columns' => $columns, 'rows' => $rows];
    }

    public function csvCell(string|int $value): string
    {
        $text = (string) $value;

        return preg_match('/^[\s\x00-\x1F]*[=+@-]/u', $text) ? "'".$text : $text;
    }
}
