"use client";
import Link from "next/link";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Pagination } from "@/components/tables/pagination";
import { useUser } from "@/hooks/use-user";
import { get, list, write } from "@/features/crm/api";
import { useCrmWrite } from "@/features/crm/hooks";
import {
  CrmState,
  PageHeading,
  Badge,
  DateText,
  Value,
} from "@/features/crm/components";
import { ActivityFeed } from "@/features/crm/activity-feed";
import { CustomerNotes } from "./customer-notes";
import type { Customer, Lead } from "@/types/crm";
export function CustomerDetail({ id }: { id: number }) {
  const { data: user } = useUser();
  const router = useRouter();
  const manager = !!user?.permissions.includes("customers.manage");
  const [page, setPage] = useState(1);
  const query = useQuery({
    queryKey: ["crm", "customer", id],
    queryFn: () => get<Customer>(`/customers/${id}`),
  });
  const leads = useQuery({
    queryKey: ["crm", "customer-leads", id, page],
    queryFn: () =>
      list<Lead>("/leads", { customer_id: id, page, per_page: 10 }),
    enabled: !!query.data,
  });
  const remove = useCrmWrite<number>(
    () => write(`/customers/${id}`, "DELETE", { version: query.data?.version }),
    "Customer deleted",
    () => router.push("/customers"),
  );
  const customer = query.data;
  if (!customer || query.error)
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
        title={`${customer.first_name} ${customer.last_name}`}
        description={customer.customer_code}
        back="/customers"
        action={
          manager && (
            <div className="flex gap-2">
              <Button variant="outline" asChild>
                <Link href={`/customers/${id}/edit`}>Edit customer</Link>
              </Button>
              <Button
                variant="outline"
                disabled={remove.isPending}
                onClick={() => {
                  if (
                    window.confirm(
                      "Delete this customer? The record will be archived and removed from the customer list.",
                    )
                  )
                    remove.mutate(id);
                }}
              >
                {remove.isPending ? "Deleting…" : "Delete customer"}
              </Button>
            </div>
          )
        }
      />
      <section className="rounded-xl border border-border bg-card p-6">
        <div className="mb-6 flex items-center justify-between">
          <h2 className="font-semibold">Customer details</h2>
          <Badge>{customer.status}</Badge>
        </div>
        <dl className="grid gap-6 text-sm sm:grid-cols-2 xl:grid-cols-3">
          {[
            ["Email", customer.email],
            ["Phone", customer.phone],
            ["Company", customer.company],
            ["Address", customer.address],
            [
              "Assigned staff",
              customer.assignees.map((p) => p.name).join(", ") || "Unassigned",
            ],
            ["Created by", customer.creator.name],
          ].map(([label, value]) => (
            <div key={label}>
              <dt className="text-xs text-muted-foreground">{label}</dt>
              <dd className="mt-2 whitespace-pre-wrap break-words">
                {value || "—"}
              </dd>
            </div>
          ))}
          <div>
            <dt className="text-xs text-muted-foreground">Created</dt>
            <dd className="mt-2">
              <DateText value={customer.created_at} />
            </dd>
          </div>
        </dl>
      </section>
      <div className="grid items-start gap-6 xl:grid-cols-2">
        <CustomerNotes id={id} canManage={manager} />
        <ActivityFeed kind="customers" id={id} />
      </div>
      <section className="overflow-hidden rounded-xl border border-border bg-card">
        <div className="p-6">
          <h2 className="font-semibold">Linked leads</h2>
          <p className="mt-1 text-xs text-muted-foreground">
            Only leads you have permission to view are shown.
          </p>
        </div>
        <CrmState
          pending={leads.isPending}
          error={leads.error}
          retry={() => leads.refetch()}
          empty={leads.data?.data.length === 0}
        />
        {leads.data && !leads.error && (
          <ul className="divide-y divide-border">
            {leads.data.data.map((lead) => (
              <li
                key={lead.id}
                className="flex flex-wrap items-center justify-between gap-3 px-6 py-4"
              >
                <div>
                  <Link
                    href={`/leads/${lead.id}`}
                    className="text-sm font-semibold hover:text-primary"
                  >
                    {lead.title}
                  </Link>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {lead.assignee.name}
                  </p>
                </div>
                <div className="flex items-center gap-4 text-sm">
                  <Value value={lead.estimated_value} />
                  <Badge>{lead.stage.name}</Badge>
                </div>
              </li>
            ))}
          </ul>
        )}
        {leads.data && !leads.error && (
          <Pagination
            page={leads.data.meta.current_page}
            lastPage={leads.data.meta.last_page}
            total={leads.data.meta.total}
            onChange={setPage}
          />
        )}
      </section>
    </div>
  );
}
