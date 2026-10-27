"use client";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { useUser } from "@/hooks/use-user";
import { list, write } from "@/features/crm/api";
import { useCrmWrite } from "@/features/crm/hooks";
import { CrmState, DateText } from "@/features/crm/components";
import { Field, controlClass } from "@/components/forms/field";
import { Button } from "@/components/ui/button";
import { Pagination } from "@/components/tables/pagination";
import type { TaskComment } from "./types";
export function TaskComments({ taskId }: { taskId: number }) {
  const { data: user } = useUser();
  const [page, setPage] = useState(1);
  const [text, setText] = useState("");
  const [editing, setEditing] = useState<TaskComment | null>(null);
  const query = useQuery({
    queryKey: ["crm", "task-comments", taskId, page],
    queryFn: () =>
      list<TaskComment>(`/tasks/${taskId}/comments`, { page, per_page: 10 }),
  });
  const save = useCrmWrite(
    () =>
      write(
        `/tasks/${taskId}/comments${editing ? `/${editing.id}` : ""}`,
        editing ? "PUT" : "POST",
        {
          comment: text.trim(),
          ...(editing ? { version: editing.version } : {}),
        },
      ),
    "Comment saved",
    () => {
      setText("");
      setEditing(null);
      setPage(1);
    },
  );
  const remove = useCrmWrite(
    (comment: TaskComment) =>
      write(`/tasks/${taskId}/comments/${comment.id}`, "DELETE", {
        version: comment.version,
      }),
    "Comment deleted",
  );
  return (
    <section className="mt-6 rounded-xl border border-border bg-card p-6">
      <h2 className="mb-5 text-lg font-semibold">Comments</h2>
      <form
        className="space-y-3"
        onSubmit={(e) => {
          e.preventDefault();
          if (text.trim()) save.mutate();
        }}
      >
        <Field label={editing ? "Edit comment" : "Add a comment"}>
          <textarea
            required
            maxLength={5000}
            rows={3}
            className={controlClass}
            value={text}
            onChange={(e) => setText(e.target.value)}
          />
        </Field>
        <div className="flex gap-2">
          <Button disabled={save.isPending || !text.trim()}>
            {save.isPending
              ? "Saving…"
              : editing
                ? "Save comment"
                : "Add comment"}
          </Button>
          {editing && (
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                setEditing(null);
                setText("");
              }}
            >
              Cancel edit
            </Button>
          )}
        </div>
        {save.error && <p role="alert">{save.error.message}</p>}
      </form>
      <CrmState
        pending={query.isPending}
        error={query.error}
        retry={() => query.refetch()}
      />
      {query.data?.data.length === 0 && (
        <p className="mt-5 text-sm text-muted-foreground">No comments yet.</p>
      )}
      <div className="mt-5 divide-y divide-border">
        {query.data?.data.map((comment) => (
          <article key={comment.id} className="py-4">
            <div className="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground">
              <span>
                {comment.user.name} · <DateText value={comment.created_at} />
                {comment.updated_at !== comment.created_at && " · edited"}
              </span>
              {(user?.permissions.includes("tasks.assign") ||
                user?.id === comment.user.id) && (
                <div className="flex gap-3">
                  <button
                    type="button"
                    className="text-primary"
                    onClick={() => {
                      setEditing(comment);
                      setText(comment.comment);
                    }}
                  >
                    Edit comment
                  </button>
                  <button
                    type="button"
                    className="text-red-600"
                    disabled={remove.isPending}
                    onClick={() => {
                      if (window.confirm("Delete this comment?"))
                        remove.mutate(comment);
                    }}
                  >
                    Delete comment
                  </button>
                </div>
              )}
            </div>
            <p className="mt-2 whitespace-pre-wrap break-words text-sm">
              {comment.comment}
            </p>
          </article>
        ))}
      </div>
      {remove.error && <p role="alert">{remove.error.message}</p>}
      {query.data && (
        <Pagination
          page={page}
          lastPage={query.data.meta.last_page}
          total={query.data.meta.total}
          onChange={setPage}
        />
      )}
    </section>
  );
}
