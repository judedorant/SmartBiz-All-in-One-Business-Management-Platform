"use client";
import {
  ResponsiveContainer,
  BarChart,
  Bar,
  LineChart,
  Line,
  AreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
} from "recharts";
import type { DashboardAnalytics } from "@/types/dashboard";
import { formatMoney } from "@/lib/money";
function ChartCard({
  title,
  description,
  empty,
  children,
  table,
}: {
  title: string;
  description: string;
  empty: boolean;
  children: React.ReactNode;
  table: React.ReactNode;
}) {
  return (
    <section className="min-w-0 rounded-xl border border-border bg-card p-5 shadow-sm">
      <h2 className="font-semibold">{title}</h2>
      <p className="mb-5 mt-1 text-xs leading-5 text-muted-foreground">
        {description}
      </p>
      {empty ? (
        <div className="flex h-64 items-center justify-center rounded-lg border border-dashed border-border px-5 text-center text-sm text-muted-foreground">
          No matching data for this period.
        </div>
      ) : (
        <div className="h-72 min-w-0" aria-label={`${title} chart`}>
          {children}
        </div>
      )}
      <details className="mt-4 border-t border-border pt-3">
        <summary className="cursor-pointer text-xs font-medium text-primary">
          View {title.toLowerCase()} data
        </summary>
        <div className="mt-3 max-h-64 overflow-auto text-sm">{table}</div>
      </details>
    </section>
  );
}
function DataTable({
  headers,
  rows,
}: {
  headers: string[];
  rows: (string | number)[][];
}) {
  return (
    <table className="w-full text-left text-xs">
      <thead>
        <tr>
          {headers.map((h) => (
            <th
              scope="col"
              key={h}
              className="px-2 py-2 font-medium text-muted-foreground"
            >
              {h}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows.map((row, index) => (
          <tr key={index} className="border-t border-border">
            {row.map((value, cell) => (
              <td className="whitespace-nowrap px-2 py-2" key={cell}>
                {value}
              </td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  );
}
const axis = { fontSize: 11, fill: "var(--muted-foreground)" };
export default function DashboardCharts({
  data,
}: {
  data: DashboardAnalytics;
}) {
  const currency = data.filters.currency;
  const sales = data.monthly_sales.map((row) => ({
    ...row,
    plot_amount: Number(row.amount),
  }));
  return (
    <div className="grid items-start gap-6 xl:grid-cols-2">
      <ChartCard
        title="Monthly sales performance"
        description={`Completed sales by close date · ${currency}. Voided entries are excluded.`}
        empty={!sales.some((row) => row.plot_amount > 0)}
        table={
          <DataTable
            headers={["Month", "Recorded sales"]}
            rows={data.monthly_sales.map((row) => [
              row.month,
              formatMoney(row.amount, currency),
            ])}
          />
        }
      >
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={sales} accessibilityLayer>
            <CartesianGrid
              stroke="var(--border)"
              strokeDasharray="3 3"
              vertical={false}
            />
            <XAxis dataKey="month" tick={axis} />
            <YAxis tick={axis} width={65} />
            <Tooltip
              content={({ active, payload, label }) =>
                active && payload?.length ? (
                  <div className="rounded-lg border border-border bg-card p-3 text-sm shadow">
                    <p>{label}</p>
                    <p className="mt-1 font-semibold">
                      {formatMoney(
                        (payload[0].payload as { amount: string }).amount,
                        currency,
                      )}
                    </p>
                  </div>
                ) : null
              }
            />
            <Bar
              dataKey="plot_amount"
              name="Recorded sales"
              fill="#4361ee"
              radius={[5, 5, 0, 0]}
              isAnimationActive={false}
            />
          </BarChart>
        </ResponsiveContainer>
      </ChartCard>
      <ChartCard
        title="Customer growth"
        description="Cumulative currently visible customers by their creation date, including the opening balance."
        empty={!data.customer_growth.some((row) => row.total_customers > 0)}
        table={
          <DataTable
            headers={["Date", "New customers", "Total customers"]}
            rows={data.customer_growth.map((row) => [
              row.date,
              row.new_customers,
              row.total_customers,
            ])}
          />
        }
      >
        <ResponsiveContainer width="100%" height="100%">
          <AreaChart data={data.customer_growth} accessibilityLayer>
            <CartesianGrid
              stroke="var(--border)"
              strokeDasharray="3 3"
              vertical={false}
            />
            <XAxis dataKey="date" tick={axis} minTickGap={35} />
            <YAxis allowDecimals={false} tick={axis} />
            <Tooltip
              contentStyle={{
                background: "var(--card)",
                border: "1px solid var(--border)",
                borderRadius: 8,
              }}
              labelStyle={{ color: "var(--foreground)" }}
            />
            <Area
              type="monotone"
              dataKey="total_customers"
              name="Visible customers"
              stroke="#10b981"
              fill="#10b981"
              fillOpacity={0.12}
              isAnimationActive={false}
            />
          </AreaChart>
        </ResponsiveContainer>
      </ChartCard>
      <ChartCard
        title="Lead conversion statistics"
        description="Current outcomes for leads created in the selected period. Won rate is won ÷ all cohort leads."
        empty={!data.lead_conversion.some((row) => row.count > 0)}
        table={
          <DataTable
            headers={["Outcome", "Leads"]}
            rows={data.lead_conversion.map((row) => [row.name, row.count])}
          />
        }
      >
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={data.lead_conversion} accessibilityLayer>
            <CartesianGrid
              stroke="var(--border)"
              strokeDasharray="3 3"
              vertical={false}
            />
            <XAxis dataKey="name" tick={axis} />
            <YAxis tick={axis} allowDecimals={false} />
            <Tooltip
              contentStyle={{
                background: "var(--card)",
                border: "1px solid var(--border)",
                borderRadius: 8,
              }}
              labelStyle={{ color: "var(--foreground)" }}
            />
            <Bar
              dataKey="count"
              name="Leads"
              fill="#8b5cf6"
              radius={[5, 5, 0, 0]}
              isAnimationActive={false}
            />
          </BarChart>
        </ResponsiveContainer>
      </ChartCard>
      <ChartCard
        title="Task completion performance"
        description="Tasks created and completed on each local date. Completions may belong to tasks created before this period."
        empty={
          !data.task_completion.some((row) => row.created || row.completed)
        }
        table={
          <DataTable
            headers={["Date", "Created", "Completed"]}
            rows={data.task_completion.map((row) => [
              row.date,
              row.created,
              row.completed,
            ])}
          />
        }
      >
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={data.task_completion} accessibilityLayer>
            <CartesianGrid
              stroke="var(--border)"
              strokeDasharray="3 3"
              vertical={false}
            />
            <XAxis dataKey="date" tick={axis} minTickGap={35} />
            <YAxis tick={axis} allowDecimals={false} />
            <Tooltip
              contentStyle={{
                background: "var(--card)",
                border: "1px solid var(--border)",
                borderRadius: 8,
              }}
              labelStyle={{ color: "var(--foreground)" }}
            />
            <Legend />
            <Line
              type="monotone"
              dataKey="created"
              name="Created"
              stroke="#94a3b8"
              strokeWidth={2}
              dot={false}
              isAnimationActive={false}
            />
            <Line
              type="monotone"
              dataKey="completed"
              name="Completed"
              stroke="#4361ee"
              strokeWidth={2}
              dot={false}
              isAnimationActive={false}
            />
          </LineChart>
        </ResponsiveContainer>
      </ChartCard>
      <div className="xl:col-span-2">
        <ChartCard
          title="Sales pipeline distribution"
          description="Current stages of permitted leads created in the selected period. Estimates are CRM amounts, not recorded revenue."
          empty={!data.pipeline.some((row) => row.count)}
          table={
            <DataTable
              headers={["Stage", "Leads", "Estimated amount"]}
              rows={data.pipeline.map((row) => [
                row.name,
                row.count,
                row.estimated_value,
              ])}
            />
          }
        >
          <ResponsiveContainer width="100%" height="100%">
            <BarChart
              data={data.pipeline}
              layout="vertical"
              accessibilityLayer
              margin={{ left: 30 }}
            >
              <CartesianGrid
                stroke="var(--border)"
                strokeDasharray="3 3"
                horizontal={false}
              />
              <XAxis type="number" tick={axis} allowDecimals={false} />
              <YAxis type="category" dataKey="name" tick={axis} width={85} />
              <Tooltip
                contentStyle={{
                  background: "var(--card)",
                  border: "1px solid var(--border)",
                  borderRadius: 8,
                }}
                labelStyle={{ color: "var(--foreground)" }}
              />
              <Bar
                dataKey="count"
                name="Leads"
                fill="#4361ee"
                radius={[0, 4, 4, 0]}
                isAnimationActive={false}
              />
            </BarChart>
          </ResponsiveContainer>
        </ChartCard>
      </div>
    </div>
  );
}
