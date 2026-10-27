import { api } from "@/lib/api";
import { queryString } from "@/features/crm/api";
import type {
  DashboardFilters,
  DashboardSummary,
  DashboardAnalytics,
} from "@/types/dashboard";
export const dashboardApi = {
  summary: (filters: DashboardFilters) =>
    api<DashboardSummary>(`/dashboard/summary?${queryString(filters)}`),
  analytics: (filters: DashboardFilters) =>
    api<DashboardAnalytics>(`/dashboard/analytics?${queryString(filters)}`),
};
