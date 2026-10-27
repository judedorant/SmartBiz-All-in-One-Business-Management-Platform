"use client";
import { useQuery } from "@tanstack/react-query";
import { useUser } from "@/hooks/use-user";
import { get } from "@/features/crm/api";
import { PageHeading, CrmState } from "@/features/crm/components";
import { AppointmentForm } from "./appointment-form";
import type { Appointment } from "@/types/appointments";
export function AppointmentEditor({
  id,
  initialStart,
  initialEnd,
}: {
  id?: number;
  initialStart?: string;
  initialEnd?: string;
}) {
  const { data: user } = useUser();
  const query = useQuery({
    queryKey: ["crm", "appointment", id],
    queryFn: () => get<Appointment>(`/appointments/${id}`),
    enabled: !!id,
    refetchOnWindowFocus: false,
  });
  if (!user?.permissions.includes("appointments.manage"))
    return (
      <p role="alert">You do not have permission to manage appointments.</p>
    );
  return (
    <div className="max-w-4xl">
      <PageHeading
        title={id ? "Edit appointment" : "New appointment"}
        description="Coordinate customer meetings within configured staff working hours."
        back={id ? `/appointments/${id}` : "/appointments"}
      />
      {id ? (
        <>
          <CrmState
            pending={query.isPending}
            error={query.error}
            retry={() => query.refetch()}
          />
          {query.data && !query.error && (
            <AppointmentForm key={query.data.id} appointment={query.data} />
          )}
        </>
      ) : (
        <AppointmentForm initialStart={initialStart} initialEnd={initialEnd} />
      )}
    </div>
  );
}
