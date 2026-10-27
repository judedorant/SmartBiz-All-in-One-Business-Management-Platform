import React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { vi, it, expect, beforeEach } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { CustomerForm } from "@/features/customers/customer-form";
import { LeadForm } from "@/features/leads/lead-form";
import { useUser } from "@/hooks/use-user";
import { write } from "@/features/crm/api";
import { ApiError } from "@/lib/api";
import { customer, lead } from "./crm-fixtures";
const { push } = vi.hoisted(() => ({ push: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));
vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/hooks/use-user", () => ({ useUser: vi.fn() }));
vi.mock("@/features/crm/api", () => ({
  write: vi.fn(),
  list: vi
    .fn()
    .mockResolvedValue({
      success: true,
      data: [],
      meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 },
    }),
}));
function setup(element: React.ReactNode) {
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
      {element}
    </QueryClientProvider>,
  );
}
beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(useUser).mockReturnValue({
    data: {
      id: 2,
      name: "Taylor Reed",
      permissions: ["customers.manage", "leads.manage"],
    },
  } as ReturnType<typeof useUser>);
});
it("rejects empty customer names before calling the API", async () => {
  setup(<CustomerForm />);
  await userEvent.click(screen.getByRole("button", { name: "Save customer" }));
  expect(await screen.findByText("Enter a first name.")).toBeInTheDocument();
  expect(screen.getByText("Enter a last name.")).toBeInTheDocument();
  expect(write).not.toHaveBeenCalled();
});
it("creates a customer through the API and navigates to its detail page", async () => {
  vi.mocked(write).mockResolvedValue(customer);
  setup(<CustomerForm />);
  await userEvent.type(screen.getByLabelText("First name"), "Ada");
  await userEvent.type(screen.getByLabelText("Last name"), "Lovelace");
  await userEvent.click(screen.getByRole("button", { name: "Save customer" }));
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith(
      "/customers",
      "POST",
      expect.objectContaining({
        first_name: "Ada",
        last_name: "Lovelace",
        email: null,
        assigned_user_ids: [],
      }),
    ),
  );
  expect(push).toHaveBeenCalledWith("/customers/10");
});
it("maps backend email uniqueness errors to the customer field", async () => {
  vi.mocked(write).mockRejectedValue(
    new ApiError("Validation failed", 422, {
      email: ["This email is already registered."],
    }),
  );
  setup(<CustomerForm customer={customer} />);
  await userEvent.click(screen.getByRole("button", { name: "Save customer" }));
  expect(
    await screen.findByText("This email is already registered."),
  ).toBeInTheDocument();
  expect(push).not.toHaveBeenCalled();
});
it("preserves the original version when editing a stale customer", async () => {
  vi.mocked(write).mockRejectedValue(
    new ApiError("This customer changed. Reload it before saving again.", 409),
  );
  setup(<CustomerForm customer={{ ...customer, version: 4 }} />);
  await userEvent.click(screen.getByRole("button", { name: "Save customer" }));
  expect(
    await screen.findByRole("button", { name: "Reload latest record" }),
  ).toBeInTheDocument();
  expect(write).toHaveBeenCalledWith(
    "/customers/10",
    "PUT",
    expect.objectContaining({ version: 4 }),
  );
  expect(push).not.toHaveBeenCalled();
});
it("staff edits omit reassignment and customer-link fields", async () => {
  vi.mocked(useUser).mockReturnValue({
    data: {
      id: 2,
      name: "Taylor Reed",
      permissions: ["leads.update_assigned"],
    },
  } as ReturnType<typeof useUser>);
  vi.mocked(write).mockResolvedValue(lead);
  setup(<LeadForm lead={lead} />);
  expect(screen.queryByLabelText("Search staff")).not.toBeInTheDocument();
  await userEvent.click(screen.getByRole("button", { name: "Save lead" }));
  await waitFor(() => expect(write).toHaveBeenCalled());
  const body = vi.mocked(write).mock.calls[0][2] as Record<string, unknown>;
  expect(body.version).toBe(1);
  expect(body).not.toHaveProperty("assigned_to");
  expect(body).not.toHaveProperty("customer_id");
});
it("prevents values with excessive decimal precision", async () => {
  setup(<LeadForm lead={lead} />);
  await userEvent.clear(screen.getByLabelText("Estimated deal value"));
  await userEvent.type(screen.getByLabelText("Estimated deal value"), "12.345");
  await userEvent.click(screen.getByRole("button", { name: "Save lead" }));
  expect(
    await screen.findByText(
      "Enter a non-negative value with up to 2 decimal places.",
    ),
  ).toBeInTheDocument();
  expect(write).not.toHaveBeenCalled();
});
it("conversion posts customer data with the original lead version", async () => {
  vi.mocked(write).mockResolvedValue({ ...lead, customer_id: 10, version: 2 });
  setup(<CustomerForm convertingLead={lead} />);
  await userEvent.type(screen.getByLabelText("First name"), "Ada");
  await userEvent.type(screen.getByLabelText("Last name"), "Lovelace");
  await userEvent.click(screen.getByRole("button", { name: "Convert lead" }));
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith(
      "/leads/5/convert",
      "POST",
      expect.objectContaining({ version: 1, assigned_user_ids: [2] }),
    ),
  );
  expect(push).toHaveBeenCalledWith("/customers/10");
});
