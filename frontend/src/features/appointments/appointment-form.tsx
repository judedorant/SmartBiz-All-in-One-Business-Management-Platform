"use client";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Field, Input, controlClass } from "@/components/forms/field";
import { AssigneePicker, CustomerPicker } from "@/features/crm/pickers";
import { useCrmWrite } from "@/features/crm/hooks";
import { write } from "@/features/crm/api";
import { ApiError } from "@/lib/api";
import { appointmentSchema, type AppointmentValues } from "./schema";
import { appointmentInstant, localDateTime } from "./dates";
import type { Appointment } from "@/types/appointments";
import type { Person, CustomerSummary } from "@/types/crm";
export function AppointmentForm({
  appointment,
  initialStart,
  initialEnd,
  onSaved,
  onCancel,
}: {
  appointment?: Appointment;
  initialStart?: string;
  initialEnd?: string;
  onSaved?: (appointment: Appointment) => void;
  onCancel?: () => void;
}) {
  const [initial] = useState(appointment);
  const router = useRouter();
  const [people, setPeople] = useState<Person[]>(
    initial ? [initial.assignee] : [],
  );
  const [customer, setCustomer] = useState<CustomerSummary | null>(
    initial?.customer ?? null,
  );
  const form = useForm<AppointmentValues>({
    resolver: zodResolver(appointmentSchema),
    defaultValues: {
      title: initial?.title ?? "",
      description: initial?.description ?? "",
      customer_id: initial?.customer_id ?? 0,
      assigned_to: initial?.assigned_to ?? 0,
      start_at: initial
        ? localDateTime(initial.start_at)
        : initialStart
          ? localDateTime(initialStart)
          : "",
      end_at: initial
        ? localDateTime(initial.end_at)
        : initialEnd
          ? localDateTime(initialEnd)
          : "",
      status: initial?.status ?? "scheduled",
    },
  });
  const save = useCrmWrite<AppointmentValues, Appointment>(
    (values) =>
      write(
        initial ? `/appointments/${initial.id}` : "/appointments",
        initial ? "PUT" : "POST",
        {
          ...values,
          description: values.description || null,
          start_at: appointmentInstant(
            values.start_at,
            initial?.start_at ?? initialStart,
          ),
          end_at: appointmentInstant(
            values.end_at,
            initial?.end_at ?? initialEnd,
          ),
          ...(initial ? { version: initial.version } : {}),
        },
      ),
    "Appointment saved",
    (data) => onSaved ? onSaved(data) : router.push(`/appointments/${data.id}`),
  );
  async function submit(values: AppointmentValues) {
    try {
      await save.mutateAsync(values);
    } catch (error) {
      if (error instanceof ApiError) {
        for (const field of [
          "title",
          "description",
          "customer_id",
          "assigned_to",
          "start_at",
          "end_at",
          "status",
        ] as const)
          if (error.errors[field])
            form.setError(field, { message: error.errors[field][0] });
      }
      form.setError("root", {
        message:
          error instanceof Error
            ? error.message
            : "Appointment could not be saved.",
      });
    }
  }
  return (
    <form
      className="space-y-6 rounded-xl border border-border bg-card p-6"
      onSubmit={form.handleSubmit(submit)}
      noValidate
    >
      <Field
        label="Appointment title"
        error={form.formState.errors.title?.message}
      >
        <Input {...form.register("title")} />
      </Field>
      <CustomerPicker
        value={customer}
        onChange={(selected) => {
          setCustomer(selected);
          form.setValue("customer_id", selected?.id ?? 0, {
            shouldValidate: true,
          });
        }}
      />
      {form.formState.errors.customer_id && (
        <p role="alert" className="text-sm text-red-600">
          {form.formState.errors.customer_id.message}
        </p>
      )}
      <AssigneePicker
        label="Appointment owner"
        endpoint="/appointments/staff"
        multiple={false}
        value={people}
        onChange={(selected) => {
          setPeople(selected);
          form.setValue("assigned_to", selected[0]?.id ?? 0, {
            shouldValidate: true,
          });
        }}
      />
      {form.formState.errors.assigned_to && (
        <p role="alert" className="text-sm text-red-600">
          {form.formState.errors.assigned_to.message}
        </p>
      )}
      <div className="grid gap-5 sm:grid-cols-2">
        <Field
          label="Appointment start"
          error={form.formState.errors.start_at?.message}
        >
          <Input type="datetime-local" {...form.register("start_at")} />
        </Field>
        <Field
          label="Appointment end"
          error={form.formState.errors.end_at?.message}
        >
          <Input type="datetime-local" {...form.register("end_at")} />
        </Field>
      </div>
      <p className="text-xs leading-5 text-muted-foreground">
        Times use your browser’s timezone (
        {Intl.DateTimeFormat().resolvedOptions().timeZone}). Book 5 minutes to 8
        hours within a year. The booking must fit one working-hours window in
        the owner’s schedule timezone. Configure availability first; adjacent
        appointments are allowed.
      </p>
      <Field
        label="Appointment status"
        error={form.formState.errors.status?.message}
      >
        <select className={controlClass} {...form.register("status")}>
          <option value="scheduled">Scheduled</option>
          <option value="confirmed">Confirmed</option>
          {initial && (
            <>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </>
          )}
        </select>
      </Field>
      <Field
        label="Appointment description"
        error={form.formState.errors.description?.message}
      >
        <textarea
          rows={4}
          className={controlClass}
          {...form.register("description")}
        />
      </Field>
      {form.formState.errors.root && (
        <p role="alert" className="text-sm text-red-600">
          {form.formState.errors.root.message}
        </p>
      )}
      <div className="flex gap-3">
        <Button disabled={save.isPending}>
          {save.isPending ? "Saving…" : "Save appointment"}
        </Button>
        <Button
          type="button"
          variant="outline"
          onClick={() =>
            onCancel ? onCancel() : router.push(
              initial ? `/appointments/${initial.id}` : "/appointments",
            )
          }
        >
          Cancel
        </Button>
        {save.error instanceof ApiError && save.error.status === 409 && (
          <Button
            type="button"
            variant="outline"
            onClick={() => window.location.reload()}
          >
            Reload latest appointment
          </Button>
        )}
      </div>
    </form>
  );
}
