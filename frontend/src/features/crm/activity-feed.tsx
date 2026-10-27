"use client";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { Field, controlClass } from "@/components/forms/field";
import { Pagination } from "@/components/tables/pagination";
import { CrmState, DateText } from "./components";
import { list, write } from "./api";
import { useCrmWrite } from "./hooks";
import type { Activity } from "@/types/crm";
const schema = z.object({
  body: z.string().trim().min(1, "Enter an activity note.").max(10000),
});
export function ActivityFeed({
  kind,
  id,
  canAdd = false,
}: {
  kind: "customers" | "leads";
  id: number;
  canAdd?: boolean;
}) {
  const [page, setPage] = useState(1);
  const path = `/${kind}/${id}/activities`;
  const query = useQuery({
    queryKey: ["crm", kind, id, "activities", page],
    queryFn: () => list<Activity>(path, { page, per_page: 10 }),
  });
  const form = useForm<z.infer<typeof schema>>({
    resolver: zodResolver(schema),
    defaultValues: { body: "" },
  });
  const add = useCrmWrite<{ body: string }>(
    (values) => write(path, "POST", values),
    "Activity recorded",
    () => {
      form.reset();
      setPage(1);
    },
  );
  return (
    <section className="overflow-hidden rounded-xl border border-border bg-card">
      <div className="p-6">
        <h2 className="mb-5 font-semibold">Activity history</h2>
        {canAdd && (
          <form
            className="mb-6 space-y-3"
            onSubmit={form.handleSubmit((values) => add.mutate(values))}
          >
            <Field
              label="Add activity note"
              error={form.formState.errors.body?.message}
            >
              <textarea
                rows={3}
                className={controlClass}
                {...form.register("body")}
              />
            </Field>
            {add.error && (
              <p role="alert" className="text-sm text-red-600">
                {add.error.message}
              </p>
            )}
            <Button size="sm" disabled={add.isPending}>
              {add.isPending ? "Recording…" : "Record activity"}
            </Button>
          </form>
        )}
        <CrmState
          pending={query.isPending}
          error={query.error}
          retry={() => query.refetch()}
        />
        {query.data && !query.error && (
          <ol className="space-y-5">
            {query.data.data.map((activity) => (
              <li
                key={activity.id}
                className="border-l-2 border-primary/20 pl-4"
              >
                <p className="whitespace-pre-wrap break-words text-sm">
                  {activity.description}
                </p>
                <p className="mt-2 text-xs text-muted-foreground">
                  {activity.user.name} ·{" "}
                  <DateText value={activity.created_at} />
                </p>
              </li>
            ))}
          </ol>
        )}
        {query.data?.data.length === 0 && (
          <p className="text-sm text-muted-foreground">No activity recorded.</p>
        )}
      </div>
      {query.data && !query.error && (
        <Pagination
          page={query.data.meta.current_page}
          lastPage={query.data.meta.last_page}
          total={query.data.meta.total}
          onChange={setPage}
        />
      )}
    </section>
  );
}
