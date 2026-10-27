"use client";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { useUser } from "@/hooks/use-user";
import { get, write } from "@/features/crm/api";
import { useCrmWrite } from "@/features/crm/hooks";
import {
  CrmState,
  PageHeading,
  Badge,
  DateText,
  Value,
} from "@/features/crm/components";
import { ActivityFeed } from "@/features/crm/activity-feed";
import { StageSelect } from "./stage-select";
import type { Lead } from "@/types/crm";
export function LeadDetail({ id }: { id: number }) {
  const { data: user } = useUser();
  const router = useRouter();
  const query = useQuery({
    queryKey: ["crm", "lead", id],
    queryFn: () => get<Lead>(`/leads/${id}`),
  });
  const manager = !!user?.permissions.includes("leads.manage");
  const remove = useCrmWrite<number>(
    () => write(`/leads/${id}`, "DELETE", { version: query.data?.version }),
    "Lead deleted",
    () => router.push("/leads"),
  );
  const lead = query.data;
  if (!lead || query.error)
    return (
      <CrmState
        pending={query.isPending}
        error={query.error}
        retry={() => query.refetch()}
      />
    );
  const canUpdate =
    manager ||
    !!(
      user?.permissions.includes("leads.update_assigned") &&
      lead.assigned_to === user.id
    );
  return (
    <div className="space-y-6">
      <PageHeading
        title={lead.title}
        description={`Lead #${lead.id} · ${lead.source}`}
        back="/leads"
        action={
          <div className="flex flex-wrap gap-2">
            {canUpdate && (
              <Button variant="outline" asChild>
                <Link href={`/leads/${id}/edit`}>Edit lead</Link>
              </Button>
            )}
            {manager &&
              !lead.customer_id &&
              user?.permissions.includes("customers.manage") && (
                <Button asChild>
                  <Link href={`/leads/${id}/convert`}>Convert to customer</Link>
                </Button>
              )}
            {manager && (
              <Button
                variant="outline"
                disabled={remove.isPending}
                onClick={() => {
                  if (
                    window.confirm(
                      "Delete this lead? The record and its history will be archived.",
                    )
                  )
                    remove.mutate(id);
                }}
              >
                {remove.isPending ? "Deleting…" : "Delete lead"}
              </Button>
            )}
          </div>
        }
      />
      <div className="grid items-start gap-6 xl:grid-cols-[1fr_320px]">
        <section className="rounded-xl border border-border bg-card p-6">
          <div className="mb-6 flex items-center justify-between">
            <h2 className="font-semibold">Opportunity details</h2>
            <Badge>{lead.stage.name}</Badge>
          </div>
          <dl className="grid gap-6 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-xs text-muted-foreground">
                Estimated deal value
              </dt>
              <dd className="mt-2 text-xl font-semibold">
                <Value value={lead.estimated_value} />
              </dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">Owner</dt>
              <dd className="mt-2">{lead.assignee.name}</dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">Expected close</dt>
              <dd className="mt-2">
                <DateText value={lead.expected_close_date} />
              </dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">Customer</dt>
              <dd className="mt-2">
                {lead.customer ? (
                  <Link
                    className="text-primary hover:underline"
                    href={`/customers/${lead.customer.id}`}
                  >
                    {lead.customer.first_name} {lead.customer.last_name}
                  </Link>
                ) : lead.customer_id ? (
                  "Archived customer"
                ) : (
                  "Not linked"
                )}
              </dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">Created</dt>
              <dd className="mt-2">
                <DateText value={lead.created_at} />
              </dd>
            </div>
            {lead.converted_at && (
              <div>
                <dt className="text-xs text-muted-foreground">Converted</dt>
                <dd className="mt-2">
                  <DateText value={lead.converted_at} />
                </dd>
              </div>
            )}
          </dl>
          <div className="mt-7 border-t border-border pt-5">
            <h3 className="text-xs text-muted-foreground">Lead notes</h3>
            <p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6">
              {lead.notes || "No lead notes."}
            </p>
          </div>
        </section>
        {canUpdate && (
          <section className="rounded-xl border border-border bg-card p-6">
            <StageSelect lead={lead} />
          </section>
        )}
      </div>
      <ActivityFeed kind="leads" id={id} canAdd={canUpdate} />
    </div>
  );
}
