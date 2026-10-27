"use client";
import { ApiError } from "@/lib/api";
import { useState, useCallback } from "react";
import Link from "next/link";
import dynamic from "next/dynamic";
import { useQuery } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { controlClass } from "@/components/forms/field";
import { Pagination } from "@/components/tables/pagination";
import { AssigneePicker } from "@/features/crm/pickers";
import { get, list, queryString } from "@/features/crm/api";
import {
  PageHeading,
  CrmState,
  Badge,
  DateText,
} from "@/features/crm/components";
import { useUser } from "@/hooks/use-user";
import { useDebounced } from "@/hooks/use-debounced";
import type { Appointment } from "@/types/appointments";
import type { Person } from "@/types/crm";
import type { CalendarRange } from "./calendar-view";
const Calendar = dynamic(
  () => import("./calendar-view").then((m) => m.CalendarView),
  {
    ssr: false,
    loading: () => (
      <p role="status" className="p-8">
        Loading calendar…
      </p>
    ),
  },
);
export function AppointmentsPage() {
  const { data: user } = useUser();
  const manager = !!user?.permissions.includes("appointments.manage");
  const [view, setView] = useState<"calendar" | "list">("calendar");
  const [range, setRange] = useState<CalendarRange | null>(null);
  const [owner, setOwner] = useState<Person | null>(null);
  const [filterOpen, setFilterOpen] = useState(false);
  const [search, setSearch] = useState("");
  const term = useDebounced(search);
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const onRange = useCallback(
    (next: CalendarRange) =>
      setRange((old) =>
        old?.start_at === next.start_at && old.end_at === next.end_at
          ? old
          : next,
      ),
    [],
  );
  const calendar = useQuery({
    queryKey: ["crm", "appointments-calendar", range, owner?.id],
    queryFn: () =>
      get<Appointment[]>(
        `/appointments/calendar?${queryString({ ...range!, assigned_to: owner?.id })}`,
      ),
    enabled: view === "calendar" && !!range,
  });
  const calendarError =
    calendar.error instanceof ApiError
      ? new Error(
          Object.values(calendar.error.errors).flat()[0] ??
            calendar.error.message,
        )
      : calendar.error;
  const rows = useQuery({
    queryKey: ["crm", "appointments", term, status, page, owner?.id],
    queryFn: () =>
      list<Appointment>("/appointments", {
        search: term,
        status,
        page,
        assigned_to: owner?.id,
      }),
    enabled: view === "list",
  });
  return (
    <div className="space-y-6">
      <PageHeading
        title="Appointments"
        description="Customer meetings and staff schedules, in your local timezone."
        action={
          <div className="flex flex-wrap gap-2">
            <Button asChild variant="outline">
              <Link href="/appointments/availability">
                {manager ? "Staff availability" : "My availability"}
              </Link>
            </Button>
            {manager && (
              <Button asChild>
                <Link href="/appointments/new">New appointment</Link>
              </Button>
            )}
          </div>
        }
      />
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex gap-2">
          <Button
            variant={view === "calendar" ? "default" : "outline"}
            onClick={() => setView("calendar")}
          >
            Calendar view
          </Button>
          <Button
            variant={view === "list" ? "default" : "outline"}
            onClick={() => setView("list")}
          >
            List view
          </Button>
        </div>
        {manager && (
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              onClick={() => setFilterOpen(!filterOpen)}
            >
              {owner ? `Staff: ${owner.name}` : "Filter staff"}
            </Button>
            {owner && (
              <Button
                variant="ghost"
                onClick={() => {
                  setOwner(null);
                  setPage(1);
                }}
              >
                All staff
              </Button>
            )}
          </div>
        )}
      </div>
      {filterOpen && manager && (
        <AssigneePicker
          label="Filter appointments by staff"
          endpoint="/appointments/staff"
          multiple={false}
          value={owner ? [owner] : []}
          onChange={(people) => {
            setOwner(people[0] ?? null);
            setPage(1);
          }}
        />
      )}
      {view === "calendar" ? (
        <>
          <CrmState
            pending={!!range && calendar.isPending}
            error={calendarError}
            retry={() => calendar.refetch()}
          />
          <Calendar
            appointments={calendar.error ? [] : (calendar.data ?? [])}
            canManage={manager}
            onRange={onRange}
          />
          <p className="text-xs text-muted-foreground">
            Month, week and day views show all permitted appointments in the
            visible range. Cancelled bookings are gray; completed bookings are
            green. Dragging/resizing requires management access. Use list view
            for keyboard-accessible record navigation.
          </p>
        </>
      ) : (
        <section className="overflow-hidden rounded-xl border border-border bg-card">
          <div className="flex flex-wrap gap-3 border-b border-border p-4">
            <Input
              className="max-w-md"
              aria-label="Search appointments"
              placeholder="Search appointment title or description"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
            <select
              className={`${controlClass} max-w-xs`}
              aria-label="Appointment status filter"
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                setPage(1);
              }}
            >
              <option value="">All statuses</option>
              <option value="scheduled">Scheduled</option>
              <option value="confirmed">Confirmed</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          <CrmState
            pending={rows.isPending}
            error={rows.error}
            retry={() => rows.refetch()}
            empty={rows.data?.data.length === 0}
          />
          {rows.data && !rows.error && (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="bg-background text-xs text-muted-foreground">
                  <tr>
                    {[
                      "Appointment",
                      "Customer",
                      "Owner",
                      "Start",
                      "End",
                      "Status",
                    ].map((h) => (
                      <th
                        scope="col"
                        key={h}
                        className="whitespace-nowrap px-4 py-4"
                      >
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {rows.data.data.map((a) => (
                    <tr key={a.id} className="border-t border-border">
                      <td className="px-4 py-4">
                        <Link
                          className="font-semibold text-primary hover:underline"
                          href={`/appointments/${a.id}`}
                        >
                          {a.title}
                        </Link>
                      </td>
                      <td className="px-4 py-4">
                        {a.customer
                          ? `${a.customer.first_name} ${a.customer.last_name}`
                          : "Archived customer"}
                      </td>
                      <td className="px-4 py-4">{a.assignee.name}</td>
                      <td className="whitespace-nowrap px-4 py-4">
                        <DateText value={a.start_at} />
                      </td>
                      <td className="whitespace-nowrap px-4 py-4">
                        <DateText value={a.end_at} />
                      </td>
                      <td className="px-4 py-4">
                        <Badge>{a.status}</Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {rows.data && !rows.error && (
            <Pagination
              page={rows.data.meta.current_page}
              lastPage={rows.data.meta.last_page}
              total={rows.data.meta.total}
              onChange={setPage}
            />
          )}
        </section>
      )}
    </div>
  );
}
