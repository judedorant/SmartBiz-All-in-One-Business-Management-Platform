"use client";
import { useState } from "react";
import Link from "next/link";
import { useInfiniteQuery, useQuery } from "@tanstack/react-query";
import { Plus, GripVertical } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { controlClass } from "@/components/forms/field";
import { useUser } from "@/hooks/use-user";
import { useDebounced } from "@/hooks/use-debounced";
import { get, list, write } from "@/features/crm/api";
import { useCrmWrite } from "@/features/crm/hooks";
import {
  CrmState,
  PageHeading,
  Value,
  DateText,
} from "@/features/crm/components";
import type { Lead, Stage } from "@/types/crm";
export function PipelineBoard() {
  const { data: user } = useUser();
  const [search, setSearch] = useState("");
  const term = useDebounced(search);
  const [dragged, setDragged] = useState<Lead | null>(null);
  const stages = useQuery({
    queryKey: ["crm", "stages"],
    queryFn: () => get<Stage[]>("/pipeline-stages"),
  });
  const move = useCrmWrite<{ lead: Lead; stageId: number }, Lead>(
    ({ lead, stageId }) =>
      write(`/leads/${lead.id}/stage`, "PATCH", {
        pipeline_stage_id: stageId,
        version: lead.version,
      }),
    "Pipeline updated",
  );
  return (
    <div>
      <PageHeading
        title="Sales pipeline"
        description="Drag an opportunity to a new stage, or use its stage selector."
        action={
          user?.permissions.includes("leads.manage") && (
            <Button asChild>
              <Link href="/leads/new">
                <Plus className="size-4" />
                New lead
              </Link>
            </Button>
          )
        }
      />
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <Input
          className="max-w-md"
          aria-label="Search pipeline"
          placeholder="Search opportunity or source…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <p className="text-xs text-muted-foreground">
          Counts include every permitted lead. Load more within each stage.
        </p>
      </div>
      <CrmState
        pending={stages.isPending}
        error={stages.error}
        retry={() => stages.refetch()}
      />
      {move.error && (
        <p
          role="alert"
          className="mb-4 rounded-lg bg-red-500/10 p-3 text-sm text-red-600"
        >
          {move.error.message}
        </p>
      )}
      <p role="status" aria-live="polite" className="sr-only">
        {move.isPending
          ? "Saving stage change"
          : move.isSuccess
            ? "Stage change saved"
            : ""}
      </p>
      {stages.data && !stages.error && (
        <div
          className="flex items-start gap-4 overflow-x-auto pb-5"
          aria-label="Sales pipeline stages"
        >
          {stages.data.map((stage) => (
            <PipelineColumn
              key={`${stage.id}-${term}`}
              stage={stage}
              stages={stages.data}
              search={term}
              pending={move.isPending}
              dragged={dragged}
              onDrag={setDragged}
              onMove={(lead, stageId) => {
                if (!move.isPending && stageId !== lead.pipeline_stage_id)
                  move.mutate({ lead, stageId });
              }}
            />
          ))}
        </div>
      )}
      {stages.data?.length === 0 && (
        <p role="alert">
          Pipeline stages are not configured. Ask your administrator to run the
          pipeline stage seeder.
        </p>
      )}
    </div>
  );
}
function PipelineColumn({
  stage,
  stages,
  search,
  pending,
  dragged,
  onDrag,
  onMove,
}: {
  stage: Stage;
  stages: Stage[];
  search: string;
  pending: boolean;
  dragged: Lead | null;
  onDrag: (lead: Lead | null) => void;
  onMove: (lead: Lead, stageId: number) => void;
}) {
  const [over, setOver] = useState(false);
  const query = useInfiniteQuery({
    queryKey: ["crm", "pipeline", stage.id, search],
    initialPageParam: 1,
    queryFn: ({ pageParam }) =>
      list<Lead>("/leads", {
        pipeline_stage_id: stage.id,
        search,
        page: pageParam,
        per_page: 10,
        sort: "updated_at",
        direction: "desc",
      }),
    getNextPageParam: (last) =>
      last.meta.current_page < last.meta.last_page
        ? last.meta.current_page + 1
        : undefined,
  });
  return (
    <section
      aria-label={`${stage.name} stage`}
      className={`w-72 shrink-0 rounded-xl border p-3 ${over ? "border-primary bg-primary/5" : "border-border bg-muted/40"}`}
      onDragOver={(e) => {
        if (dragged && !pending) {
          e.preventDefault();
          setOver(true);
        }
      }}
      onDragLeave={() => setOver(false)}
      onDrop={(e) => {
        e.preventDefault();
        setOver(false);
        if (dragged && !pending) onMove(dragged, stage.id);
        onDrag(null);
      }}
    >
      <div className="mb-4 flex items-center justify-between px-1">
        <h2 className="text-sm font-semibold">
          {stage.name}
          <span className="ml-2 rounded-full bg-card px-2 py-0.5 text-xs text-muted-foreground">
            {query.data?.pages[0]?.meta.total ?? "…"}
          </span>
        </h2>
        <span className="text-[10px] text-muted-foreground">
          {stage.probability}% probability
        </span>
      </div>
      <CrmState
        pending={query.isPending}
        error={query.error}
        retry={() => query.refetch()}
      />
      <div className="space-y-3">
        {!query.error &&
          query.data?.pages
            .flatMap((p) => p.data)
            .map((lead) => (
              <article
                key={lead.id}
                draggable={!pending}
                onDragStart={(e) => {
                  e.dataTransfer.setData("text/plain", String(lead.id));
                  e.dataTransfer.effectAllowed = "move";
                  onDrag(lead);
                }}
                onDragEnd={() => onDrag(null)}
                className="rounded-lg border border-border bg-card p-4 shadow-sm"
              >
                <div className="mb-3 flex items-start gap-2">
                  <GripVertical className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                  <Link
                    className="text-sm font-semibold leading-5 hover:text-primary"
                    href={`/leads/${lead.id}`}
                  >
                    {lead.title}
                  </Link>
                </div>
                <p className="mb-3 text-lg font-semibold">
                  <Value value={lead.estimated_value} />
                </p>
                <p className="text-xs text-muted-foreground">
                  {lead.assignee.name} · {lead.source}
                </p>
                <p className="mt-2 text-xs text-muted-foreground">
                  Close: <DateText value={lead.expected_close_date} />
                </p>
                <select
                  aria-label={`Move ${lead.title} to stage`}
                  className={`${controlClass} mt-4 text-xs`}
                  disabled={pending}
                  value={lead.pipeline_stage_id}
                  onChange={(e) => onMove(lead, Number(e.target.value))}
                >
                  {stages.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}
                    </option>
                  ))}
                </select>
              </article>
            ))}
      </div>
      {query.data?.pages[0]?.meta.total === 0 && (
        <p className="rounded-lg border border-dashed border-border p-6 text-center text-xs text-muted-foreground">
          No opportunities in this stage.
        </p>
      )}
      {query.hasNextPage && (
        <Button
          className="mt-3 w-full"
          variant="outline"
          size="sm"
          disabled={query.isFetchingNextPage}
          onClick={() => query.fetchNextPage()}
        >
          {query.isFetchingNextPage ? "Loading…" : "Load more opportunities"}
        </Button>
      )}
    </section>
  );
}
