import React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { vi, it, expect, beforeEach, afterEach } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { CustomerDetail } from "@/features/customers/customer-detail";
import { write, get, list } from "@/features/crm/api";
import { customer, page } from "./crm-fixtures";
const { push } = vi.hoisted(() => ({ push: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));
vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/hooks/use-user", () => ({
  useUser: () => ({ data: { permissions: ["customers.manage"] } }),
}));
vi.mock("@/features/crm/api", () => ({
  get: vi.fn(),
  list: vi.fn(),
  write: vi.fn(),
}));
function setup() {
  render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <CustomerDetail id={10} />
    </QueryClientProvider>,
  );
}
beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(get).mockResolvedValue(customer);
  vi.mocked(list).mockResolvedValue(page([]));
  vi.mocked(write).mockResolvedValue(null);
});
afterEach(() => vi.restoreAllMocks());
it("does not delete when the confirmation is cancelled", async () => {
  const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);
  setup();
  await userEvent.click(
    await screen.findByRole("button", { name: "Delete customer" }),
  );
  expect(confirm).toHaveBeenCalled();
  expect(write).not.toHaveBeenCalled();
});
it("deletes using the current version only after confirmation", async () => {
  vi.spyOn(window, "confirm").mockReturnValue(true);
  setup();
  await userEvent.click(
    await screen.findByRole("button", { name: "Delete customer" }),
  );
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith("/customers/10", "DELETE", {
      version: 1,
    }),
  );
  expect(push).toHaveBeenCalledWith("/customers");
});
