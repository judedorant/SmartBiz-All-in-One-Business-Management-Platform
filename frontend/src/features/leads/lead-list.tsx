"use client";
import { useState } from "react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Plus, Columns3 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { controlClass } from "@/components/forms/field";
import { Pagination } from "@/components/tables/pagination";
import { useUser } from "@/hooks/use-user";
import { useDebounced } from "@/hooks/use-debounced";
import { get, list } from "@/features/crm/api";
import {
  Badge,
  CrmState,
  PageHeading,
  Value,
  DateText,
} from "@/features/crm/components";
import type { Lead, Stage } from "@/types/crm";
export function LeadList() {
  const { data: user } = useUser();
  const [search, setSearch] = useState("");
  const term = useDebounced(search);
  const [stage, setStage] = useState("");
  const [sort, setSort] = useState("created_at");
  const [direction, setDirection] = useState("desc");
  const [page, setPage] = useState(1);
  const [owner, setOwner] = useState("");
  const stages = useQuery({
    queryKey: ["crm", "stages"],
    queryFn: () => get<Stage[]>("/pipeline-stages"),
  });
  const query = useQuery({
    queryKey: ["crm", "leads", term, stage, sort, direction, page, owner],
    queryFn: () =>
      list<Lead>("/leads", {
        search: term,
        pipeline_stage_id: stage,
        sort,
        direction,
        page,
        assigned_to: owner,
      }),
  });
  return (
    <div>
      <PageHeading
        title="Leads"
        description="Turn opportunities into lasting customer relationships."
        action={
          <div className="flex gap-2">
            <Button asChild variant="outline">
              <Link href="/pipeline">
                <Columns3 className="size-4" />
                Pipeline
              </Link>
            </Button>
            {user?.permissions.includes("leads.manage") && (
              <Button asChild>
                <Link href="/leads/new">
                  <Plus className="size-4" />
                  New lead
                </Link>
              </Button>
            )}
          </div>
        }
      />
      <section className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
        <div className="grid gap-3 border-b border-border p-4 sm:grid-cols-2 xl:grid-cols-[1fr_170px_160px_160px_130px]">
          <Input
            aria-label="Search leads"
            placeholder="Search lead title or source…"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
          />
          <select
            aria-label="Lead stage filter"
            className={controlClass}
            value={stage}
            onChange={(e) => {
              setStage(e.target.value);
              setPage(1);
            }}
          >
            <option value="">All stages</option>
            {stages.data?.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </select>
          <select
            aria-label="Lead assignment filter"
            className={controlClass}
            value={owner}
            onChange={(e) => {
              setOwner(e.target.value);
              setPage(1);
            }}
          >
            <option value="">All permitted leads</option>
            <option value={user?.id}>My leads</option>
          </select>
          <select
            aria-label="Sort leads by"
            className={controlClass}
            value={sort}
            onChange={(e) => {
              setSort(e.target.value);
              setPage(1);
            }}
          >
            <option value="created_at">Created date</option>
            <option value="title">Title</option>
            <option value="estimated_value">Estimated value</option>
            <option value="expected_close_date">Close date</option>
          </select>
          <select
            aria-label="Lead sort direction"
            className={controlClass}
            value={direction}
            onChange={(e) => {
              setDirection(e.target.value);
              setPage(1);
            }}
          >
            <option value="desc">Descending</option>
            <option value="asc">Ascending</option>
          </select>
        </div>
        {stages.error && (
          <p role="alert" className="p-4 text-sm text-red-600">
            Stage filters unavailable: {stages.error.message}
          </p>
        )}
        <CrmState
          pending={query.isPending}
          error={query.error}
          retry={() => query.refetch()}
          empty={query.data?.data.length === 0}
        />
        {query.data && !query.error && query.data.data.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="bg-background text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                  {[
                    "Opportunity",
                    "Customer",
                    "Owner",
                    "Estimated value",
                    "Stage",
                    "Expected close",
                  ].map((h) => (
                    <th
                      key={h}
                      scope="col"
                      className="whitespace-nowrap px-5 py-4"
                    >
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {query.data.data.map((lead) => (
                  <tr
                    key={lead.id}
                    className="border-t border-border hover:bg-muted/40"
                  >
                    <td className="px-5 py-4">
                      <Link
                        className="font-semibold hover:text-primary"
                        href={`/leads/${lead.id}`}
                      >
                        {lead.title}
                      </Link>
                      <p className="mt-1 text-xs text-muted-foreground">
                        {lead.source}
                      </p>
                    </td>
                    <td className="px-5 py-4">
                      {lead.customer ? (
                        <Link
                          className="hover:text-primary"
                          href={`/customers/${lead.customer.id}`}
                        >
                          {lead.customer.first_name} {lead.customer.last_name}
                        </Link>
                      ) : (
                        "Not linked"
                      )}
                    </td>
                    <td className="px-5 py-4">{lead.assignee.name}</td>
                    <td className="px-5 py-4">
                      <Value value={lead.estimated_value} />
                    </td>
                    <td className="px-5 py-4">
                      <Badge>{lead.stage.name}</Badge>
                    </td>
                    <td className="px-5 py-4">
                      <DateText value={lead.expected_close_date} />
                    </td>
                  </tr>
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
    </div>
  );
}
