import React from "react";
import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { LoginForm } from "@/features/auth/login-form";
import { authApi } from "@/features/auth/api";
import { ApiError } from "@/lib/api";
const { replace } = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace }) }));
vi.mock("sonner", () => ({ toast: { success: vi.fn() } }));
vi.mock("@/features/auth/api", () => ({ authApi: { login: vi.fn() } }));
function setup() {
  const client = new QueryClient({
    defaultOptions: { mutations: { retry: false }, queries: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <LoginForm />
    </QueryClientProvider>,
  );
  return client;
}
beforeEach(() => vi.clearAllMocks());
describe("Sign in", () => {
  it("validates required inputs before calling the API", async () => {
    setup();
    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));
    expect(
      await screen.findByText("Enter a valid email address."),
    ).toBeInTheDocument();
    expect(screen.getByText("Enter your password.")).toBeInTheDocument();
    expect(authApi.login).not.toHaveBeenCalled();
  });
  it("shows backend credential errors without navigating", async () => {
    vi.mocked(authApi.login).mockRejectedValue(
      new ApiError("Validation failed", 422, {
        email: ["Credentials are incorrect."],
      }),
    );
    setup();
    await userEvent.type(
      screen.getByLabelText("Email address"),
      "staff@example.test",
    );
    await userEvent.type(screen.getByLabelText("Password"), "incorrect");
    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));
    expect(
      await screen.findByText("Credentials are incorrect."),
    ).toBeInTheDocument();
    expect(replace).not.toHaveBeenCalled();
  });
  it("clears previous user data and navigates after successful sign in", async () => {
    const user = {
      id: 3,
      name: "Staff Example",
      email: "staff@example.test",
      status: "active" as const,
      last_login_at: null,
      roles: ["Staff"],
      permissions: ["dashboard.view"],
    };
    vi.mocked(authApi.login).mockResolvedValue(user);
    const client = setup();
    client.setQueryData(["previous-private-records"], [{ secret: "old" }]);
    await userEvent.type(
      screen.getByLabelText("Email address"),
      "staff@example.test",
    );
    await userEvent.type(screen.getByLabelText("Password"), "test-secret");
    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));
    await waitFor(() => expect(replace).toHaveBeenCalledWith("/dashboard"));
    expect(client.getQueryData(["auth", "me"])).toEqual(user);
    expect(client.getQueryData(["previous-private-records"])).toBeUndefined();
  });
  it("shows an actionable error when the API is unavailable", async () => {
    vi.mocked(authApi.login).mockRejectedValue(
      new ApiError("Cannot reach SmartBiz. Check that the API is running.", 0),
    );
    setup();
    await userEvent.type(
      screen.getByLabelText("Email address"),
      "user@example.test",
    );
    await userEvent.type(screen.getByLabelText("Password"), "test-secret");
    await userEvent.click(screen.getByRole("button", { name: "Sign in" }));
    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Cannot reach SmartBiz",
    );
  });
});
