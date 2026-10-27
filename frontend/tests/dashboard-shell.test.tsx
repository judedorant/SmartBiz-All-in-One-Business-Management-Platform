vi.mock("@/features/tasks/notification-center", () => ({
  NotificationCenter: () => <span data-testid="notification-center" />,
}));
import React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { vi, it, expect, beforeEach } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { DashboardShell } from "@/components/layout/dashboard-shell";
import Dashboard from "@/app/(dashboard)/dashboard/page";
import { useUser } from "@/hooks/use-user";
import { authApi } from "@/features/auth/api";
import { ApiError } from "@/lib/api";
const { replace } = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock("next/navigation", () => ({
  usePathname: () => "/dashboard",
  useRouter: () => ({ replace }),
}));
vi.mock("next-themes", () => ({
  useTheme: () => ({ resolvedTheme: "light", setTheme: vi.fn() }),
}));
vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/hooks/use-user", () => ({ useUser: vi.fn() }));
vi.mock("@/features/auth/api", () => ({ authApi: { logout: vi.fn() } }));
vi.mock("@/features/dashboard/api", () => ({
  dashboardApi: {
    summary: vi.fn().mockResolvedValue({
      filters: {
        start_date: "2026-10-01",
        end_date: "2026-10-26",
        timezone: "UTC",
        currency: "USD",
        available_currencies: ["USD"],
      },
      metrics: {
        total_customers: 0,
        active_leads: 0,
        qualified_leads: 0,
        won_deals: 0,
        total_recorded_sales: "0.00",
        pending_tasks: 0,
        upcoming_appointments: 0,
        overdue_tasks: 0,
      },
      lead_conversion: { won_rate: 0 },
    }),
    analytics: vi.fn().mockResolvedValue({}),
  },
}));
vi.mock("next/dynamic", () => ({
  default: () =>
    function ChartStub() {
      return <div>Charts</div>;
    },
}));
const profile = {
  id: 2,
  name: "Morgan Chen",
  email: "morgan@example.test",
  status: "active" as const,
  last_login_at: "2026-10-26T12:00:00Z",
  roles: ["Manager"],
  permissions: ["dashboard.view", "tasks.assign"],
};
function userState(overrides: Record<string, unknown> = {}) {
  vi.mocked(useUser).mockReturnValue({
    data: profile,
    isPending: false,
    error: null,
    refetch: vi.fn(),
    ...overrides,
  } as unknown as ReturnType<typeof useUser>);
}
function setup(children: React.ReactNode = <p>Protected content</p>) {
  const client = new QueryClient();
  render(
    <QueryClientProvider client={client}>
      <DashboardShell>{children}</DashboardShell>
    </QueryClientProvider>,
  );
  return client;
}
beforeEach(() => {
  vi.clearAllMocks();
  userState();
});
it("keeps protected content hidden until the session has been checked", () => {
  userState({ data: undefined, isPending: true });
  setup();
  expect(screen.getByRole("status")).toHaveTextContent(
    "Loading your workspace",
  );
  expect(screen.queryByText("Protected content")).not.toBeInTheDocument();
});
it("redirects unauthenticated users to login without rendering protected content", async () => {
  userState({ data: undefined, error: new ApiError("Unauthenticated.", 401) });
  setup();
  await waitFor(() => expect(replace).toHaveBeenCalledWith("/login"));
  expect(screen.queryByText("Protected content")).not.toBeInTheDocument();
});
it("renders authenticated profile and permissions from the API data", () => {
  setup(<Dashboard />);
  expect(screen.getByText("Welcome, Morgan")).toBeInTheDocument();
  expect(screen.getByText("Morgan Chen")).toBeInTheDocument();
  expect(screen.getByText("tasks assign")).toBeInTheDocument();
  expect(screen.getByText("Workspace ready")).toBeInTheDocument();
  expect(screen.getAllByTestId("notification-center")).toHaveLength(1);
  expect(screen.getByRole("link", { name: "Tasks" })).toHaveAttribute(
    "href",
    "/tasks",
  );
});
it("calls logout and removes private query data on sign out", async () => {
  vi.mocked(authApi.logout).mockResolvedValue(null);
  const client = setup();
  client.setQueryData(["private-records"], ["private"]);
  await userEvent.click(screen.getByRole("button", { name: "Sign out" }));
  await waitFor(() => expect(replace).toHaveBeenCalledWith("/login"));
  expect(authApi.logout).toHaveBeenCalledOnce();
  expect(client.getQueryData(["private-records"])).toBeUndefined();
});
it("keeps the session view and reports a logout network error", async () => {
  vi.mocked(authApi.logout).mockRejectedValue(
    new ApiError("API unavailable", 0),
  );
  setup();
  await userEvent.click(screen.getByRole("button", { name: "Sign out" }));
  await waitFor(() =>
    expect(screen.getByRole("button", { name: "Sign out" })).toBeEnabled(),
  );
  expect(replace).not.toHaveBeenCalled();
  expect(screen.getByText("Protected content")).toBeInTheDocument();
});
