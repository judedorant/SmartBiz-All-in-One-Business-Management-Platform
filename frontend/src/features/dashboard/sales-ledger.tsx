"use client";
import { Fragment, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { Field, Input, controlClass } from "@/components/forms/field";
import { Pagination } from "@/components/tables/pagination";
import { useUser } from "@/hooks/use-user";
import { get, list, write } from "@/features/crm/api";
import { useCrmWrite } from "@/features/crm/hooks";
import {
  CrmState,
  PageHeading,
  DateText,
  Badge,
} from "@/features/crm/components";
import { formatMoney } from "@/lib/money";
import { SaleForm } from "./sale-form";
import type { Sale, SalesOptions } from "@/types/sales";
const voidSchema = z.object({
  reason: z
    .string()
    .trim()
    .min(1, "Enter a reason for voiding this sale.")
    .max(500),
});
export function SalesLedger() {
  const { data: user } = useUser();
  const manager = !!user?.permissions.includes("sales.manage");
  const [currency, setCurrency] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [voiding, setVoiding] = useState<number | null>(null);
  const options = useQuery({
    queryKey: ["crm", "sales-options"],
    queryFn: () => get<SalesOptions>("/sales/options"),
  });
  const query = useQuery({
    queryKey: ["crm", "sales", currency, status, page],
    queryFn: () => list<Sale>("/sales", { currency, status, page }),
  });
  return (
    <div className="space-y-6">
      <PageHeading
        title="Sales ledger"
        description="Recorded transactions, separated by currency. Voided records remain in the audit trail."
        back="/dashboard"
      />
      {manager && (
        <>
          <CrmState
            pending={options.isPending}
            error={options.error}
            retry={() => options.refetch()}
          />
          {options.data && !options.error && (
            <SaleForm options={options.data} />
          )}
        </>
      )}
      <section className="overflow-hidden rounded-xl border border-border bg-card">
        <div className="flex flex-wrap gap-3 border-b border-border p-4">
          <Field label="Filter sales currency">
            <select
              className={controlClass}
              value={currency}
              onChange={(e) => {
                setCurrency(e.target.value);
                setPage(1);
              }}
            >
              <option value="">All currencies (separate entries)</option>
              {options.data?.currencies.map((currency) => (
                <option key={currency}>{currency}</option>
              ))}
            </select>
          </Field>
          <Field label="Filter sale status">
            <select
              className={controlClass}
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                setPage(1);
              }}
            >
              <option value="">All statuses</option>
              <option value="completed">Completed</option>
              <option value="voided">Voided</option>
            </select>
          </Field>
        </div>
        <CrmState
          pending={query.isPending}
          error={query.error}
          retry={() => query.refetch()}
          empty={query.data?.data.length === 0}
        />
        {options.error && !manager && (
          <p role="alert" className="p-4 text-sm text-red-600">
            {options.error.message}
          </p>
        )}
        {query.data && !query.error && query.data.data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="bg-background text-xs uppercase text-muted-foreground">
                <tr>
                  {[
                    "Sale",
                    "Customer / lead",
                    "Amount",
                    "Closed",
                    "Status",
                    "Recorded by",
                    ...(manager ? ["Correction"] : []),
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
                {query.data.data.map((sale) => (
                  <Fragment key={sale.id}>
                    <tr className="border-t border-border">
                      <td className="px-4 py-4">#{sale.id}</td>
                      <td className="px-4 py-4">
                        <p>
                          {sale.customer
                            ? `${sale.customer.first_name} ${sale.customer.last_name}`
                            : `Archived customer #${sale.customer_id}`}
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                          {sale.lead?.title ??
                            (sale.lead_id ? "Archived lead" : "No linked lead")}
                        </p>
                      </td>
                      <td className="whitespace-nowrap px-4 py-4 font-semibold tabular-nums">
                        {formatMoney(sale.amount, sale.currency)}
                      </td>
                      <td className="whitespace-nowrap px-4 py-4">
                        <DateText value={sale.closed_at} />
                      </td>
                      <td className="px-4 py-4">
                        <Badge>{sale.status}</Badge>
                        {sale.void_reason && (
                          <p className="mt-2 max-w-xs whitespace-pre-wrap break-words text-xs text-muted-foreground">
                            {sale.void_reason}
                          </p>
                        )}
                      </td>
                      <td className="px-4 py-4">{sale.recorder.name}</td>
                      {manager && (
                        <td className="px-4 py-4">
                          {sale.status === "completed" ? (
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() => setVoiding(sale.id)}
                            >
                              Void sale #{sale.id}
                            </Button>
                          ) : (
                            <span className="text-xs text-muted-foreground">
                              <DateText value={sale.voided_at} />
                            </span>
                          )}
                        </td>
                      )}
                    </tr>
                    {manager && voiding === sale.id && (
                      <tr>
                        <td colSpan={7} className="border-t border-border p-4">
                          <VoidSaleForm
                            sale={sale}
                            onClose={() => setVoiding(null)}
                          />
                        </td>
                      </tr>
                    )}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {query.data && !query.error && (
          <Pagination
            page={query.data.meta.current_page}
            lastPage={query.data.meta.last_page}
            total={query.data.meta.total}
            onChange={setPage}
          />
        )}
      </section>
      {!manager && (
        <p className="text-xs text-muted-foreground">
          Read-only sales linked to your currently assigned leads and permitted
          customers.
        </p>
      )}
    </div>
  );
}
function VoidSaleForm({ sale, onClose }: { sale: Sale; onClose: () => void }) {
  const form = useForm<z.infer<typeof voidSchema>>({
    resolver: zodResolver(voidSchema),
    defaultValues: { reason: "" },
  });
  const mutation = useCrmWrite<{ reason: string }>(
    (values) =>
      write(`/sales/${sale.id}/void`, "PATCH", {
        ...values,
        version: sale.version,
      }),
    "Sale voided",
    onClose,
  );
  return (
    <form
      aria-label={`Void sale #${sale.id}`}
      onSubmit={form.handleSubmit((values) => {
        if (
          window.confirm(
            `Void sale #${sale.id}? It will remain in the ledger but be excluded from revenue.`,
          )
        )
          mutation.mutate(values);
      })}
      className="space-y-3"
      noValidate
    >
      <Field
        label="Reason for voiding"
        error={form.formState.errors.reason?.message}
      >
        <Input {...form.register("reason")} />
      </Field>
      {mutation.error && (
        <p role="alert" className="text-sm text-red-600">
          {mutation.error.message}
        </p>
      )}
      <div className="flex gap-2">
        <Button disabled={mutation.isPending}>
          {mutation.isPending ? "Voiding…" : "Confirm void"}
        </Button>
        <Button type="button" variant="outline" onClick={onClose}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
