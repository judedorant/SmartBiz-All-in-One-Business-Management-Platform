import React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, expect, it, vi } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { UsersWorkspace } from "@/features/users/workspace";
import { useUser } from "@/hooks/use-user";
import { list, write } from "@/features/crm/api";
import { ApiError } from "@/lib/api";

vi.mock("@/hooks/use-user", () => ({ useUser: vi.fn() }));
vi.mock("@/hooks/use-debounced", () => ({
  useDebounced: (value: string) => value,
}));
vi.mock("@/features/crm/api", () => ({ list: vi.fn(), write: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace: vi.fn() }) }));
vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const account = {
  id: 2,
  name: "Taylor Reed",
  email: "taylor@example.test",
  roles: ["Staff"],
  status: "active",
  last_login_at: null,
  permissions: [],
  revision: "a".repeat(64),
};
function setup() {
  render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { queries: { retry: false } } })
      }
    >
      <UsersWorkspace />
    </QueryClientProvider>,
  );
}
beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(useUser).mockReturnValue({
    data: { id: 1, permissions: ["users.manage"] },
  } as unknown as ReturnType<typeof useUser>);
  vi.mocked(list).mockResolvedValue({
    success: true,
    message: "Users",
    data: [account],
    meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 },
  });
  vi.mocked(write).mockResolvedValue(account);
});

it("blocks account management for non-admins without fetching users", () => {
  vi.mocked(useUser).mockReturnValue({
    data: { id: 1, permissions: ["dashboard.view"] },
  } as unknown as ReturnType<typeof useUser>);
  setup();
  expect(screen.getByRole("alert")).toHaveTextContent("Only Admins");
  expect(list).not.toHaveBeenCalled();
  expect(
    screen.queryByRole("button", { name: "Add user" }),
  ).not.toBeInTheDocument();
});

it("filters the account list by role and status", async () => {
  setup();
  await screen.findByRole("button", { name: "Edit Taylor Reed" });
  await userEvent.selectOptions(
    screen.getByLabelText("Filter by role"),
    "Manager",
  );
  await userEvent.selectOptions(
    screen.getByLabelText("Filter by status"),
    "inactive",
  );
  await waitFor(() =>
    expect(list).toHaveBeenLastCalledWith(
      "/users",
      expect.objectContaining({ role: "Manager", status: "inactive", page: 1 }),
    ),
  );
});

it("creates a user with the selected role and confirmed password", async () => {
  setup();
  await userEvent.click(screen.getByRole("button", { name: "Add user" }));
  await userEvent.type(screen.getByLabelText("Full name"), "New Colleague");
  await userEvent.type(
    screen.getByLabelText("Email address"),
    "colleague@example.test",
  );
  await userEvent.selectOptions(
    screen.getByLabelText("Role", { exact: true }),
    "Manager",
  );
  await userEvent.type(
    screen.getByLabelText("Password", { exact: true }),
    "New-Password-123!",
  );
  await userEvent.type(
    screen.getByLabelText("Confirm password"),
    "New-Password-123!",
  );
  await userEvent.click(screen.getByRole("button", { name: "Create user" }));
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith(
      "/users",
      "POST",
      expect.objectContaining({
        name: "New Colleague",
        role: "Manager",
        status: "active",
        password: "New-Password-123!",
        password_confirmation: "New-Password-123!",
      }),
    ),
  );
  await waitFor(() =>
    expect(
      screen.queryByRole("region", { name: "Create user" }),
    ).not.toBeInTheDocument(),
  );
});

it("edits a role and deactivates an account while keeping its current password", async () => {
  setup();
  await userEvent.click(
    await screen.findByRole("button", { name: "Edit Taylor Reed" }),
  );
  await userEvent.selectOptions(
    screen.getByLabelText("Role", { exact: true }),
    "Manager",
  );
  await userEvent.selectOptions(
    screen.getByLabelText("Account status"),
    "inactive",
  );
  await userEvent.click(screen.getByRole("button", { name: "Save user" }));
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith(
      "/users/2",
      "PUT",
      expect.objectContaining({
        role: "Manager",
        status: "inactive",
        revision: account.revision,
        password: undefined,
      }),
    ),
  );
});

it("locks a stale edit and offers to reload the user list", async () => {
  vi.mocked(write).mockRejectedValue(
    new ApiError(
      "This account changed. Reload the user list before saving again.",
      409,
    ),
  );
  setup();
  await userEvent.click(
    await screen.findByRole("button", { name: "Edit Taylor Reed" }),
  );
  await userEvent.click(screen.getByRole("button", { name: "Save user" }));
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "This account changed",
  );
  expect(screen.getByRole("button", { name: "Save user" })).toBeDisabled();
  await userEvent.click(
    screen.getByRole("button", { name: "Close and reload users" }),
  );
  expect(
    screen.queryByRole("region", { name: "Edit user" }),
  ).not.toBeInTheDocument();
});

it("protects the signed-in admin role and active status", async () => {
  vi.mocked(useUser).mockReturnValue({
    data: { id: 2, permissions: ["users.manage"] },
  } as unknown as ReturnType<typeof useUser>);
  vi.mocked(list).mockResolvedValue({
    success: true,
    message: "Users",
    data: [{ ...account, roles: ["Admin"] }],
    meta: { current_page: 1, last_page: 1, total: 1, per_page: 20 },
  });
  setup();
  await userEvent.click(
    await screen.findByRole("button", { name: "Edit Taylor Reed" }),
  );
  expect(screen.getByLabelText("Role", { exact: true })).toBeDisabled();
  expect(screen.getByLabelText("Account status")).toBeDisabled();
  await userEvent.click(screen.getByRole("button", { name: "Save user" }));
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith(
      "/users/2",
      "PUT",
      expect.objectContaining({ role: "Admin", status: "active" }),
    ),
  );
});
