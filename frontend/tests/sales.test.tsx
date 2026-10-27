import React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { it, expect, vi, beforeEach, afterEach } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { SaleForm } from "@/features/dashboard/sale-form";
import { SalesLedger } from "@/features/dashboard/sales-ledger";
import { get, list, write } from "@/features/crm/api";
import { useUser } from "@/hooks/use-user";
import { ApiError } from "@/lib/api";
import { customer, page, stages } from "./crm-fixtures";
import type { Sale } from "@/types/sales";
const options = { currencies: ["USD", "EUR", "GBP"], default_currency: "USD" };
const sale: Sale = {
  id: 1,
  customer_id: 10,
  lead_id: null,
  amount: "123.45",
  currency: "USD",
  status: "completed",
  version: 1,
  closed_at: "2026-10-20T12:00:00Z",
  voided_at: null,
  void_reason: null,
  customer: { id: 10, first_name: "Ada", last_name: "Lovelace" },
  lead: null,
  recorder: { id: 2, name: "Taylor Reed" },
};
vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/hooks/use-user", () => ({ useUser: vi.fn() }));
vi.mock("@/features/crm/api", () => ({
  get: vi.fn(),
  list: vi.fn(),
  write: vi.fn(),
}));
function setup(children: React.ReactNode) {
  render(
    <QueryClientProvider
      client={
        new QueryClient({
          defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
          },
        })
      }
    >
      {children}
    </QueryClientProvider>,
  );
}
beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(useUser).mockReturnValue({
    data: { permissions: ["sales.manage"] },
  } as unknown as ReturnType<typeof useUser>);
  vi.mocked(get).mockImplementation(async (path) =>
    path === "/pipeline-stages" ? stages : options,
  );
  vi.mocked(list).mockImplementation(async (path) =>
    path === "/customers"
      ? page([customer])
      : path === "/sales"
        ? page([sale])
        : page([]),
  );
  vi.mocked(write).mockResolvedValue(sale);
});
afterEach(() => vi.restoreAllMocks());
it("validates a customer and positive amount before recording", async () => {
  setup(<SaleForm options={options} />);
  await userEvent.click(screen.getByRole("button", { name: "Record sale" }));
  expect(await screen.findByText("Select a customer.")).toBeInTheDocument();
  expect(write).not.toHaveBeenCalled();
});
it("records exact sale values and reuses the submission ID after a network failure", async () => {
  vi.mocked(write)
    .mockRejectedValueOnce(new ApiError("Network unavailable", 0))
    .mockResolvedValueOnce(sale);
  setup(<SaleForm options={options} />);
  await userEvent.click(
    await screen.findByRole("button", { name: /Ada Lovelace/ }),
  );
  await userEvent.type(screen.getByLabelText("Sale amount"), "123.45");
  await userEvent.click(screen.getByRole("button", { name: "Record sale" }));
  expect(await screen.findByText("Network unavailable")).toBeInTheDocument();
  await userEvent.click(screen.getByRole("button", { name: "Record sale" }));
  await waitFor(() => expect(write).toHaveBeenCalledTimes(2));
  const first = vi.mocked(write).mock.calls[0][2] as Record<string, unknown>;
  const second = vi.mocked(write).mock.calls[1][2] as Record<string, unknown>;
  expect(first.amount).toBe("123.45");
  expect(first.customer_id).toBe(10);
  expect(first.request_id).toBe(second.request_id);
  expect(String(first.closed_at)).toMatch(/Z$/);
});
it("requires a reason and confirmation before voiding", async () => {
  const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);
  setup(<SalesLedger />);
  await userEvent.click(
    await screen.findByRole("button", { name: "Void sale #1" }),
  );
  await userEvent.click(screen.getByRole("button", { name: "Confirm void" }));
  expect(
    await screen.findByText("Enter a reason for voiding this sale."),
  ).toBeInTheDocument();
  expect(confirm).not.toHaveBeenCalled();
  await userEvent.type(
    screen.getByLabelText("Reason for voiding"),
    "Wrong amount",
  );
  await userEvent.click(screen.getByRole("button", { name: "Confirm void" }));
  expect(confirm).toHaveBeenCalled();
  expect(write).not.toHaveBeenCalled();
  confirm.mockReturnValue(true);
  await userEvent.click(screen.getByRole("button", { name: "Confirm void" }));
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith("/sales/1/void", "PATCH", {
      version: 1,
      reason: "Wrong amount",
    }),
  );
});
it("staff sees a read-only ledger without recording or void controls", async () => {
  vi.mocked(useUser).mockReturnValue({
    data: { permissions: ["dashboard.view", "leads.update_assigned"] },
  } as unknown as ReturnType<typeof useUser>);
  setup(<SalesLedger />);
  expect(await screen.findByText("USD 123.45")).toBeInTheDocument();
  expect(
    screen.queryByRole("button", { name: "Record sale" }),
  ).not.toBeInTheDocument();
  expect(
    screen.queryByRole("button", { name: "Void sale #1" }),
  ).not.toBeInTheDocument();
});
