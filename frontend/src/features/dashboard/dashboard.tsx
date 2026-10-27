"use client";
import { useState } from "react";
import Link from "next/link";
import dynamic from "next/dynamic";
import { useQuery } from "@tanstack/react-query";
import {
  Users,
  Target,
  BadgeCheck,
  Trophy,
  Wallet,
  CheckSquare,
  CalendarDays,
  AlarmClock,
  RefreshCw,
  ArrowUpRight,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { useUser } from "@/hooks/use-user";
import { CrmState } from "@/features/crm/components";
import { formatMoney } from "@/lib/money";
import { DateFilters } from "./date-filters";
import { dashboardApi } from "./api";
import type { DashboardFilters } from "@/types/dashboard";
const Charts = dynamic(() => import("@/components/charts/dashboard-charts"), {
  ssr: false,
  loading: () => (
    <div role="status" className="p-10 text-muted-foreground">
      Loading charts…
    </div>
  ),
});
export function Dashboard() {
  const { data: user } = useUser();
  const [filters, setFilters] = useState<DashboardFilters>(() => ({
    preset: "last_30_days",
    currency: "USD",
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC",
  }));
  const ready =
    filters.preset !== "custom" || !!(filters.start_date && filters.end_date);
  const permitted = !!user?.permissions.includes("dashboard.view");
  const summary = useQuery({
    queryKey: ["dashboard", "summary", filters],
    queryFn: () => dashboardApi.summary(filters),
    enabled: permitted && ready,
  });
  const analytics = useQuery({
    queryKey: ["dashboard", "analytics", filters],
    queryFn: () => dashboardApi.analytics(filters),
    enabled: permitted && ready,
  });
  if (!user) return null;
  if (!permitted)
    return (
      <p role="alert">You do not have permission to view the dashboard.</p>
    );
  const data = summary.data;
  const m = data?.metrics;
  const metrics = m
    ? [
        {
          label: "Total customers",
          value: m.total_customers,
          icon: Users,
          hint: "Created in range",
        },
        {
          label: "Active leads",
          value: m.active_leads,
          icon: Target,
          hint: "Open leads created in range",
        },
        {
          label: "Qualified leads",
          value: m.qualified_leads,
          icon: BadgeCheck,
          hint: "Current stage · cohort",
        },
        {
          label: "Won deals",
          value: m.won_deals,
          icon: Trophy,
          hint: `${data.lead_conversion.won_rate}% won rate · cohort`,
        },
        {
          label: "Total recorded sales",
          value: formatMoney(m.total_recorded_sales, data.filters.currency),
          icon: Wallet,
          hint: "Completed sales closed in range",
        },
        {
          label: "Pending tasks",
          value: m.pending_tasks,
          icon: CheckSquare,
          hint: "Pending / in progress · cohort",
        },
        {
          label: "Upcoming appointments",
          value: m.upcoming_appointments,
          icon: CalendarDays,
          hint: "Start in range and not in the past",
        },
        {
          label: "Overdue tasks",
          value: m.overdue_tasks,
          icon: AlarmClock,
          hint: "Unfinished, past due · cohort",
        },
      ]
    : [];
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="mb-2 text-xs font-semibold uppercase tracking-widest text-primary">
            Your business, in focus
          </p>
          <h1 className="text-3xl font-bold tracking-tight">
            Welcome, {user.name.split(" ")[0]}
          </h1>
          <span className="mt-2 inline-block text-xs text-emerald-600">
            Workspace ready
          </span>
          <p className="mt-2 text-sm text-muted-foreground">
            A clear view of your customers, opportunities, and recorded results.
          </p>
        </div>
        <div className="flex gap-2">
          <Button
            variant="outline"
            disabled={!ready || summary.isFetching || analytics.isFetching}
            onClick={() => {
              summary.refetch();
              analytics.refetch();
            }}
          >
            <RefreshCw
              className={`size-4 ${summary.isFetching || analytics.isFetching ? "animate-spin" : ""}`}
            />
            Refresh
          </Button>
          <Button asChild>
            <Link href="/sales">
              Sales ledger
              <ArrowUpRight className="size-4" />
            </Link>
          </Button>
        </div>
      </div>
      <DateFilters
        value={filters}
        onChange={setFilters}
        currencies={
          data?.filters.available_currencies ??
          analytics.data?.filters.available_currencies ??
          []
        }
      />
      {!ready ? (
        <p className="rounded-lg border border-border bg-card p-6 text-sm text-muted-foreground">
          Choose a start and end date, then apply the range.
        </p>
      ) : (
        <>
          <CrmState
            pending={summary.isPending}
            error={summary.error}
            retry={() => summary.refetch()}
          />
          {m && !summary.error && (
            <>
              <p className="text-xs text-muted-foreground">
                {data.filters.start_date} — {data.filters.end_date} ·{" "}
                {data.filters.timezone} · Your permitted records only
              </p>
              <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {metrics.map(({ label, value, icon: Icon, hint }) => (
                  <section
                    key={label}
                    aria-label={label}
                    className="rounded-xl border border-border bg-card p-5 shadow-sm"
                  >
                    <div className="mb-4 flex items-center justify-between gap-2">
                      <h2 className="text-xs font-medium text-muted-foreground">
                        {label}
                      </h2>
                      <span className="rounded-lg bg-primary/10 p-2 text-primary">
                        <Icon className="size-4" />
                      </span>
                    </div>
                    <p className="break-words text-2xl font-bold tracking-tight tabular-nums">
                      {value}
                    </p>
                    <p className="mt-2 text-[11px] text-muted-foreground">
                      {hint}
                    </p>
                  </section>
                ))}
              </div>
            </>
          )}
          <CrmState
            pending={analytics.isPending}
            error={analytics.error}
            retry={() => analytics.refetch()}
          />
          {analytics.data && !analytics.error && (
            <Charts data={analytics.data} />
          )}
          <p className="text-xs leading-5 text-muted-foreground">
            Customer, lead, and task counts use creation dates and current
            statuses. Revenue uses close dates; appointment counts use start
            times. Manage tasks and comments from the Tasks workspace.
            Scheduling is available from Appointments. Empty periods reflect
            database results.
          </p>
        </>
      )}
      <details className="rounded-xl border border-border bg-card p-4">
        <summary className="cursor-pointer text-sm font-medium">
          Your workspace access
        </summary>
        <div className="mt-4 flex flex-wrap gap-2">
          {user.permissions.map((permission) => (
            <span
              key={permission}
              className="rounded bg-muted px-3 py-2 text-xs capitalize"
            >
              {permission.replaceAll(".", " ").replaceAll("_", " ")}
            </span>
          ))}
        </div>
      </details>
    </div>
  );
}
