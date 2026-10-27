"use client";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useForm, useFieldArray } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Button } from "@/components/ui/button";
import { Field, Input, controlClass } from "@/components/forms/field";
import { AssigneePicker } from "@/features/crm/pickers";
import { useUser } from "@/hooks/use-user";
import { get, write } from "@/features/crm/api";
import { useCrmWrite } from "@/features/crm/hooks";
import { PageHeading, CrmState } from "@/features/crm/components";
import { availabilitySchema, type AvailabilityValues } from "./schema";
import { dayNames } from "./dates";
import type { Availability } from "@/types/appointments";
import type { Person } from "@/types/crm";
export function AvailabilityPage() {
  const { data: user } = useUser();
  const [selected, setSelected] = useState<Person | null>(null);
  const manager = !!user?.permissions.includes("appointments.manage");
  const id = selected?.id ?? user?.id;
  const query = useQuery({
    queryKey: ["crm", "availability", id],
    queryFn: () => get<Availability>(`/staff/${id}/availability`),
    enabled: !!id,
    refetchOnWindowFocus: false,
  });
  return (
    <div className="max-w-4xl space-y-6">
      <PageHeading
        title="Staff availability"
        description="Weekly working hours, with a single timezone per staff schedule."
        back="/appointments"
      />
      {manager && (
        <AssigneePicker
          label="Select staff schedule"
          endpoint="/appointments/staff"
          multiple={false}
          value={
            selected
              ? [selected]
              : user
                ? [{ id: user.id, name: user.name }]
                : []
          }
          onChange={(people) => setSelected(people[0] ?? null)}
        />
      )}
      <CrmState
        pending={query.isPending}
        error={query.error}
        retry={() => query.refetch()}
      />
      {query.data &&
        !query.error &&
        (manager ? (
          <ScheduleForm
            key={`${id}-${query.data.version}`}
            schedule={query.data}
          />
        ) : (
          <section className="rounded-xl border border-border bg-card p-6">
            <h2 className="font-semibold">
              Your working hours · {query.data.timezone}
            </h2>
            <ul className="mt-4 space-y-2 text-sm">
              {query.data.windows.map((w, i) => (
                <li key={i}>
                  {dayNames[w.day_of_week]} · {w.start_time}–{w.end_time}
                </li>
              ))}
            </ul>
            {!query.data.windows.length && (
              <p className="mt-4 text-sm text-muted-foreground">
                No availability configured. Contact your manager.
              </p>
            )}
            <p className="mt-5 text-xs text-muted-foreground">
              Your manager controls this schedule.
            </p>
          </section>
        ))}
    </div>
  );
}
export function ScheduleForm({ schedule }: { schedule: Availability }) {
  const form = useForm<AvailabilityValues>({
    resolver: zodResolver(availabilitySchema),
    defaultValues: { timezone: schedule.timezone, windows: schedule.windows },
  });
  const windows = useFieldArray({ control: form.control, name: "windows" });
  const save = useCrmWrite<AvailabilityValues>(
    (values) =>
      write(`/staff/${schedule.user_id}/availability`, "PUT", {
        ...values,
        version: schedule.version,
      }),
    "Availability saved",
  );
  return (
    <form
      aria-label="Staff working hours"
      className="space-y-5 rounded-xl border border-border bg-card p-6"
      onSubmit={form.handleSubmit((values) => {
        if (
          values.windows.length ||
          window.confirm(
            "Set this staff member unavailable for the entire week?",
          )
        )
          save.mutate(values);
      })}
      noValidate
    >
      <Field
        label="Schedule timezone"
        error={form.formState.errors.timezone?.message}
      >
        <Input
          placeholder="e.g. America/New_York"
          {...form.register("timezone")}
        />
      </Field>
      <p className="text-xs leading-5 text-muted-foreground">
        Enter local working hours for each day. Gaps and breaks are unavailable.
        Overnight windows are not supported. An empty schedule prevents
        bookings; existing upcoming appointments must still fit after changes.
      </p>
      {windows.fields.map((field, index) => (
        <fieldset
          key={field.id}
          className="grid items-start gap-3 rounded-lg border border-border p-4 sm:grid-cols-[1fr_1fr_1fr_auto]"
        >
          <legend className="px-1 text-xs text-muted-foreground">
            Working window {index + 1}
          </legend>
          <Field label={`Day ${index + 1}`}>
            <select
              className={controlClass}
              {...form.register(`windows.${index}.day_of_week`, {
                valueAsNumber: true,
              })}
            >
              {dayNames.map((name, day) => (
                <option key={name} value={day}>
                  {name}
                </option>
              ))}
            </select>
          </Field>
          <Field
            label={`Working start ${index + 1}`}
            error={form.formState.errors.windows?.[index]?.start_time?.message}
          >
            <Input
              type="time"
              {...form.register(`windows.${index}.start_time`)}
            />
          </Field>
          <Field
            label={`Working end ${index + 1}`}
            error={form.formState.errors.windows?.[index]?.end_time?.message}
          >
            <Input
              type="time"
              {...form.register(`windows.${index}.end_time`)}
            />
          </Field>
          <Button
            type="button"
            variant="ghost"
            className="mt-7"
            aria-label={`Remove window ${index + 1}`}
            onClick={() => windows.remove(index)}
          >
            Remove
          </Button>
        </fieldset>
      ))}
      {form.formState.errors.windows?.message && (
        <p role="alert" className="text-sm text-red-600">
          {form.formState.errors.windows.message}
        </p>
      )}
      {save.error && (
        <p role="alert" className="text-sm text-red-600">
          {save.error.message}
        </p>
      )}
      <div className="flex flex-wrap gap-3">
        <Button
          type="button"
          variant="outline"
          disabled={windows.fields.length >= 42}
          onClick={() =>
            windows.append({ day_of_week: 1, start_time: "", end_time: "" })
          }
        >
          Add working window
        </Button>
        <Button disabled={save.isPending}>
          {save.isPending ? "Saving…" : "Save availability"}
        </Button>
        {save.error && (
          <Button
            type="button"
            variant="outline"
            onClick={() => window.location.reload()}
          >
            Reload schedule
          </Button>
        )}
      </div>
    </form>
  );
}
