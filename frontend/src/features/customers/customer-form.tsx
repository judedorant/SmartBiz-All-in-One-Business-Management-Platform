"use client";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Field, Input, controlClass } from "@/components/forms/field";
import { ApiError } from "@/lib/api";
import { AssigneePicker } from "@/features/crm/pickers";
import { useCrmWrite } from "@/features/crm/hooks";
import { write } from "@/features/crm/api";
import { customerSchema, type CustomerValues } from "./schema";
import type { Customer, Lead, Person } from "@/types/crm";
export function CustomerForm({
  customer,
  convertingLead,
}: {
  customer?: Customer;
  convertingLead?: Lead;
}) {
  const [initial] = useState(customer);
  const [lead] = useState(convertingLead);
  const router = useRouter();
  const [people, setPeople] = useState<Person[]>(
    initial?.assignees ?? (lead ? [lead.assignee] : []),
  );
  const form = useForm<CustomerValues>({
    resolver: zodResolver(customerSchema),
    defaultValues: {
      first_name: initial?.first_name ?? "",
      last_name: initial?.last_name ?? "",
      email: initial?.email ?? "",
      phone: initial?.phone ?? "",
      company: initial?.company ?? "",
      address: initial?.address ?? "",
      status: initial?.status ?? "active",
      assigned_user_ids: people.map((p) => p.id),
    },
  });
  const save = useCrmWrite<CustomerValues, Customer | Lead>(
    (values) => {
      const body = {
        ...values,
        email: values.email || null,
        phone: values.phone || null,
        company: values.company || null,
        address: values.address || null,
        ...(initial
          ? { version: initial.version }
          : lead
            ? { version: lead.version }
            : {}),
      };
      return write<Customer | Lead>(
        initial
          ? `/customers/${initial.id}`
          : lead
            ? `/leads/${lead.id}/convert`
            : "/customers",
        initial ? "PUT" : "POST",
        body,
      );
    },
    lead ? "Lead converted to customer" : "Customer saved",
    (data) =>
      router.push(
        `/customers/${"customer_id" in data ? data.customer_id : data.id}`,
      ),
  );
  async function submit(values: CustomerValues) {
    try {
      await save.mutateAsync(values);
    } catch (error) {
      if (error instanceof ApiError) {
        for (const field of Object.keys(
          customerSchema.shape,
        ) as (keyof CustomerValues)[])
          if (error.errors[field])
            form.setError(field, { message: error.errors[field][0] });
      }
      form.setError("root", {
        message:
          error instanceof Error ? error.message : "Unable to save customer.",
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
        {(
          [
            { name: "first_name", label: "First name", required: true },
            { name: "last_name", label: "Last name", required: true },
            { name: "email", label: "Email address", type: "email" },
            { name: "phone", label: "Phone" },
            { name: "company", label: "Company" },
          ] as const
        ).map((field) => (
          <Field
            key={field.name}
            label={field.label}
            error={form.formState.errors[field.name]?.message}
          >
            <Input
              type={"type" in field ? field.type : "text"}
              autoComplete={field.name === "email" ? "email" : "off"}
              aria-invalid={!!form.formState.errors[field.name]}
              {...form.register(field.name)}
            />
          </Field>
        ))}
        <Field label="Status" error={form.formState.errors.status?.message}>
          <select className={controlClass} {...form.register("status")}>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </Field>
      </div>
      <Field label="Address" error={form.formState.errors.address?.message}>
        <textarea
          rows={3}
          className={controlClass}
          {...form.register("address")}
        />
      </Field>
      <AssigneePicker
        value={people}
        onChange={(selected) => {
          setPeople(selected);
          form.setValue(
            "assigned_user_ids",
            selected.map((p) => p.id),
            { shouldValidate: true },
          );
        }}
      />
      {form.formState.errors.assigned_user_ids?.message && (
        <p role="alert" className="text-sm text-red-600">
          {form.formState.errors.assigned_user_ids.message}
        </p>
      )}
      {lead && (
        <p className="text-xs leading-5 text-muted-foreground">
          Conversion creates a customer and links this lead. Its current owner
          is retained as assigned staff. The lead stage is unchanged; a won lead
          is not a recorded sale.
        </p>
      )}
      {form.formState.errors.root && (
        <div
          role="alert"
          className="rounded-lg bg-red-500/10 p-3 text-sm text-red-600"
        >
          {form.formState.errors.root.message}
          {save.error instanceof ApiError && save.error.status === 409 && (
            <Button
              type="button"
              variant="outline"
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
          {save.isPending ? "Saving…" : lead ? "Convert lead" : "Save customer"}
        </Button>
        <Button
          type="button"
          variant="outline"
          onClick={() =>
            router.push(
              initial
                ? `/customers/${initial.id}`
                : lead
                  ? `/leads/${lead.id}`
                  : "/customers",
            )
          }
        >
          Cancel
        </Button>
      </div>
    </form>
  );
}
