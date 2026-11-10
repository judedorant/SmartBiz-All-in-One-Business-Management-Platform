import React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, expect, it, vi } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ReportsWorkspace } from "@/features/reports/workspace";
import { useUser } from "@/hooks/use-user";
import { api, downloadCsv } from "@/lib/api";
import { toast } from "sonner";

vi.mock("@/hooks/use-user", () => ({ useUser: vi.fn() }));
vi.mock("@/lib/api", async (original) => ({
  ...(await original<typeof import("@/lib/api")>()),
  api: vi.fn(),
  downloadCsv: vi.fn(),
}));
vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

function setup() {
  render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <ReportsWorkspace />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(useUser).mockReturnValue({
    data: { permissions: ["reports.team"] },
  } as unknown as ReturnType<typeof useUser>);
  vi.mocked(api).mockResolvedValue({
    report: "sales",
    title: "Monthly sales",
    description: "Completed sales by close date.",
    filters: {
      preset: "last_30_days",
      start_date: "2026-10-01",
      end_date: "2026-10-27",
      timezone: "UTC",
      currency: "USD",
      available_currencies: ["USD", "EUR"],
      generated_at: "2026-10-27T12:00:00Z",
    },
    columns: ["Month", "Recorded sales"],
    rows: [["2026-10", "123.45"]],
  });
  vi.mocked(downloadCsv).mockResolvedValue(undefined);
});

it("renders real report cells and exports with the displayed calendar dates", async () => {
  setup();
  expect(
    await screen.findByRole("cell", { name: "123.45" }),
  ).toBeInTheDocument();
  await userEvent.click(screen.getByRole("button", { name: "Download CSV" }));
  await waitFor(() => expect(downloadCsv).toHaveBeenCalled());
  const [path, filename] = vi.mocked(downloadCsv).mock.calls[0];
  const query = new URLSearchParams(path.split("?")[1]);
  expect(query.get("preset")).toBe("custom");
  expect(query.get("start_date")).toBe("2026-10-01");
  expect(query.get("end_date")).toBe("2026-10-27");
  expect(filename).toBe("smartbiz-sales-2026-10-01-2026-10-27.csv");
});

it("refetches for report selection and blocks incomplete custom dates", async () => {
  setup();
  await screen.findByRole("table");
  await userEvent.selectOptions(screen.getByLabelText("Report"), "tasks");
  await waitFor(() =>
    expect(api).toHaveBeenLastCalledWith(
      expect.stringContaining("report=tasks"),
    ),
  );
  await userEvent.selectOptions(screen.getByLabelText("Date range"), "custom");
  expect(screen.queryByRole("table")).not.toBeInTheDocument();
  expect(screen.getByRole("button", { name: "Download CSV" })).toBeDisabled();
});

it("does not fetch or offer downloads to staff without report permissions", () => {
  vi.mocked(useUser).mockReturnValue({
    data: { permissions: ["dashboard.view"] },
  } as unknown as ReturnType<typeof useUser>);
  setup();
  expect(screen.getByRole("alert")).toHaveTextContent("permission");
  expect(api).not.toHaveBeenCalled();
  expect(
    screen.queryByRole("button", { name: "Download CSV" }),
  ).not.toBeInTheDocument();
});

it("shows export errors and allows another download attempt", async () => {
  vi.mocked(downloadCsv).mockRejectedValue(new Error("Session expired"));
  setup();
  await screen.findByRole("table");
  await userEvent.click(screen.getByRole("button", { name: "Download CSV" }));
  await waitFor(() =>
    expect(toast.error).toHaveBeenCalledWith("Session expired"),
  );
  expect(screen.getByRole("button", { name: "Download CSV" })).toBeEnabled();
});
