import React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { it, expect, vi, beforeEach } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  appointmentInstant,
  localDateTime,
} from "@/features/appointments/dates";
import { AppointmentForm } from "@/features/appointments/appointment-form";
import { ScheduleForm } from "@/features/appointments/availability-page";
import { write, list } from "@/features/crm/api";
import { ApiError } from "@/lib/api";
import { appointment, schedule } from "./appointment-fixtures";
import { customer, person, page } from "./crm-fixtures";
const { push } = vi.hoisted(() => ({ push: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));
vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/features/crm/api", () => ({
  get: vi.fn(),
  write: vi.fn(),
  list: vi.fn(),
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
  vi.mocked(list).mockImplementation(async (path) =>
    path === "/appointments/staff" ? page([person]) : page([customer]),
  );
  vi.mocked(write).mockResolvedValue(appointment);
});
it("requires a title, customer, owner, and valid dates", async () => {
  setup(<AppointmentForm />);
  await userEvent.click(
    screen.getByRole("button", { name: "Save appointment" }),
  );
  expect(
    await screen.findByText("Enter an appointment title."),
  ).toBeInTheDocument();
  expect(screen.getByText("Choose a customer.")).toBeInTheDocument();
  expect(write).not.toHaveBeenCalled();
});
it("sends canonical UTC times and the current version when editing", async () => {
  setup(<AppointmentForm appointment={appointment} />);
  await userEvent.click(
    screen.getByRole("button", { name: "Save appointment" }),
  );
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith(
      "/appointments/20",
      "PUT",
      expect.objectContaining({
        version: 1,
        start_at: "2027-01-04T10:00:00Z",
        end_at: "2027-01-04T11:00:00Z",
      }),
    ),
  );
  expect(push).toHaveBeenCalledWith("/appointments/20");
});
it("reports staff scheduling conflicts without navigating away", async () => {
  vi.mocked(write).mockRejectedValue(
    new ApiError(
      "This staff member already has an appointment in that time slot.",
      409,
    ),
  );
  setup(<AppointmentForm appointment={appointment} />);
  await userEvent.click(
    screen.getByRole("button", { name: "Save appointment" }),
  );
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "already has an appointment",
  );
  expect(
    screen.getByRole("button", { name: "Reload latest appointment" }),
  ).toBeInTheDocument();
  expect(push).not.toHaveBeenCalled();
});
it("saves availability with the existing version", async () => {
  setup(<ScheduleForm schedule={schedule} />);
  await userEvent.click(
    screen.getByRole("button", { name: "Save availability" }),
  );
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith("/staff/2/availability", "PUT", {
      timezone: "UTC",
      version: 1,
      windows: schedule.windows,
    }),
  );
});
it("rejects inverted working windows before calling the API", async () => {
  setup(<ScheduleForm schedule={schedule} />);
  await userEvent.clear(screen.getByLabelText("Working end 1"));
  await userEvent.type(screen.getByLabelText("Working end 1"), "08:00");
  await userEvent.click(
    screen.getByRole("button", { name: "Save availability" }),
  );
  expect(
    await screen.findByText("End time must be after start time."),
  ).toBeInTheDocument();
  expect(write).not.toHaveBeenCalled();
});
it("requires confirmation before saving a completely unavailable week", async () => {
  const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);
  setup(<ScheduleForm schedule={{ ...schedule, windows: [] }} />);
  await userEvent.click(
    screen.getByRole("button", { name: "Save availability" }),
  );
  expect(confirm).toHaveBeenCalled();
  expect(write).not.toHaveBeenCalled();
  confirm.mockRestore();
});

it("preserves an existing UTC instant when a local wall-clock input is unchanged", () => {
  const offset = vi
    .spyOn(Date.prototype, "getTimezoneOffset")
    .mockReturnValue(300);
  try {
    const original = "2026-11-01T06:30:00Z";
    const display = localDateTime(original);
    expect(display).toBe("2026-11-01T01:30");
    expect(appointmentInstant(display, original)).toBe(original);
  } finally {
    offset.mockRestore();
  }
});
