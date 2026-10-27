export type DashboardFilters = {
  preset: "today" | "last_7_days" | "last_30_days" | "this_month" | "custom";
  timezone: string;
  currency: string;
  start_date?: string;
  end_date?: string;
};
export type FilterMetadata = DashboardFilters & {
  start_date: string;
  end_date: string;
  available_currencies: string[];
  generated_at: string;
};
export type DashboardSummary = {
  filters: FilterMetadata;
  metrics: {
    total_customers: number;
    active_leads: number;
    qualified_leads: number;
    won_deals: number;
    total_recorded_sales: string;
    pending_tasks: number;
    upcoming_appointments: number;
    overdue_tasks: number;
  };
  lead_conversion: {
    total: number;
    won: number;
    lost: number;
    open: number;
    won_rate: number;
  };
};
export type DashboardAnalytics = {
  filters: FilterMetadata;
  monthly_sales: { month: string; amount: string }[];
  customer_growth: {
    date: string;
    new_customers: number;
    total_customers: number;
  }[];
  task_completion: { date: string; created: number; completed: number }[];
  lead_conversion: { name: string; count: number }[];
  pipeline: {
    id: number;
    name: string;
    slug: string;
    count: number;
    estimated_value: string;
  }[];
};
