"use client";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useUser } from "@/hooks/use-user";
import { get, write } from "@/features/crm/api";
import { useCrmWrite } from "@/features/crm/hooks";
import {
  CrmState,
  PageHeading,
  Badge,
  DateText,
} from "@/features/crm/components";
import { Button } from "@/components/ui/button";
import { controlClass } from "@/components/forms/field";
import { TaskForm } from "./form";
import { TaskComments } from "./comments";
import { statuses, label, type Task } from "./types";
export function TaskDetail({
  id,
  edit = false,
}: {
  id: string;
  edit?: boolean;
}) {
  const { data: user } = useUser();
  const router = useRouter();
  const query = useQuery({
    queryKey: ["crm", "task", id],
    queryFn: () => get<Task>(`/tasks/${id}`),
  });
  const change = useCrmWrite(
    (status: string) =>
      write(`/tasks/${id}/status`, "PATCH", {
        status,
        version: query.data?.version,
      }),
    "Task status updated",
  );
  const archive = useCrmWrite(
    () => write(`/tasks/${id}`, "DELETE", { version: query.data?.version }),
    "Task archived",
    () => router.push("/tasks"),
  );
  if (!query.data)
    return (
      <CrmState
        pending={query.isPending}
        error={query.error}
        retry={() => query.refetch()}
      />
    );
  const task = query.data;
  if (edit)
    return (
      <>
        <PageHeading
          title="Edit task"
          description="Update the work and its due date."
          back={`/tasks/${id}`}
        />
        <TaskForm key={task.version} task={task} />
      </>
    );
  return (
    <>
      <PageHeading
        title={task.title}
        description={`Created by ${task.creator?.name ?? "Unavailable"}`}
        back="/tasks"
        action={
          <div className="flex gap-2">
            <Button asChild variant="outline">
              <Link href={`/tasks/${id}/edit`}>Edit task</Link>
            </Button>
            {user?.permissions.includes("tasks.assign") && (
              <Button
                variant="outline"
                className="text-red-600"
                disabled={archive.isPending}
                onClick={() => {
                  if (
                    window.confirm(
                      "Archive this task? It will be removed from task lists.",
                    )
                  )
                    archive.mutate();
                }}
              >
                Archive task
              </Button>
            )}
          </div>
        }
      />
      {query.error && <p role="alert">{query.error.message}</p>}
      <div className="rounded-xl border border-border bg-card p-6">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div className="flex gap-2">
            <Badge>{label(task.status)}</Badge>
            <Badge>{task.priority}</Badge>
            {task.is_overdue && (
              <span className="text-sm font-semibold text-red-600">
                Overdue
              </span>
            )}
          </div>
          <label className="flex items-center gap-3 text-sm">
            Change status
            <select
              className={controlClass}
              value={task.status}
              disabled={change.isPending}
              onChange={(e) => {
                if (
                  e.target.value === "cancelled" &&
                  !window.confirm("Cancel this task?")
                )
                  return;
                change.mutate(e.target.value);
              }}
            >
              {statuses.map((s) => (
                <option key={s} value={s}>
                  {label(s)}
                </option>
              ))}
            </select>
          </label>
        </div>
        <p className="my-6 whitespace-pre-wrap break-words text-sm">
          {task.description || "No description provided."}
        </p>
        <dl className="grid gap-5 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-muted-foreground">Owner</dt>
            <dd>{task.assignee?.name ?? "Unavailable"}</dd>
          </div>
          <div>
            <dt className="text-muted-foreground">Due date</dt>
            <dd>
              <DateText value={task.due_date} />
            </dd>
          </div>
          <div>
            <dt className="text-muted-foreground">Completed</dt>
            <dd>
              <DateText value={task.completed_at} />
            </dd>
          </div>
          <div>
            <dt className="text-muted-foreground">Linked records</dt>
            <dd className="flex flex-col gap-1">
              {task.customer && (
                <Link
                  className="text-primary"
                  href={`/customers/${task.customer.id}`}
                >
                  {task.customer.first_name} {task.customer.last_name}
                </Link>
              )}
              {task.lead && (
                <Link className="text-primary" href={`/leads/${task.lead.id}`}>
                  {task.lead.title}
                </Link>
              )}
              {!task.customer && !task.lead && "No accessible linked records"}
            </dd>
          </div>
        </dl>
        {(change.error || archive.error) && (
          <p className="mt-4 text-red-600" role="alert">
            {change.error?.message ?? archive.error?.message}
          </p>
        )}
      </div>
      <TaskComments taskId={task.id} />
    </>
  );
}
