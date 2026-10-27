"use client";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useQuery } from "@tanstack/react-query";
import { Button } from "@/components/ui/button";
import { get, write } from "@/features/crm/api";
import { useCrmWrite } from "@/features/crm/hooks";
import { useUser } from "@/hooks/use-user";
import {
  CrmState,
  PageHeading,
  Badge,
  DateText,
} from "@/features/crm/components";
import type { Appointment } from "@/types/appointments";
export function AppointmentDetail({ id }: { id: number }) {
  const { data: user } = useUser();
  const router = useRouter();
  const query = useQuery({
    queryKey: ["crm", "appointment", id],
    queryFn: () => get<Appointment>(`/appointments/${id}`),
  });
  const manager = !!user?.permissions.includes("appointments.manage");
  const cancel = useCrmWrite<number>(
    () =>
      write(`/appointments/${id}/cancel`, "PATCH", {
        version: query.data?.version,
      }),
    "Appointment cancelled",
  );
  const archive = useCrmWrite<number>(
    () =>
      write(`/appointments/${id}`, "DELETE", { version: query.data?.version }),
    "Appointment archived",
    () => router.push("/appointments"),
  );
  const a = query.data;
  if (!a || query.error)
    return (
      <CrmState
        pending={query.isPending}
        error={query.error}
        retry={() => query.refetch()}
      />
    );
  return (
    <div className="space-y-6">
      <PageHeading
        title={a.title}
        description={`Appointment #${a.id}`}
        back="/appointments"
        action={
          manager && (
            <div className="flex flex-wrap gap-2">
              <Button asChild variant="outline">
                <Link href={`/appointments/${id}/edit`}>Edit appointment</Link>
              </Button>
              {["scheduled", "confirmed"].includes(a.status) && (
                <Button
                  variant="outline"
                  disabled={cancel.isPending}
                  onClick={() => {
                    if (
                      window.confirm(
                        "Cancel this appointment and release its time slot?",
                      )
                    )
                      cancel.mutate(id);
                  }}
                >
                  Cancel appointment
                </Button>
              )}
              <Button
                variant="outline"
                disabled={archive.isPending}
                onClick={() => {
                  if (
                    window.confirm(
                      "Archive this appointment? It will be removed from calendar views.",
                    )
                  )
                    archive.mutate(id);
                }}
              >
                Archive appointment
              </Button>
            </div>
          )
        }
      />
      <section className="rounded-xl border border-border bg-card p-6">
        <div className="mb-6 flex items-center justify-between">
          <h2 className="font-semibold">Booking details</h2>
          <Badge>{a.status}</Badge>
        </div>
        <dl className="grid gap-6 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-xs text-muted-foreground">Customer</dt>
            <dd className="mt-2">
              {a.customer ? (
                <Link
                  className="text-primary hover:underline"
                  href={`/customers/${a.customer.id}`}
                >
                  {a.customer.first_name} {a.customer.last_name}
                </Link>
              ) : (
                "Archived customer"
              )}
            </dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Staff member</dt>
            <dd className="mt-2">{a.assignee.name}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Starts</dt>
            <dd className="mt-2">
              <DateText value={a.start_at} />
            </dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Ends</dt>
            <dd className="mt-2">
              <DateText value={a.end_at} />
            </dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Reminder</dt>
            <dd className="mt-2">
              {a.reminder_sent_at ? (
                <span>
                  Reminder processed <DateText value={a.reminder_sent_at} />
                </span>
              ) : (
                "Not sent"
              )}
            </dd>
          </div>
        </dl>
        <div className="mt-6 border-t border-border pt-5">
          <h3 className="text-xs text-muted-foreground">Description</h3>
          <p className="mt-2 whitespace-pre-wrap break-words text-sm">
            {a.description || "No description provided."}
          </p>
        </div>
      </section>
      <p className="text-xs text-muted-foreground">
        Displayed in your local timezone. Reminder emails to the assigned staff
        member are queued within 30 minutes of the start time.
      </p>
    </div>
  );
}
