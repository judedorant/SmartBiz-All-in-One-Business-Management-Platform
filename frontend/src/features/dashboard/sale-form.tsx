"use client";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { Field, Input, controlClass } from "@/components/forms/field";
import { CustomerPicker } from "@/features/crm/pickers";
import { get, list, write } from "@/features/crm/api";
import { useCrmWrite } from "@/features/crm/hooks";
import { useDebounced } from "@/hooks/use-debounced";
import type { CustomerSummary, Lead, Stage } from "@/types/crm";
import type { Sale, SalesOptions } from "@/types/sales";
import { ApiError } from "@/lib/api";
export const saleSchema = z.object({
  customer_id: z.number().int().positive("Select a customer."),
  lead_id: z.number().int().positive().nullable(),
  amount: z
    .string()
    .regex(
      /^\d{1,13}(?:\.\d{1,2})?$/,
      "Enter a positive amount with up to 2 decimal places.",
    )
    .refine((v) => /[1-9]/.test(v), "Amount must be greater than zero."),
  currency: z.string().length(3),
  closed_at: z
    .string()
    .min(1, "Choose a close date and time.")
    .refine(
      (v) => Number.isFinite(new Date(v).valueOf()),
      "Choose a valid date and time.",
    )
    .refine(
      (v) => new Date(v).valueOf() <= Date.now(),
      "Close time cannot be in the future.",
    ),
});
function localNow() {
  const now = new Date();
  return new Date(now.valueOf() - now.getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16);
}
export function SaleForm({ options }: { options: SalesOptions }) {
  const [requestId, setRequestId] = useState(() => crypto.randomUUID());
  const [customer, setCustomer] = useState<CustomerSummary | null>(null);
  const [lead, setLead] = useState<Lead | null>(null);
  const form = useForm<z.infer<typeof saleSchema>>({
    resolver: zodResolver(saleSchema),
    defaultValues: {
      customer_id: 0,
      lead_id: null,
      amount: "",
      currency: options.default_currency,
      closed_at: localNow(),
    },
  });
  const save = useCrmWrite<z.infer<typeof saleSchema>, Sale>(
    (values) =>
      write<Sale>("/sales", "POST", {
        ...values,
        closed_at: new Date(values.closed_at)
          .toISOString()
          .replace(/\.\d{3}Z$/, "Z"),
        request_id: requestId,
      }),
    "Sale recorded",
    () => {
      setRequestId(crypto.randomUUID());
      setCustomer(null);
      setLead(null);
      form.reset({
        customer_id: 0,
        lead_id: null,
        amount: "",
        currency: options.default_currency,
        closed_at: localNow(),
      });
    },
  );
  async function submit(values: z.infer<typeof saleSchema>) {
    try {
      await save.mutateAsync(values);
    } catch (error) {
      if (error instanceof ApiError) {
        for (const key of Object.keys(saleSchema.shape) as (keyof z.infer<
          typeof saleSchema
        >)[])
          if (error.errors[key])
            form.setError(key, { message: error.errors[key][0] });
      }
      form.setError("root", {
        message:
          error instanceof Error
            ? error.message
            : "Sale could not be recorded.",
      });
    }
  }
  return (
    <form
      aria-label="Record sale"
      className="space-y-5 rounded-xl border border-border bg-card p-6"
      onSubmit={form.handleSubmit(submit)}
      noValidate
    >
      <div>
        <h2 className="font-semibold">Record a sale</h2>
        <p className="mt-2 text-xs leading-5 text-muted-foreground">
          Record an actual completed transaction. Won deals and estimated values
          do not automatically become revenue.
        </p>
      </div>
      <CustomerPicker
        value={customer}
        onChange={(selected) => {
          setCustomer(selected);
          setLead(null);
          form.setValue("customer_id", selected?.id ?? 0, {
            shouldValidate: true,
          });
          form.setValue("lead_id", null);
        }}
      />
      {form.formState.errors.customer_id && (
        <p role="alert" className="text-sm text-red-600">
          {form.formState.errors.customer_id.message}
        </p>
      )}
      <div className="grid gap-4 sm:grid-cols-3">
        <Field
          label="Sale amount"
          error={form.formState.errors.amount?.message}
        >
          <Input
            inputMode="decimal"
            placeholder="0.00"
            {...form.register("amount")}
          />
        </Field>
        <Field
          label="Sale currency"
          error={form.formState.errors.currency?.message}
        >
          <select className={controlClass} {...form.register("currency")}>
            {options.currencies.map((currency) => (
              <option key={currency}>{currency}</option>
            ))}
          </select>
        </Field>
        <Field
          label="Close date and time"
          error={form.formState.errors.closed_at?.message}
        >
          <Input type="datetime-local" {...form.register("closed_at")} />
        </Field>
      </div>
      <p className="text-xs text-muted-foreground">
        Close time uses your browser’s local timezone and is stored in UTC.
      </p>
      {customer && (
        <WonLeadPicker
          customerId={customer.id}
          value={lead}
          onChange={(selected) => {
            setLead(selected);
            form.setValue("lead_id", selected?.id ?? null);
          }}
        />
      )}
      {form.formState.errors.lead_id && (
        <p role="alert" className="text-sm text-red-600">
          {form.formState.errors.lead_id.message}
        </p>
      )}
      {form.formState.errors.root && (
        <p role="alert" className="text-sm text-red-600">
          {form.formState.errors.root.message}
        </p>
      )}
      <Button disabled={save.isPending}>
        {save.isPending ? "Recording…" : "Record sale"}
      </Button>
      <p className="text-xs leading-5 text-muted-foreground">
        Separate transactions may reference the same lead, for example
        installments. Retrying this submission uses the same identifier to
        prevent duplicate recording. Corrections are made by voiding the
        original entry.
      </p>
    </form>
  );
}
function WonLeadPicker({
  customerId,
  value,
  onChange,
}: {
  customerId: number;
  value: Lead | null;
  onChange: (lead: Lead | null) => void;
}) {
  const [search, setSearch] = useState("");
  const term = useDebounced(search);
  const [page, setPage] = useState(1);
  const stages = useQuery({
    queryKey: ["crm", "stages"],
    queryFn: () => get<Stage[]>("/pipeline-stages"),
  });
  const won = stages.data?.find((s) => s.slug === "won");
  const query = useQuery({
    queryKey: ["crm", "won-lead-picker", customerId, won?.id, term, page],
    queryFn: () =>
      list<Lead>("/leads", {
        customer_id: customerId,
        pipeline_stage_id: won!.id,
        search: term,
        page,
        per_page: 10,
      }),
    enabled: !!won,
  });
  return (
    <fieldset className="space-y-3 rounded-lg border border-border p-3">
      <legend className="px-1 text-sm font-medium">
        Linked won lead (optional)
      </legend>
      {value && (
        <div className="flex items-center justify-between text-sm">
          <span>{value.title}</span>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => onChange(null)}
          >
            Remove lead
          </Button>
        </div>
      )}
      <Input
        aria-label="Search won leads"
        placeholder="Search this customer's won leads"
        value={search}
        onChange={(e) => {
          setSearch(e.target.value);
          setPage(1);
        }}
      />
      {(query.error || stages.error) && (
        <p role="alert" className="text-xs text-red-600">
          {query.error?.message ?? stages.error?.message}
        </p>
      )}
      {query.isFetching && (
        <p role="status" className="text-xs text-muted-foreground">
          Loading won leads…
        </p>
      )}
      {query.data?.data.map((lead) => (
        <button
          type="button"
          key={lead.id}
          className={`block w-full rounded p-2 text-left text-sm hover:bg-muted ${value?.id === lead.id ? "bg-primary/10" : ""}`}
          onClick={() => onChange(lead)}
        >
          {lead.title}
        </button>
      ))}
      {query.data?.data.length === 0 && (
        <p className="text-xs text-muted-foreground">
          No matching won leads. You can record a sale without linking one.
        </p>
      )}
      {query.data && query.data.meta.last_page > 1 && (
        <div className="flex gap-2">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={page === 1}
            onClick={() => setPage(page - 1)}
          >
            Previous leads
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={page >= query.data.meta.last_page}
            onClick={() => setPage(page + 1)}
          >
            More leads
          </Button>
        </div>
      )}
    </fieldset>
  );
}
