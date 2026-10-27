import React, { cloneElement, type ReactElement } from "react";
import { render, screen } from "@testing-library/react";
import { it, expect, vi } from "vitest";
import DashboardCharts from "@/components/charts/dashboard-charts";
import { analytics } from "./dashboard-fixtures";
import { formatMoney } from "@/lib/money";
import { customRangeSchema } from "@/features/dashboard/date-filters";
vi.mock("recharts", async (importOriginal) => {
  const actual = await importOriginal<typeof import("recharts")>();
  return {
    ...actual,
    ResponsiveContainer: ({ children }: { children: ReactElement }) => (
      <div>
        {cloneElement(
          children as ReactElement<{ width: number; height: number }>,
          { width: 600, height: 288 },
        )}
      </div>
    ),
  };
});
it("renders five real chart components and accessible data alternatives", () => {
  const { container } = render(<DashboardCharts data={analytics} />);
  expect(screen.getAllByRole("heading", { level: 2 })).toHaveLength(5);
  expect(
    screen.getByText("View monthly sales performance data"),
  ).toBeInTheDocument();
  expect(screen.getAllByRole("table", { hidden: true })).toHaveLength(5);
  expect(
    container.querySelectorAll("svg.recharts-surface").length,
  ).toBeGreaterThanOrEqual(5);
});
it("shows honest empty states for an empty reporting period", () => {
  render(
    <DashboardCharts
      data={{
        ...analytics,
        monthly_sales: [],
        customer_growth: [],
        task_completion: [],
        lead_conversion: [],
        pipeline: [],
      }}
    />,
  );
  expect(screen.getAllByText("No matching data for this period.")).toHaveLength(
    5,
  );
});
it("keeps cents exact beyond JavaScript's safe integer range", () => {
  expect(formatMoney("12345678901234567890.01", "USD")).toBe(
    "USD 12,345,678,901,234,567,890.01",
  );
  expect(formatMoney("0.10", "EUR")).toBe("EUR 0.10");
});
it("rejects reversed and oversized custom ranges", () => {
  expect(
    customRangeSchema.safeParse({
      start_date: "2026-10-20",
      end_date: "2026-10-01",
    }).success,
  ).toBe(false);
  expect(
    customRangeSchema.safeParse({
      start_date: "2020-01-01",
      end_date: "2026-10-01",
    }).success,
  ).toBe(false);
  expect(
    customRangeSchema.safeParse({
      start_date: "2026-10-20",
      end_date: "2026-10-20",
    }).success,
  ).toBe(true);
});
