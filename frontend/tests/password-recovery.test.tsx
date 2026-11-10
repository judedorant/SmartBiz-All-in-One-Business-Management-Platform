import React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, expect, it, vi } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { PasswordForm } from "@/features/auth/password-form";
import { SendPasswordReset } from "@/features/users/send-password-reset";
import { api, csrf, ApiError } from "@/lib/api";
import { write } from "@/features/crm/api";
const { replace } = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace }) }));
vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/lib/api", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api")>()),
  api: vi.fn(),
  csrf: vi.fn(),
}));
vi.mock("@/features/crm/api", () => ({ write: vi.fn() }));
function setup(children: React.ReactNode) {
  return render(
    <QueryClientProvider
      client={
        new QueryClient({ defaultOptions: { mutations: { retry: false } } })
      }
    >
      {children}
    </QueryClientProvider>,
  );
}
beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(api).mockResolvedValue(null);
  vi.mocked(csrf).mockResolvedValue(undefined);
});
it("requests a generic recovery response with CSRF protection", async () => {
  setup(<PasswordForm mode="forgot" />);
  await userEvent.type(
    screen.getByLabelText("Email address"),
    "staff@example.test",
  );
  await userEvent.click(
    screen.getByRole("button", { name: "Send reset link" }),
  );
  expect(await screen.findByRole("status")).toHaveTextContent(
    "If an account exists",
  );
  expect(csrf).toHaveBeenCalledOnce();
  expect(api).toHaveBeenCalledWith("/forgot-password", {
    method: "POST",
    body: JSON.stringify({ email: "staff@example.test" }),
  });
});
it("prevents duplicate requests while waiting", async () => {
  vi.mocked(api).mockReturnValue(new Promise(() => {}));
  setup(<PasswordForm mode="forgot" />);
  await userEvent.type(
    screen.getByLabelText("Email address"),
    "staff@example.test",
  );
  await userEvent.click(
    screen.getByRole("button", { name: "Send reset link" }),
  );
  expect(
    await screen.findByRole("button", { name: "Please wait…" }),
  ).toBeDisabled();
  expect(api).toHaveBeenCalledOnce();
});
it("rejects incomplete reset links", () => {
  setup(<PasswordForm mode="reset" />);
  expect(screen.getByRole("alert")).toHaveTextContent("incomplete");
  expect(screen.getByRole("button", { name: "Reset password" })).toBeDisabled();
});
async function fillPasswords() {
  await userEvent.type(
    screen.getByLabelText("New password"),
    "Secure-New-123!",
  );
  await userEvent.type(
    screen.getByLabelText("Confirm new password"),
    "Secure-New-123!",
  );
}
it("resets using the email and token then redirects to login", async () => {
  setup(
    <PasswordForm
      mode="reset"
      email="staff@example.test"
      token="secure-token"
    />,
  );
  await fillPasswords();
  await userEvent.click(screen.getByRole("button", { name: "Reset password" }));
  await waitFor(() => expect(replace).toHaveBeenCalledWith("/login"));
  expect(api).toHaveBeenCalledWith(
    "/reset-password",
    expect.objectContaining({
      body: JSON.stringify({
        password: "Secure-New-123!",
        password_confirmation: "Secure-New-123!",
        email: "staff@example.test",
        token: "secure-token",
      }),
    }),
  );
});
it("displays invalid link errors and a recovery path", async () => {
  vi.mocked(api).mockRejectedValue(
    new ApiError("Validation failed", 422, {
      token: ["This reset link is invalid or expired."],
    }),
  );
  setup(
    <PasswordForm mode="reset" email="staff@example.test" token="expired" />,
  );
  await fillPasswords();
  await userEvent.click(screen.getByRole("button", { name: "Reset password" }));
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "invalid or expired",
  );
  expect(replace).not.toHaveBeenCalled();
});
it("changes passwords with current credentials and supports visibility controls", async () => {
  setup(<PasswordForm mode="change" />);
  const current = screen.getByLabelText("Current password");
  expect(current).toHaveAttribute("type", "password");
  await userEvent.click(screen.getByRole("button", { name: "Show passwords" }));
  expect(current).toHaveAttribute("type", "text");
  await userEvent.type(current, "Old-Password-123!");
  await fillPasswords();
  await userEvent.click(
    screen.getByRole("button", { name: "Change password" }),
  );
  await waitFor(() => expect(replace).toHaveBeenCalledWith("/login"));
  expect(api).toHaveBeenCalledWith(
    "/profile/change-password",
    expect.objectContaining({ method: "PUT" }),
  );
});
it("does not submit mismatched confirmations", async () => {
  setup(<PasswordForm mode="reset" email="staff@example.test" token="token" />);
  await userEvent.type(
    screen.getByLabelText("New password"),
    "Secure-New-123!",
  );
  await userEvent.type(
    screen.getByLabelText("Confirm new password"),
    "Mismatch-123!",
  );
  await userEvent.click(screen.getByRole("button", { name: "Reset password" }));
  expect(await screen.findByText("Passwords must match.")).toBeInTheDocument();
  expect(api).not.toHaveBeenCalled();
});
it("admin action posts only the selected user ID", async () => {
  setup(<SendPasswordReset id={12} name="Taylor" />);
  await userEvent.click(
    screen.getByRole("button", { name: "Send Password Reset to Taylor" }),
  );
  expect(write).toHaveBeenCalledWith("/users/12/send-password-reset", "POST");
});
