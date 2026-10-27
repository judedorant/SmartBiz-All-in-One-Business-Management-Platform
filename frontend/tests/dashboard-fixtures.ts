import type { DashboardSummary, DashboardAnalytics } from "@/types/dashboard";
export const summary: DashboardSummary = {
  filters: {
    preset: "last_30_days",
    start_date: "2026-09-27",
    end_date: "2026-10-26",
    timezone: "UTC",
    currency: "USD",
    available_currencies: ["USD", "EUR", "GBP"],
    generated_at: "2026-10-26T12:00:00Z",
  },
  metrics: {
    total_customers: 12,
    active_leads: 7,
    qualified_leads: 3,
    won_deals: 2,
    total_recorded_sales: "12345.67",
    pending_tasks: 4,
    upcoming_appointments: 2,
    overdue_tasks: 1,
  },
  lead_conversion: { total: 10, won: 2, lost: 1, open: 7, won_rate: 20 },
};
export const analytics: DashboardAnalytics = {
  filters: summary.filters,
  monthly_sales: [
    { month: "2026-09", amount: "0.00" },
    { month: "2026-10", amount: "12345.67" },
  ],
  customer_growth: [
    { date: "2026-10-26", new_customers: 12, total_customers: 15 },
  ],
  task_completion: [{ date: "2026-10-26", created: 4, completed: 2 }],
  lead_conversion: [
    { name: "Open", count: 7 },
    { name: "Won", count: 2 },
    { name: "Lost", count: 1 },
  ],
  pipeline: [
    { id: 1, name: "New", slug: "new", count: 7, estimated_value: "7000.00" },
    { id: 2, name: "Won", slug: "won", count: 2, estimated_value: "12345.67" },
  ],
};
