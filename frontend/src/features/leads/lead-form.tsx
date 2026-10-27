"use client";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Field, Input, controlClass } from "@/components/forms/field";
import { ApiError } from "@/lib/api";
import { AssigneePicker, CustomerPicker } from "@/features/crm/pickers";
import { useCrmWrite } from "@/features/crm/hooks";
import { write } from "@/features/crm/api";
import { useUser } from "@/hooks/use-user";
import { leadSchema, type LeadValues } from "./schema";
import type { Lead, Person, CustomerSummary } from "@/types/crm";
export function LeadForm({ lead }: { lead?: Lead }) {
  const { data: user } = useUser();
  const [initial] = useState(lead);
  const router = useRouter();
  const manager = !!user?.permissions.includes("leads.manage");
  const [people, setPeople] = useState<Person[]>(
    initial
      ? [initial.assignee]
      : user
        ? [{ id: user.id, name: user.name }]
        : [],
  );
  const [customer, setCustomer] = useState<CustomerSummary | null>(
    initial?.customer ?? null,
  );
  const form = useForm<LeadValues>({
    resolver: zodResolver(leadSchema),
    defaultValues: {
      title: initial?.title ?? "",
      source: initial?.source ?? "",
      estimated_value: initial?.estimated_value ?? "0.00",
      expected_close_date: initial?.expected_close_date ?? "",
      notes: initial?.notes ?? "",
      assigned_to: initial?.assigned_to ?? user?.id ?? 0,
      customer_id: initial?.customer_id ?? null,
    },
  });
  const save = useCrmWrite<LeadValues, Lead>(
    (values) => {
      const { assigned_to, customer_id, ...details } = values;
      return write<Lead>(
        initial ? `/leads/${initial.id}` : "/leads",
        initial ? "PUT" : "POST",
        {
          ...details,
          expected_close_date: values.expected_close_date || null,
          notes: values.notes || null,
          ...(manager
            ? { assigned_to, ...(initial?.converted_at ? {} : { customer_id }) }
            : {}),
          ...(initial ? { version: initial.version } : {}),
        },
      );
    },
    "Lead saved",
    (data) => router.push(`/leads/${data.id}`),
  );
  async function submit(values: LeadValues) {
    try {
      await save.mutateAsync(values);
    } catch (error) {
      if (error instanceof ApiError) {
        for (const field of Object.keys(
          leadSchema.shape,
        ) as (keyof LeadValues)[])
          if (error.errors[field])
            form.setError(field, { message: error.errors[field][0] });
      }
      form.setError("root", {
        message:
          error instanceof Error ? error.message : "Unable to save lead.",
      });
    }
  }
  return (
    <form
      className="space-y-6 rounded-xl border border-border bg-card p-6"
      onSubmit={form.handleSubmit(submit)}
      noValidate
    >
      <div className="grid gap-5 sm:grid-cols-2">
        <Field label="Lead title" error={form.formState.errors.title?.message}>
          <Input
            aria-invalid={!!form.formState.errors.title}
            {...form.register("title")}
          />
        </Field>
        <Field label="Source" error={form.formState.errors.source?.message}>
          <Input
            placeholder="e.g. Referral"
            aria-invalid={!!form.formState.errors.source}
            {...form.register("source")}
          />
        </Field>
        <Field
          label="Estimated deal value"
          error={form.formState.errors.estimated_value?.message}
        >
          <Input
            inputMode="decimal"
            aria-invalid={!!form.formState.errors.estimated_value}
            {...form.register("estimated_value")}
          />
        </Field>
        <Field
          label="Expected close date"
          error={form.formState.errors.expected_close_date?.message}
        >
          <Input type="date" {...form.register("expected_close_date")} />
        </Field>
      </div>
      <p className="text-xs text-muted-foreground">
        Estimated values are stored as exact decimal amounts. Currency-aware
        sales reporting is introduced in a later phase.
      </p>
      <Field label="Lead notes" error={form.formState.errors.notes?.message}>
        <textarea
          rows={4}
          className={controlClass}
          {...form.register("notes")}
        />
      </Field>
      {manager && (
        <>
          <AssigneePicker
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
          {initial?.converted_at ? (
            <p className="text-sm text-muted-foreground">
              Converted customer:{" "}
              {initial.customer
                ? `${initial.customer.first_name} ${initial.customer.last_name}`
                : "Archived customer"}
              . This link cannot be changed.
            </p>
          ) : (
            <CustomerPicker
              value={customer}
              onChange={(selected) => {
                setCustomer(selected);
                form.setValue("customer_id", selected?.id ?? null);
              }}
            />
          )}
        </>
      )}
      {form.formState.errors.root && (
        <div
          role="alert"
          className="rounded-lg bg-red-500/10 p-3 text-sm text-red-600"
        >
          {form.formState.errors.root.message}
          {save.error instanceof ApiError && save.error.status === 409 && (
            <Button
              variant="outline"
              type="button"
              size="sm"
              className="ml-3"
              onClick={() => window.location.reload()}
            >
              Reload latest record
            </Button>
          )}
        </div>
      )}
      <div className="flex gap-3">
        <Button disabled={save.isPending}>
          {save.isPending ? "Saving…" : "Save lead"}
        </Button>
        <Button
          variant="outline"
          type="button"
          onClick={() =>
            router.push(initial ? `/leads/${initial.id}` : "/leads")
          }
        >
          Cancel
        </Button>
      </div>
    </form>
  );
}
