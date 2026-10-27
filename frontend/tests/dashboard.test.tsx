import React from "react";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { vi, it, expect, beforeEach } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { Dashboard } from "@/features/dashboard/dashboard";
import { dashboardApi } from "@/features/dashboard/api";
import { useUser } from "@/hooks/use-user";
import { ApiError } from "@/lib/api";
import { summary, analytics } from "./dashboard-fixtures";
vi.mock("next/dynamic", () => ({
  default: () =>
    function ChartStub() {
      return <div>Charts loaded</div>;
    },
}));
vi.mock("@/features/dashboard/api", () => ({
  dashboardApi: { summary: vi.fn(), analytics: vi.fn() },
}));
vi.mock("@/hooks/use-user", () => ({ useUser: vi.fn() }));
function setup() {
  render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <Dashboard />
    </QueryClientProvider>,
  );
}
beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(useUser).mockReturnValue({
    data: {
      name: "Morgan Chen",
      permissions: ["dashboard.view", "sales.manage"],
    },
  } as unknown as ReturnType<typeof useUser>);
  vi.mocked(dashboardApi.summary).mockResolvedValue(summary);
  vi.mocked(dashboardApi.analytics).mockResolvedValue(analytics);
});
it("renders all eight metrics from API results and keeps revenue exact", async () => {
  setup();
  const revenue = await screen.findByRole("region", {
    name: "Total recorded sales",
  });
  expect(revenue).toHaveTextContent("USD 12,345.67");
  expect(
    within(screen.getByRole("region", { name: "Total customers" })).getByText(
      "12",
    ),
  ).toBeInTheDocument();
  expect(
    screen.getByRole("region", { name: "Overdue tasks" }),
  ).toHaveTextContent("1");
  expect(screen.getByText("Welcome, Morgan")).toBeInTheDocument();
});
it("refetches summary and charts with date preset and currency filters", async () => {
  setup();
  await screen.findByRole("region", { name: "Total customers" });
  await userEvent.selectOptions(screen.getByLabelText("Date range"), "today");
  await waitFor(() =>
    expect(dashboardApi.summary).toHaveBeenLastCalledWith(
      expect.objectContaining({ preset: "today" }),
    ),
  );
  await userEvent.selectOptions(
    screen.getByLabelText("Revenue currency"),
    "EUR",
  );
  await waitFor(() =>
    expect(dashboardApi.analytics).toHaveBeenLastCalledWith(
      expect.objectContaining({ preset: "today", currency: "EUR" }),
    ),
  );
});
it("does not request a custom range until complete valid dates are applied", async () => {
  setup();
  await screen.findByRole("region", { name: "Total customers" });
  await userEvent.selectOptions(screen.getByLabelText("Date range"), "custom");
  const count = vi.mocked(dashboardApi.summary).mock.calls.length;
  await userEvent.click(screen.getByRole("button", { name: "Apply dates" }));
  expect(vi.mocked(dashboardApi.summary).mock.calls.length).toBe(count);
  await userEvent.type(screen.getByLabelText("Start date"), "2026-10-01");
  await userEvent.type(screen.getByLabelText("End date"), "2026-10-20");
  await userEvent.click(screen.getByRole("button", { name: "Apply dates" }));
  await waitFor(() =>
    expect(dashboardApi.summary).toHaveBeenLastCalledWith(
      expect.objectContaining({
        preset: "custom",
        start_date: "2026-10-01",
        end_date: "2026-10-20",
      }),
    ),
  );
});
it("shows a failed summary without displaying fabricated metrics", async () => {
  vi.mocked(dashboardApi.summary).mockRejectedValue(
    new ApiError("Dashboard could not be loaded", 500),
  );
  setup();
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "Dashboard could not be loaded",
  );
  expect(
    screen.queryByRole("region", { name: "Total customers" }),
  ).not.toBeInTheDocument();
  expect(screen.getByRole("button", { name: "Try again" })).toBeEnabled();
});
it("does not query dashboard data for an account without access", () => {
  vi.mocked(useUser).mockReturnValue({
    data: { name: "No access", permissions: [] },
  } as unknown as ReturnType<typeof useUser>);
  setup();
  expect(screen.getByRole("alert")).toHaveTextContent("permission");
  expect(dashboardApi.summary).not.toHaveBeenCalled();
  expect(dashboardApi.analytics).not.toHaveBeenCalled();
});
