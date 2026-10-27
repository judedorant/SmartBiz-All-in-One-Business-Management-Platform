"use client";
import FullCalendar from "@fullcalendar/react";
import dayGridPlugin from "@fullcalendar/daygrid";
import timeGridPlugin from "@fullcalendar/timegrid";
import interactionPlugin from "@fullcalendar/interaction";
import type { EventApi } from "@fullcalendar/core";
import { useRouter } from "next/navigation";
import { write } from "@/features/crm/api";
import { useCrmWrite } from "@/features/crm/hooks";
import { isoSeconds } from "./dates";
import type { Appointment } from "@/types/appointments";
export type CalendarRange = { start_at: string; end_at: string };
export function CalendarView({
  appointments,
  canManage,
  onRange,
}: {
  appointments: Appointment[];
  canManage: boolean;
  onRange: (range: CalendarRange) => void;
}) {
  const router = useRouter();
  const move = useCrmWrite<{
    appointment: Appointment;
    start: string;
    end: string;
  }>(
    (values) =>
      write(`/appointments/${values.appointment.id}/reschedule`, "PATCH", {
        start_at: values.start,
        end_at: values.end,
        version: values.appointment.version,
      }),
    "Appointment rescheduled",
  );
  async function change(info: { event: EventApi; revert: () => void }) {
    if (!info.event.start || !info.event.end) {
      info.revert();
      return;
    }
    const appointment = info.event.extendedProps.appointment as Appointment;
    try {
      await move.mutateAsync({
        appointment,
        start: isoSeconds(info.event.start),
        end: isoSeconds(info.event.end),
      });
    } catch {
      info.revert();
    }
  }
  return (
    <div className="smartbiz-calendar rounded-xl border border-border bg-card p-3 sm:p-5">
      <FullCalendar
        plugins={[dayGridPlugin, timeGridPlugin, interactionPlugin]}
        initialView="dayGridMonth"
        timeZone="local"
        headerToolbar={{
          left: "prev,next today",
          center: "title",
          right: "dayGridMonth,timeGridWeek,timeGridDay",
        }}
        buttonText={{
          today: "Today",
          month: "Month",
          week: "Week",
          day: "Day",
        }}
        height="auto"
        fixedWeekCount={false}
        dayMaxEvents
        nowIndicator
        editable={canManage && !move.isPending}
        selectable={canManage && !move.isPending}
        eventStartEditable={canManage && !move.isPending}
        eventDurationEditable={canManage && !move.isPending}
        eventDrop={change}
        eventResize={change}
        datesSet={(info) =>
          onRange({
            start_at: isoSeconds(info.start),
            end_at: isoSeconds(info.end),
          })
        }
        select={(info) => {
          if (!canManage) return;
          const start = new Date(info.start);
          let end = new Date(info.end);
          if (info.allDay) {
            start.setHours(9, 0, 0, 0);
            end = new Date(start.valueOf() + 30 * 60000);
          }
          router.push(
            `/appointments/new?${new URLSearchParams({ start: isoSeconds(start), end: isoSeconds(end) })}`,
          );
        }}
        eventClick={(info) => router.push(`/appointments/${info.event.id}`)}
        events={appointments.map((a) => ({
          id: String(a.id),
          title: a.title,
          start: a.start_at,
          end: a.end_at,
          backgroundColor:
            a.status === "cancelled"
              ? "#64748b"
              : a.status === "completed"
                ? "#059669"
                : "#4361ee",
          borderColor: "transparent",
          editable:
            canManage &&
            ["scheduled", "confirmed"].includes(a.status) &&
            !move.isPending,
          extendedProps: { appointment: a },
        }))}
        eventDidMount={(info) => {
          info.el.setAttribute(
            "aria-label",
            `${info.event.title}, ${(info.event.extendedProps.appointment as Appointment).status}`,
          );
        }}
      />
      {move.error && (
        <p role="alert" className="mt-4 text-sm text-red-600">
          {move.error.message}
        </p>
      )}
    </div>
  );
}
