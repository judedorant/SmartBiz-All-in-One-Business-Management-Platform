import { z } from "zod";
import { triggers, actionTypes, type Action } from "./types";
const text = z.string();
export const emptyAction = {
  type: "create_task" as (typeof actionTypes)[number],
  title: "",
  assigned_to: null as number | null,
  priority: "medium",
  due_in_hours: 24,
  recipient: "assignee",
  to: "",
  subject: "",
  body: "",
  transport: "mail",
  integration_account_id: null as number | null,
  spreadsheet_id: "",
  range: "Sheet1!A:G",
  webhook_endpoint_id: null as number | null,
  user_id: null as number | null,
  calendar_id: "primary",
};
const action = z
  .object({
    type: z.enum(actionTypes),
    title: text,
    assigned_to: z.number().nullable(),
    priority: text,
    due_in_hours: z.number(),
    recipient: text,
    to: text,
    subject: text,
    body: text,
    transport: text,
    integration_account_id: z.number().nullable(),
    spreadsheet_id: text,
    range: text,
    webhook_endpoint_id: z.number().nullable(),
    user_id: z.number().nullable(),
    calendar_id: text,
  })
  .superRefine((v, ctx) => {
    const error = (path: string, message: string) =>
      ctx.addIssue({ code: "custom", path: [path], message });
    if (v.type === "create_task") {
      if (!v.title.trim() || v.title.length > 200)
        error("title", "Enter a task title (up to 200 characters).");
      if (
        !Number.isInteger(v.due_in_hours) ||
        v.due_in_hours < 0 ||
        v.due_in_hours > 8760
      )
        error("due_in_hours", "Choose 0–8760 hours.");
    }
    if (v.type === "send_email") {
      if (!v.subject.trim() || v.subject.length > 200)
        error("subject", "Enter an email subject.");
      if (!v.body.trim() || v.body.length > 10000)
        error("body", "Enter an email body.");
      if (v.recipient === "fixed" && !z.email().safeParse(v.to).success)
        error("to", "Enter a valid email.");
    }
    if (
      (v.type.startsWith("google_") ||
        (v.type === "send_email" && v.transport === "gmail")) &&
      !v.integration_account_id
    )
      error("integration_account_id", "Choose a connected Google account.");
    if (v.type === "google_sheets_row") {
      if (!/^[a-zA-Z0-9_-]{10,200}$/.test(v.spreadsheet_id))
        error("spreadsheet_id", "Enter a spreadsheet ID.");
      if (!v.range.trim()) error("range", "Enter a sheet range.");
    }
    if (v.type === "webhook" && !v.webhook_endpoint_id)
      error("webhook_endpoint_id", "Choose an outbound webhook.");
    if (v.type === "google_calendar_event" && !v.calendar_id.trim())
      error("calendar_id", "Enter a calendar ID.");
  });
export const workflowSchema = z
  .object({
    name: z.string().trim().min(1, "Name this workflow.").max(150),
    trigger_type: z.enum(triggers),
    is_active: z.boolean(),
    lead_status: z.string(),
    actions: z.array(action).min(1).max(8),
  })
  .superRefine((v, ctx) => {
    if (
      v.actions.some((a) => a.type === "google_calendar_event") &&
      ![
        "appointment_created",
        "appointment_updated",
        "appointment_approaching",
      ].includes(v.trigger_type)
    )
      ctx.addIssue({
        code: "custom",
        path: ["trigger_type"],
        message: "Google Calendar actions require an appointment trigger.",
      });
  });
export type WorkflowValues = z.infer<typeof workflowSchema>;
export function actionPayload(v: WorkflowValues["actions"][number]): Action {
  switch (v.type) {
    case "create_task":
      return {
        type: v.type,
        title: v.title,
        priority: v.priority,
        due_in_hours: v.due_in_hours,
        ...(v.assigned_to ? { assigned_to: v.assigned_to } : {}),
      };
    case "send_notification":
      return { type: v.type, ...(v.user_id ? { user_id: v.user_id } : {}) };
    case "send_email":
      return {
        type: v.type,
        recipient: v.recipient,
        subject: v.subject,
        body: v.body,
        transport: v.transport,
        ...(v.recipient === "fixed" ? { to: v.to } : {}),
        ...(v.transport === "gmail" && v.integration_account_id
          ? { integration_account_id: v.integration_account_id }
          : {}),
      };
    case "google_sheets_row":
      return {
        type: v.type,
        integration_account_id: v.integration_account_id!,
        spreadsheet_id: v.spreadsheet_id,
        range: v.range,
      };
    case "google_calendar_event":
      return {
        type: v.type,
        integration_account_id: v.integration_account_id!,
        calendar_id: v.calendar_id,
      };
    case "webhook":
      return { type: v.type, webhook_endpoint_id: v.webhook_endpoint_id! };
  }
}
