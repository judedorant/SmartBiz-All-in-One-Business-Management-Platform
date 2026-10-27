export const triggers = [
  "customer_created",
  "lead_created",
  "lead_status_changed",
  "appointment_created",
  "appointment_updated",
  "appointment_approaching",
  "task_overdue",
  "webhook_received",
] as const;
export const actionTypes = [
  "create_task",
  "send_email",
  "send_notification",
  "google_sheets_row",
  "webhook",
  "google_calendar_event",
] as const;
export type ActionType = (typeof actionTypes)[number];
export type Action = {
  type: ActionType;
  title?: string;
  assigned_to?: number;
  priority?: string;
  due_in_hours?: number;
  recipient?: string;
  to?: string;
  subject?: string;
  body?: string;
  transport?: string;
  integration_account_id?: number;
  spreadsheet_id?: string;
  range?: string;
  webhook_endpoint_id?: number;
  user_id?: number;
  calendar_id?: string;
};
export type Workflow = {
  id: number;
  name: string;
  trigger_type: (typeof triggers)[number];
  is_active: boolean;
  version: number;
  configuration: { actions: Action[]; lead_status?: string | null };
  created_at: string;
  updated_at: string;
};
export type Execution = {
  id: number;
  workflow_id: number;
  workflow_version: number;
  execution_status: string;
  error_message: string | null;
  executed_at: string | null;
  created_at: string;
  steps: {
    id: number;
    action_type: string;
    action_index: number;
    status: string;
    attempts: number;
    retryable: boolean;
    error_code: string | null;
  }[];
};
export type Account = {
  id: number;
  provider: string;
  display_email: string | null;
  status: string;
  updated_at: string;
};
export type Integrations = {
  google_configured: boolean;
  mail_mode: "local" | "provider";
  providers: string[];
  accounts: Account[];
};
export type Endpoint = {
  id: number;
  name: string;
  direction: "inbound" | "outbound";
  endpoint_url: string | null;
  receive_url: string | null;
  is_active: boolean;
  version: number;
  updated_at: string;
};
export const pretty = (value: string) => value.replaceAll("_", " ");
