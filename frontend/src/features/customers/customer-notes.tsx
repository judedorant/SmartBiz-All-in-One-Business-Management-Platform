"use client";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { Button } from "@/components/ui/button";
import { Field, controlClass } from "@/components/forms/field";
import { Pagination } from "@/components/tables/pagination";
import { CrmState, DateText } from "@/features/crm/components";
import { list, write } from "@/features/crm/api";
import { useCrmWrite } from "@/features/crm/hooks";
import type { Note } from "@/types/crm";
const schema = z.object({
  body: z.string().trim().min(1, "Enter a note.").max(10000),
});
export function CustomerNotes({
  id,
  canManage,
}: {
  id: number;
  canManage: boolean;
}) {
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<number | null>(null);
  const path = `/customers/${id}/notes`;
  const query = useQuery({
    queryKey: ["crm", "customers", id, "notes", page],
    queryFn: () => list<Note>(path, { page, per_page: 10 }),
  });
  const form = useForm<z.infer<typeof schema>>({
    resolver: zodResolver(schema),
    defaultValues: { body: "" },
  });
  const save = useCrmWrite<{ body: string }>(
    (values) =>
      write(
        editing ? `${path}/${editing}` : path,
        editing ? "PUT" : "POST",
        values,
      ),
    "Note saved",
    () => {
      form.reset({ body: "" });
      setEditing(null);
      setPage(1);
    },
  );
  const remove = useCrmWrite<number>(
    (noteId) => write(`${path}/${noteId}`, "DELETE"),
    "Note deleted",
    () => {
      setEditing(null);
      form.reset({ body: "" });
    },
  );
  return (
    <section className="overflow-hidden rounded-xl border border-border bg-card">
      <div className="p-6">
        <h2 className="mb-5 font-semibold">Customer notes</h2>
        {canManage && (
          <form
            className="mb-6 space-y-3"
            onSubmit={form.handleSubmit((values) => save.mutate(values))}
          >
            <Field
              label={editing ? "Edit note" : "Add a customer note"}
              error={form.formState.errors.body?.message}
            >
              <textarea
                rows={3}
                className={controlClass}
                {...form.register("body")}
              />
            </Field>
            {save.error && (
              <p role="alert" className="text-sm text-red-600">
                {save.error.message}
              </p>
            )}
            <div className="flex gap-2">
              <Button size="sm" disabled={save.isPending}>
                {save.isPending
                  ? "Saving…"
                  : editing
                    ? "Update note"
                    : "Add note"}
              </Button>
              {editing && (
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => {
                    setEditing(null);
                    form.reset({ body: "" });
                  }}
                >
                  Cancel edit
                </Button>
              )}
            </div>
          </form>
        )}
        <CrmState
          pending={query.isPending}
          error={query.error}
          retry={() => query.refetch()}
        />
        <ul className="space-y-4">
          {!query.error &&
            query.data?.data.map((note) => (
              <li
                key={note.id}
                className="rounded-lg border border-border bg-background p-4"
              >
                <p className="whitespace-pre-wrap break-words text-sm">
                  {note.body}
                </p>
                <p className="mt-3 text-xs text-muted-foreground">
                  {note.user.name} · <DateText value={note.updated_at} />
                </p>
                {canManage && (
                  <div className="mt-3 flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        setEditing(note.id);
                        form.reset({ body: note.body });
                      }}
                    >
                      Edit note
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={remove.isPending}
                      onClick={() => {
                        if (
                          window.confirm(
                            "Delete this customer note? This cannot be undone.",
                          )
                        )
                          remove.mutate(note.id);
                      }}
                    >
                      Delete note
                    </Button>
                  </div>
                )}
              </li>
            ))}
        </ul>
        {query.data?.data.length === 0 && (
          <p className="text-sm text-muted-foreground">
            No customer notes yet.
          </p>
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
