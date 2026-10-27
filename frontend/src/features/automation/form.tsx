"use client";
import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import {
  useForm,
  useFieldArray,
  useWatch,
  type UseFormReturn,
} from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { Field, Input, controlClass } from "@/components/forms/field";
import { Button } from "@/components/ui/button";
import { Pagination } from "@/components/tables/pagination";
import { AssigneePicker } from "@/features/crm/pickers";
import { list, get, write } from "@/features/crm/api";
import { ApiError } from "@/lib/api";
import { useAutomationWrite } from "./hooks";
import {
  workflowSchema,
  emptyAction,
  actionPayload,
  type WorkflowValues,
} from "./schema";
import {
  actionTypes,
  triggers,
  pretty,
  type Workflow,
  type Integrations,
  type Endpoint,
} from "./types";
function WebhookPicker({
  value,
  onChange,
}: {
  value: number | null;
  onChange: (id: number | null) => void;
}) {
  const [page, setPage] = useState(1);
  const query = useQuery({
    queryKey: ["automation", "endpoint-picker", page],
    queryFn: () => list<Endpoint>("/webhooks", { page, per_page: 10 }),
  });
  return (
    <div className="space-y-2">
      <select
        aria-label="Outbound webhook"
        className={controlClass}
        value={value ?? ""}
        onChange={(e) =>
          onChange(e.target.value ? Number(e.target.value) : null)
        }
      >
        <option value="">Choose a webhook</option>
        {value && !query.data?.data.some((e) => e.id === value) && (
          <option value={value}>Endpoint #{value}</option>
        )}
        {query.data?.data
          .filter((e) => e.direction === "outbound" && e.is_active)
          .map((e) => (
            <option key={e.id} value={e.id}>
              {e.name}
            </option>
          ))}
      </select>
      {query.isPending && <p role="status">Loading endpoints…</p>}
      {query.error && <p role="alert">{query.error.message}</p>}
      {query.data && (
        <Pagination
          page={page}
          lastPage={query.data.meta.last_page}
          total={query.data.meta.total}
          onChange={setPage}
        />
      )}
    </div>
  );
}
function ActionFields({
  index,
  form,
  accounts,
}: {
  index: number;
  form: UseFormReturn<WorkflowValues>;
  accounts: Integrations["accounts"];
}) {
  const {
    register,
    control,
    setValue,
    formState: { errors },
  } = form;
  const value = useWatch({ control, name: `actions.${index}` });
  const error = errors.actions?.[index];
  const provider =
    value.type === "google_sheets_row"
      ? "google_sheets"
      : value.type === "google_calendar_event"
        ? "google_calendar"
        : "google_gmail";
  const [names, setNames] = useState<Record<number, string>>({});
  const ownerKey =
    value.type === "send_notification" ? "user_id" : "assigned_to";
  const id = value[ownerKey];
  return (
    <div className="space-y-4">
      <Field label="Action">
        <select className={controlClass} {...register(`actions.${index}.type`)}>
          {actionTypes.map((type) => (
            <option key={type} value={type}>
              {pretty(type)}
            </option>
          ))}
        </select>
      </Field>
      {value.type === "create_task" && (
        <>
          <Field label="Task title" error={error?.title?.message}>
            <Input {...register(`actions.${index}.title`)} maxLength={200} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Priority">
              <select
                className={controlClass}
                {...register(`actions.${index}.priority`)}
              >
                {["low", "medium", "high", "urgent"].map((p) => (
                  <option key={p}>{p}</option>
                ))}
              </select>
            </Field>
            <Field label="Due in hours" error={error?.due_in_hours?.message}>
              <Input
                type="number"
                min={0}
                max={8760}
                {...register(`actions.${index}.due_in_hours`, {
                  valueAsNumber: true,
                })}
              />
            </Field>
          </div>
        </>
      )}
      {["create_task", "send_notification"].includes(value.type) && (
        <>
          <AssigneePicker
            label={
              value.type === "create_task"
                ? "Task owner override (optional)"
                : "Notification recipient override (optional)"
            }
            endpoint="/tasks/staff"
            multiple={false}
            value={id ? [{ id, name: names[id] ?? `User #${id}` }] : []}
            onChange={(people) => {
              const person = people[0];
              if (person)
                setNames((previous) => ({
                  ...previous,
                  [person.id]: person.name,
                }));
              setValue(`actions.${index}.${ownerKey}`, person?.id ?? null, {
                shouldDirty: true,
              });
            }}
          />
          <p className="text-xs text-muted-foreground">
            Defaults to the current record owner, then the workflow creator.
          </p>
        </>
      )}
      {value.type === "send_email" && (
        <>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Email transport">
              <select
                className={controlClass}
                {...register(`actions.${index}.transport`)}
              >
                <option value="mail">Configured mail provider</option>
                <option value="gmail">Connected Gmail</option>
              </select>
            </Field>
            <Field label="Recipient">
              <select
                className={controlClass}
                {...register(`actions.${index}.recipient`)}
              >
                <option value="assignee">Current record owner</option>
                <option value="customer">Linked customer</option>
                <option value="fixed">Specific email address</option>
              </select>
            </Field>
          </div>
          {value.recipient === "fixed" && (
            <Field label="Recipient email" error={error?.to?.message}>
              <Input type="email" {...register(`actions.${index}.to`)} />
            </Field>
          )}
          <Field label="Email subject" error={error?.subject?.message}>
            <Input {...register(`actions.${index}.subject`)} />
          </Field>
          <Field label="Email body (plain text)" error={error?.body?.message}>
            <textarea
              rows={4}
              className={controlClass}
              {...register(`actions.${index}.body`)}
            />
          </Field>
        </>
      )}
      {(value.type.startsWith("google_") ||
        (value.type === "send_email" && value.transport === "gmail")) && (
        <Field
          label="Google account"
          error={error?.integration_account_id?.message}
        >
          <select
            className={controlClass}
            value={value.integration_account_id ?? ""}
            onChange={(e) =>
              setValue(
                `actions.${index}.integration_account_id`,
                e.target.value ? Number(e.target.value) : null,
                { shouldDirty: true },
              )
            }
          >
            <option value="">Choose a connected account</option>
            {accounts
              .filter(
                (a) => a.provider === provider && a.status === "connected",
              )
              .map((a) => (
                <option key={a.id} value={a.id}>
                  {a.display_email ?? `Account #${a.id}`}
                </option>
              ))}
          </select>
        </Field>
      )}
      {value.type === "google_sheets_row" && (
        <>
          <Field label="Spreadsheet ID" error={error?.spreadsheet_id?.message}>
            <Input {...register(`actions.${index}.spreadsheet_id`)} />
          </Field>
          <Field label="Sheet range" error={error?.range?.message}>
            <Input {...register(`actions.${index}.range`)} />
          </Field>
          <p className="text-xs text-muted-foreground">
            Appends event key, trigger, record type, ID, title, status and event
            time as plain values.
          </p>
        </>
      )}
      {value.type === "google_calendar_event" && (
        <Field label="Calendar ID" error={error?.calendar_id?.message}>
          <Input {...register(`actions.${index}.calendar_id`)} />
        </Field>
      )}
      {value.type === "webhook" && (
        <>
          <WebhookPicker
            value={value.webhook_endpoint_id}
            onChange={(id) =>
              setValue(`actions.${index}.webhook_endpoint_id`, id, {
                shouldDirty: true,
              })
            }
          />
          {error?.webhook_endpoint_id && (
            <p role="alert">{error.webhook_endpoint_id.message}</p>
          )}
        </>
      )}
    </div>
  );
}
export function WorkflowForm({
  workflow,
  onDone,
}: {
  workflow?: Workflow;
  onDone: () => void;
}) {
  const accounts = useQuery({
    queryKey: ["automation", "integrations"],
    queryFn: () => get<Integrations>("/integrations"),
  });
  const form = useForm<WorkflowValues>({
    resolver: zodResolver(workflowSchema),
    defaultValues: {
      name: workflow?.name ?? "",
      trigger_type: workflow?.trigger_type ?? "lead_created",
      is_active: workflow?.is_active ?? false,
      lead_status: workflow?.configuration.lead_status ?? "",
      actions: workflow?.configuration.actions.map((a) => ({
        ...emptyAction,
        ...a,
      })) ?? [{ ...emptyAction }],
    },
  });
  const {
    register,
    handleSubmit,
    control,
    setError,
    formState: { errors },
  } = form;
  const { fields, append, remove, move } = useFieldArray({
    control,
    name: "actions",
  });
  const trigger = useWatch({ control, name: "trigger_type" });
  const save = useAutomationWrite(
    async (values: WorkflowValues) => {
      try {
        return await write(
          workflow ? `/automations/${workflow.id}` : "/automations",
          workflow ? "PUT" : "POST",
          {
            name: values.name,
            trigger_type: values.trigger_type,
            is_active: values.is_active,
            ...(workflow ? { version: workflow.version } : {}),
            configuration: {
              actions: values.actions.map(actionPayload),
              ...(values.trigger_type === "lead_status_changed" &&
              values.lead_status
                ? { lead_status: values.lead_status }
                : {}),
            },
          },
        );
      } catch (e) {
        if (e instanceof ApiError) {
          for (const [key, messages] of Object.entries(e.errors)) {
            if (key === "name" || key === "trigger_type")
              setError(key, { message: messages[0] });
          }
        }
        throw e;
      }
    },
    workflow ? "Workflow updated" : "Workflow created",
    onDone,
  );
  return (
    <form
      onSubmit={handleSubmit((v) => save.mutate(v))}
      className="space-y-5 rounded-xl border border-border bg-card p-6"
    >
      <h2 className="text-xl font-semibold">
        {workflow ? "Edit workflow" : "New workflow"}
      </h2>
      <Field label="Workflow name" error={errors.name?.message}>
        <Input {...register("name")} maxLength={150} />
      </Field>
      <Field label="When this happens" error={errors.trigger_type?.message}>
        <select className={controlClass} {...register("trigger_type")}>
          {triggers.map((t) => (
            <option key={t} value={t}>
              {pretty(t)}
            </option>
          ))}
        </select>
      </Field>
      {trigger === "lead_status_changed" && (
        <Field label="Only when entering this stage">
          <select className={controlClass} {...register("lead_status")}>
            <option value="">Any stage</option>
            {[
              "new",
              "contacted",
              "qualified",
              "proposal",
              "negotiation",
              "won",
              "lost",
            ].map((s) => (
              <option key={s}>{s}</option>
            ))}
          </select>
        </Field>
      )}
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" {...register("is_active")} />
        Enable workflow
      </label>
      <p className="text-xs leading-5 text-muted-foreground">
        Actions run in order. Text supports{" "}
        {"{{id}}, {{title}}, {{status}}, {{trigger}}"}. Scheduled triggers run
        once per record version. External outcomes marked uncertain require
        review before any new delivery.
      </p>
      {accounts.data?.mail_mode === "local" && (
        <p className="rounded-lg bg-muted p-3 text-sm">
          Configured email currently writes to local logs. External inbox
          delivery needs a mail provider or connected Gmail account.
        </p>
      )}
      {accounts.error && <p role="alert">{accounts.error.message}</p>}
      {fields.map((field, index) => (
        <fieldset
          className="space-y-4 rounded-xl border border-border p-4"
          key={field.id}
        >
          <legend className="px-2 text-sm font-semibold">
            Step {index + 1}
          </legend>
          <ActionFields
            index={index}
            form={form}
            accounts={accounts.data?.accounts ?? []}
          />
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              size="sm"
              variant="ghost"
              disabled={index === 0}
              onClick={() => move(index, index - 1)}
            >
              Move up
            </Button>
            <Button
              type="button"
              size="sm"
              variant="ghost"
              disabled={index === fields.length - 1}
              onClick={() => move(index, index + 1)}
            >
              Move down
            </Button>
            <Button
              type="button"
              size="sm"
              variant="ghost"
              disabled={fields.length === 1}
              onClick={() => remove(index)}
            >
              Remove step
            </Button>
          </div>
        </fieldset>
      ))}
      <Button
        type="button"
        variant="outline"
        disabled={fields.length >= 8}
        onClick={() => append({ ...emptyAction })}
      >
        Add action
      </Button>
      {save.error && (
        <div role="alert" className="text-sm text-red-600">
          {save.error.message}
          {save.error instanceof ApiError &&
            Object.entries(save.error.errors).map(([key, messages]) => (
              <p key={key}>{messages.join(" ")}</p>
            ))}
        </div>
      )}
      <div className="flex gap-3">
        <Button disabled={save.isPending}>
          {save.isPending ? "Saving…" : "Save workflow"}
        </Button>
        <Button type="button" variant="outline" onClick={onDone}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
