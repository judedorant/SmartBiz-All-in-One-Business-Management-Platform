"use client";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { Field, Input, controlClass } from "@/components/forms/field";
import { Button } from "@/components/ui/button";
import { AssigneePicker, CustomerPicker } from "@/features/crm/pickers";
import { useCrmWrite } from "@/features/crm/hooks";
import { write } from "@/features/crm/api";
import { ApiError } from "@/lib/api";
import { localDateTime } from "@/lib/date-time";
import { useUser } from "@/hooks/use-user";
import { LeadPicker } from "./lead-picker";
import { taskSchema, taskPayload, type TaskValues } from "./schema";
import { statuses, priorities, label, type Task } from "./types";
export function TaskForm({ task }: { task?: Task }) {
  const { data: user } = useUser();
  const router = useRouter();
  const canAssign = !!user?.permissions.includes("tasks.assign");
  const [owner, setOwner] = useState(
    task?.assignee
      ? [task.assignee]
      : user
        ? [{ id: user.id, name: user.name }]
        : [],
  );
  const [customer, setCustomer] = useState(task?.customer ?? null);
  const [lead, setLead] = useState(task?.lead ?? null);
  const {
    register,
    handleSubmit,
    setValue,
    setError,
    formState: { errors, dirtyFields },
  } = useForm<TaskValues>({
    resolver: zodResolver(taskSchema),
    defaultValues: {
      title: task?.title ?? "",
      description: task?.description ?? "",
      status: task?.status ?? "pending",
      priority: task?.priority ?? "medium",
      due_date: task?.due_date ? localDateTime(task.due_date) : "",
      assigned_to: task?.assigned_to ?? user?.id ?? 0,
      customer_id: task?.customer_id ?? null,
      lead_id: task?.lead_id ?? null,
    },
  });
  const save = useCrmWrite(
    async (values: TaskValues) => {
      try {
        return await write<Task>(
          task ? `/tasks/${task.id}` : "/tasks",
          task ? "PUT" : "POST",
          taskPayload(
            values,
            canAssign,
            task?.due_date ?? undefined,
            task?.version,
            dirtyFields,
          ),
        );
      } catch (error) {
        if (error instanceof ApiError)
          for (const [key, messages] of Object.entries(error.errors))
            setError(key as keyof TaskValues, { message: messages[0] });
        throw error;
      }
    },
    task ? "Task updated" : "Task created",
    (data) => router.push(`/tasks/${data.id}`),
  );
  return (
    <form
      onSubmit={handleSubmit((values) => {
        if (
          values.status === "cancelled" &&
          task?.status !== "cancelled" &&
          !window.confirm("Cancel this task?")
        )
          return;
        save.mutate(values);
      })}
      className="max-w-3xl space-y-5 rounded-xl border border-border bg-card p-6"
    >
      <Field label="Title" error={errors.title?.message}>
        <Input {...register("title")} maxLength={200} />
      </Field>
      <Field label="Description" error={errors.description?.message}>
        <textarea
          className={controlClass}
          rows={4}
          {...register("description")}
        />
      </Field>
      <div className="grid gap-5 sm:grid-cols-2">
        <Field label="Status" error={errors.status?.message}>
          <select className={controlClass} {...register("status")}>
            {statuses.map((s) => (
              <option key={s} value={s}>
                {label(s)}
              </option>
            ))}
          </select>
        </Field>
        <Field label="Priority" error={errors.priority?.message}>
          <select className={controlClass} {...register("priority")}>
            {priorities.map((p) => (
              <option key={p}>{p}</option>
            ))}
          </select>
        </Field>
      </div>
      <Field
        label="Due date (your local time, optional)"
        error={errors.due_date?.message}
      >
        <Input type="datetime-local" {...register("due_date")} />
      </Field>
      {canAssign && (
        <>
          <AssigneePicker
            endpoint="/tasks/staff"
            label="Task owner"
            multiple={false}
            value={owner}
            onChange={(people) => {
              setOwner(people);
              setValue("assigned_to", people[0]?.id ?? 0, {
                shouldDirty: true,
                shouldValidate: true,
              });
            }}
          />
          {errors.assigned_to && (
            <p role="alert" className="text-sm text-red-600">
              {errors.assigned_to.message}
            </p>
          )}
          <CustomerPicker
            value={customer}
            onChange={(v) => {
              setCustomer(v);
              setValue("customer_id", v?.id ?? null, { shouldDirty: true });
            }}
          />
          {errors.customer_id && (
            <p role="alert">{errors.customer_id.message}</p>
          )}
          <LeadPicker
            value={lead}
            onChange={(v) => {
              setLead(v);
              setValue("lead_id", v?.id ?? null, { shouldDirty: true });
            }}
          />
          {errors.lead_id && <p role="alert">{errors.lead_id.message}</p>}
          <p className="text-xs text-muted-foreground">
            Linked leads and customers must agree. A lead’s customer is linked
            automatically if no customer is selected.
          </p>
        </>
      )}
      {save.error && (
        <p role="alert" className="text-sm text-red-600">
          {save.error.message}
        </p>
      )}
      <Button disabled={save.isPending}>
        {save.isPending ? "Saving…" : task ? "Save task" : "Create task"}
      </Button>
    </form>
  );
}
