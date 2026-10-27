import React from "react";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { it, expect, vi, beforeEach } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { CalendarView } from "@/features/appointments/calendar-view";
import { AppointmentDetail } from "@/features/appointments/appointment-detail";
import { write, get } from "@/features/crm/api";
import { useUser } from "@/hooks/use-user";
import { ApiError } from "@/lib/api";
import { appointment } from "./appointment-fixtures";
const { props, revert, push } = vi.hoisted(() => ({
  props: { current: {} as Record<string, unknown> },
  revert: vi.fn(),
  push: vi.fn(),
}));
vi.mock("@fullcalendar/react", () => ({
  default: (options: Record<string, unknown>) => {
    props.current = options;
    return <div data-testid="fullcalendar">Calendar</div>;
  },
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));
vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/features/crm/api", () => ({ write: vi.fn(), get: vi.fn() }));
vi.mock("@/hooks/use-user", () => ({ useUser: vi.fn() }));
function setup(child: React.ReactNode) {
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
      {child}
    </QueryClientProvider>,
  );
}
function move() {
  const fn = props.current.eventDrop as (info: unknown) => Promise<void>;
  return fn({
    event: {
      start: new Date("2027-01-04T12:00:00Z"),
      end: new Date("2027-01-04T13:00:00Z"),
      extendedProps: { appointment },
    },
    revert,
  });
}
beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(write).mockResolvedValue(appointment);
  vi.mocked(get).mockResolvedValue(appointment);
  vi.mocked(useUser).mockReturnValue({
    data: { permissions: ["appointments.manage"] },
  } as unknown as ReturnType<typeof useUser>);
});
it("configures month, week, and day views and disables staff editing", () => {
  setup(
    <CalendarView
      appointments={[appointment]}
      canManage={false}
      onRange={vi.fn()}
    />,
  );
  expect(props.current.editable).toBe(false);
  expect(props.current.selectable).toBe(false);
  expect(props.current.headerToolbar).toEqual(
    expect.objectContaining({ right: "dayGridMonth,timeGridWeek,timeGridDay" }),
  );
});
it("reschedules a dropped event through the version-protected API", async () => {
  setup(
    <CalendarView appointments={[appointment]} canManage onRange={vi.fn()} />,
  );
  await move();
  expect(write).toHaveBeenCalledWith("/appointments/20/reschedule", "PATCH", {
    start_at: "2027-01-04T12:00:00Z",
    end_at: "2027-01-04T13:00:00Z",
    version: 1,
  });
  expect(revert).not.toHaveBeenCalled();
});
it("reverts a failed calendar move and exposes the API error", async () => {
  vi.mocked(write).mockRejectedValue(
    new ApiError("Time slot is already booked.", 409),
  );
  setup(
    <CalendarView appointments={[appointment]} canManage onRange={vi.fn()} />,
  );
  await move();
  expect(revert).toHaveBeenCalledOnce();
  expect(await screen.findByRole("alert")).toHaveTextContent("already booked");
});
it("does not cancel an appointment when confirmation is declined", async () => {
  const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);
  setup(<AppointmentDetail id={20} />);
  await userEvent.click(
    await screen.findByRole("button", { name: "Cancel appointment" }),
  );
  expect(write).not.toHaveBeenCalled();
  confirm.mockRestore();
});
it("cancels with a current version after confirmation", async () => {
  const confirm = vi.spyOn(window, "confirm").mockReturnValue(true);
  setup(<AppointmentDetail id={20} />);
  await userEvent.click(
    await screen.findByRole("button", { name: "Cancel appointment" }),
  );
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith("/appointments/20/cancel", "PATCH", {
      version: 1,
    }),
  );
  confirm.mockRestore();
});
it("staff details contain no management controls", async () => {
  vi.mocked(useUser).mockReturnValue({
    data: { permissions: ["appointments.view_assigned"] },
  } as unknown as ReturnType<typeof useUser>);
  setup(<AppointmentDetail id={20} />);
  expect(await screen.findByText("Discovery meeting")).toBeInTheDocument();
  expect(
    screen.queryByRole("button", { name: "Cancel appointment" }),
  ).not.toBeInTheDocument();
  expect(
    screen.queryByRole("link", { name: "Edit appointment" }),
  ).not.toBeInTheDocument();
});
