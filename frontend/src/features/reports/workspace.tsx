"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Download, RefreshCw } from "lucide-react";
import { toast } from "sonner";
import { api, downloadCsv } from "@/lib/api";
import { useUser } from "@/hooks/use-user";
import { Button } from "@/components/ui/button";
import { Field, controlClass } from "@/components/forms/field";
import { CrmState } from "@/features/crm/components";
import { queryString } from "@/features/crm/api";
import { DateFilters } from "@/features/dashboard/date-filters";
import type { DashboardFilters, FilterMetadata } from "@/types/dashboard";

type Report = {
  report: string;
  title: string;
  description: string;
  filters: FilterMetadata;
  columns: string[];
  rows: (string | number)[][];
};

export function ReportsWorkspace() {
  const { data: user } = useUser();
  const [report, setReport] = useState("sales");
  const [exporting, setExporting] = useState(false);
  const [filters, setFilters] = useState<DashboardFilters>(() => ({
    preset: "last_30_days",
    currency: "USD",
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC",
  }));
  const permitted = !!user?.permissions.some((permission) =>
    ["reports.team", "reports.all"].includes(permission),
  );
  const ready =
    filters.preset !== "custom" || !!(filters.start_date && filters.end_date);
  const query = useQuery({
    queryKey: ["reports", report, filters],
    queryFn: () =>
      api<Report>(`/reports?${queryString({ ...filters, report })}`),
    enabled: permitted && ready,
  });
  const data = query.data;

  async function exportReport() {
    if (!data) return;
    setExporting(true);
    try {
      const { start_date, end_date, timezone, currency } = data.filters;
      await downloadCsv(
        `/reports/export?${queryString({ preset: "custom", start_date, end_date, timezone, currency, report: data.report })}`,
        `smartbiz-${data.report}-${start_date}-${end_date}.csv`,
      );
      toast.success("Report downloaded");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Export failed");
    } finally {
      setExporting(false);
    }
  }

  if (!user) return null;
  if (!permitted)
    return <p role="alert">You do not have permission to view reports.</p>;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Reports</h1>
          <p className="mt-2 text-sm text-muted-foreground">
            Review business results and download them for your records.
          </p>
        </div>
        <div className="flex gap-2">
          <Button
            variant="outline"
            disabled={!ready || query.isFetching}
            onClick={() => query.refetch()}
          >
            <RefreshCw className="size-4" />
            Refresh
          </Button>
          <Button
            disabled={
              !ready || !data || !!query.error || query.isFetching || exporting
            }
            onClick={exportReport}
          >
            <Download className="size-4" />
            {exporting ? "Exporting..." : "Download CSV"}
          </Button>
        </div>
      </div>
      <Field label="Report">
        <select
          className={controlClass}
          value={report}
          onChange={(event) => setReport(event.target.value)}
        >
          <option value="sales">Monthly sales</option>
          <option value="customers">Customer growth</option>
          <option value="tasks">Task completion</option>
          <option value="pipeline">Lead pipeline</option>
        </select>
      </Field>
      <DateFilters
        value={filters}
        onChange={setFilters}
        currencies={data?.filters.available_currencies ?? ["USD", "EUR", "GBP"]}
      />
      {!ready ? (
        <p className="text-sm text-muted-foreground">
          Choose a start and end date, then apply the range.
        </p>
      ) : (
        <>
          <CrmState
            pending={query.isPending}
            error={query.error}
            retry={() => query.refetch()}
          />
          {data && !query.error && (
            <section className="overflow-hidden rounded-xl border border-border bg-card">
              <div className="space-y-2 border-b border-border p-5">
                <h2 className="text-lg font-semibold">{data.title}</h2>
                <p className="text-sm text-muted-foreground">
                  {data.description}
                </p>
                <p className="text-xs text-muted-foreground">
                  {data.filters.start_date} to {data.filters.end_date} ·{" "}
                  {data.filters.timezone} · {data.filters.currency}
                </p>
                <p className="text-xs text-muted-foreground">
                  Generated{" "}
                  {new Date(data.filters.generated_at).toLocaleString()}.
                  Downloads regenerate current results using these dates.
                </p>
              </div>
              <div className="max-h-[36rem] overflow-auto">
                <table className="w-full text-left text-sm">
                  <caption className="sr-only">
                    {data.title} for {data.filters.start_date} through{" "}
                    {data.filters.end_date}
                  </caption>
                  <thead className="sticky top-0 bg-muted">
                    <tr>
                      {data.columns.map((column) => (
                        <th
                          key={column}
                          scope="col"
                          className="px-5 py-3 font-medium"
                        >
                          {column}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {data.rows.map((row, index) => (
                      <tr key={index} className="border-t border-border">
                        {row.map((cell, column) => (
                          <td key={column} className="px-5 py-3 tabular-nums">
                            {cell}
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          )}
        </>
      )}
    </div>
  );
}
