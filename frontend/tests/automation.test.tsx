import React from "react";
import { beforeEach, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { WorkflowForm } from "@/features/automation/form";
import {
  AutomationWorkspace,
  ExecutionLogs,
} from "@/features/automation/workspace";
import {
  IntegrationWorkspace,
  EndpointForm,
  validGoogleUrl,
} from "@/features/automation/integrations";
import {
  actionPayload,
  emptyAction,
  workflowSchema,
} from "@/features/automation/schema";
import type { Workflow, Endpoint } from "@/features/automation/types";
import { get, list, write } from "@/features/crm/api";
import { page, person } from "./crm-fixtures";
const { profile } = vi.hoisted(() => ({
  profile: {
    id: 1,
    name: "Admin",
    permissions: ["automations.manage", "integrations.manage"],
  },
}));
vi.mock("@/hooks/use-user", () => ({ useUser: () => ({ data: profile }) }));
vi.mock("@/features/crm/api", () => ({
  get: vi.fn(),
  list: vi.fn(),
  write: vi.fn(),
}));
vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
const workflow: Workflow = {
  id: 8,
  name: "Lead follow-up",
  trigger_type: "lead_created",
  is_active: true,
  version: 3,
  configuration: {
    actions: [
      {
        type: "create_task",
        title: "Follow up {{title}}",
        priority: "high",
        due_in_hours: 24,
      },
    ],
  },
  created_at: "2026-10-27T12:00:00Z",
  updated_at: "2026-10-27T12:00:00Z",
};
const endpoint: Endpoint = {
  id: 9,
  name: "CRM receiver",
  direction: "inbound",
  endpoint_url: null,
  receive_url: "http://localhost:8000/api/v1/webhooks/9/receive",
  is_active: true,
  version: 2,
  updated_at: workflow.created_at,
};
function setup(node: React.ReactNode) {
  render(
    <QueryClientProvider
      client={
        new QueryClient({
          defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
          },
        })
      }
    >
      {node}
    </QueryClientProvider>,
  );
}
beforeEach(() => {
  vi.clearAllMocks();
  profile.permissions = ["automations.manage", "integrations.manage"];
  vi.mocked(get).mockResolvedValue({
    google_configured: false,
    providers: ["google_gmail", "google_sheets", "google_calendar"],
    accounts: [],
  });
  vi.mocked(list).mockImplementation(async (path) =>
    path === "/tasks/staff" ? page([person]) : page([]),
  );
  vi.mocked(write).mockResolvedValue(workflow);
});
it("requires workflow and action titles before saving", async () => {
  setup(<WorkflowForm onDone={vi.fn()} />);
  await userEvent.click(screen.getByRole("button", { name: "Save workflow" }));
  expect(await screen.findByText("Name this workflow.")).toBeInTheDocument();
  expect(
    screen.getByText("Enter a task title (up to 200 characters)."),
  ).toBeInTheDocument();
  expect(write).not.toHaveBeenCalled();
});
it("sends the workflow revision with only allowlisted action settings", async () => {
  const done = vi.fn();
  setup(<WorkflowForm workflow={workflow} onDone={done} />);
  await userEvent.click(screen.getByRole("button", { name: "Save workflow" }));
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith("/automations/8", "PUT", {
      name: workflow.name,
      trigger_type: "lead_created",
      is_active: true,
      version: 3,
      configuration: workflow.configuration,
    }),
  );
  expect(done).toHaveBeenCalled();
});
it("can add and reorder steps before saving", async () => {
  setup(<WorkflowForm workflow={workflow} onDone={vi.fn()} />);
  await userEvent.click(screen.getByRole("button", { name: "Add action" }));
  const actions = screen.getAllByLabelText("Action");
  await userEvent.selectOptions(actions[1], "send_notification");
  await userEvent.click(screen.getAllByRole("button", { name: "Move up" })[1]);
  await userEvent.click(screen.getByRole("button", { name: "Save workflow" }));
  await waitFor(() => expect(write).toHaveBeenCalled());
  const body = vi.mocked(write).mock.calls[0][2] as {
    configuration: { actions: { type: string }[] };
  };
  expect(body.configuration.actions.map((a) => a.type)).toEqual([
    "send_notification",
    "create_task",
  ]);
});
it("validates connected Google accounts and appointment-only Calendar actions", () => {
  const values = {
    name: "Test",
    trigger_type: "lead_created",
    is_active: true,
    lead_status: "",
    actions: [
      {
        ...emptyAction,
        type: "google_calendar_event",
        integration_account_id: 1,
      },
    ],
  } as const;
  expect(
    workflowSchema.safeParse({ ...values, actions: [{ ...values.actions[0] }] })
      .success,
  ).toBe(false);
  expect(
    actionPayload({
      ...emptyAction,
      type: "send_email",
      transport: "mail",
      recipient: "fixed",
      to: "a@example.test",
      subject: "Hello",
      body: "Hello",
      integration_account_id: 123,
    }),
  ).not.toHaveProperty("integration_account_id");
});
it("disables workflows using the current version without resending action configuration", async () => {
  vi.mocked(list).mockResolvedValue(page([workflow]));
  vi.mocked(write).mockResolvedValue({ ...workflow, is_active: false });
  setup(<AutomationWorkspace />);
  await userEvent.click(await screen.findByRole("button", { name: "Disable" }));
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith("/automations/8/active", "PATCH", {
      version: 3,
      is_active: false,
    }),
  );
});
it("requires confirmation before archiving a workflow", async () => {
  const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);
  try {
    vi.mocked(list).mockResolvedValue(page([workflow]));
    setup(<AutomationWorkspace />);
    await userEvent.click(
      await screen.findByRole("button", { name: "Archive" }),
    );
    expect(confirm).toHaveBeenCalled();
    expect(write).not.toHaveBeenCalled();
  } finally {
    confirm.mockRestore();
  }
});
it("never offers automatic retries for uncertain delivery outcomes", async () => {
  vi.mocked(list).mockResolvedValue(
    page([
      {
        id: 1,
        workflow_id: 8,
        workflow_version: 3,
        execution_status: "uncertain",
        error_message: "mail_outcome_unknown",
        created_at: workflow.created_at,
        executed_at: workflow.created_at,
        steps: [
          {
            id: 1,
            action_index: 0,
            action_type: "send_email",
            status: "uncertain",
            attempts: 1,
            retryable: false,
          },
        ],
      },
    ]),
  );
  setup(<ExecutionLogs workflow={workflow} />);
  expect(
    await screen.findByText(/Delivery may have completed/),
  ).toBeInTheDocument();
  expect(
    screen.queryByRole("button", { name: "Retry failed step" }),
  ).not.toBeInTheDocument();
});
it("queues retries for known safe failures", async () => {
  vi.mocked(list).mockResolvedValue(
    page([
      {
        id: 4,
        workflow_id: 8,
        workflow_version: 3,
        execution_status: "failed",
        error_message: "google_rate_limited",
        created_at: workflow.created_at,
        executed_at: workflow.created_at,
        steps: [
          {
            id: 1,
            action_index: 0,
            action_type: "google_sheets_row",
            status: "failed",
            attempts: 3,
            retryable: true,
          },
        ],
      },
    ]),
  );
  setup(<ExecutionLogs workflow={workflow} />);
  await userEvent.click(
    await screen.findByRole("button", { name: "Retry failed step" }),
  );
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith("/automations/8/logs/4/retry", "POST"),
  );
});
it("withholds automation and integration tools from staff", () => {
  profile.permissions = ["tasks.manage_assigned"];
  setup(
    <>
      <AutomationWorkspace />
      <IntegrationWorkspace />
    </>,
  );
  expect(
    screen.getByText("You do not have permission to manage automation."),
  ).toBeInTheDocument();
  expect(
    screen.getByText("You do not have permission to manage integrations."),
  ).toBeInTheDocument();
  expect(list).not.toHaveBeenCalled();
  expect(get).not.toHaveBeenCalled();
});
it("explains missing OAuth configuration and disables connect controls", async () => {
  setup(<IntegrationWorkspace />);
  expect(
    await screen.findByText(/Google OAuth is not configured/),
  ).toBeInTheDocument();
  expect(screen.getByRole("button", { name: "Connect gmail" })).toBeDisabled();
  expect(screen.getByRole("button", { name: "Connect sheets" })).toBeDisabled();
  expect(screen.getByText("No Google accounts connected.")).toBeInTheDocument();
});
it("rejects an unexpected OAuth redirect destination", async () => {
  vi.mocked(get).mockResolvedValue({
    google_configured: true,
    providers: ["google_gmail"],
    accounts: [],
  });
  vi.mocked(write).mockResolvedValue({ url: "https://evil.example/steal" });
  setup(<IntegrationWorkspace />);
  await userEvent.click(
    await screen.findByRole("button", { name: "Connect gmail" }),
  );
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "Invalid Google authorization response.",
  );
  expect(
    validGoogleUrl("https://accounts.google.com/o/oauth2/v2/auth?state=abc"),
  ).toBe(true);
  expect(
    validGoogleUrl("https://accounts.google.com.evil.example/o/oauth2/v2/auth"),
  ).toBe(false);
});
it("requires a secret when creating an endpoint and hides the entered value", async () => {
  setup(<EndpointForm onDone={vi.fn()} />);
  await userEvent.type(screen.getByLabelText("Endpoint name"), "Inbound CRM");
  await userEvent.click(screen.getByRole("button", { name: "Save webhook" }));
  expect(
    await screen.findByText("Enter or generate a signing secret."),
  ).toBeInTheDocument();
  const secret = screen.getByLabelText("Signing secret");
  expect(secret).toHaveAttribute("type", "password");
  await userEvent.type(secret, "test-only-secret-abcdefghijklmnopqrstuvwxyz");
  await userEvent.click(screen.getByRole("button", { name: "Save webhook" }));
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith(
      "/webhooks",
      "POST",
      expect.objectContaining({
        name: "Inbound CRM",
        direction: "inbound",
        secret: "test-only-secret-abcdefghijklmnopqrstuvwxyz",
        endpoint_url: null,
      }),
    ),
  );
});
it("preserves a stored secret by omitting blank values on edit", async () => {
  setup(<EndpointForm endpoint={endpoint} onDone={vi.fn()} />);
  await userEvent.click(screen.getByRole("button", { name: "Save webhook" }));
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith("/webhooks/9", "PUT", {
      name: "CRM receiver",
      direction: "inbound",
      endpoint_url: null,
      is_active: true,
      version: 2,
    }),
  );
});
it("rejects non-HTTPS webhook receivers before making a request", async () => {
  setup(<EndpointForm onDone={vi.fn()} />);
  await userEvent.type(screen.getByLabelText("Endpoint name"), "Outbound CRM");
  await userEvent.selectOptions(screen.getByLabelText("Direction"), "outbound");
  await userEvent.type(
    screen.getByLabelText("Receiver HTTPS URL"),
    "http://127.0.0.1/private",
  );
  await userEvent.type(
    screen.getByLabelText("Signing secret"),
    "test-only-secret-abcdefghijklmnopqrstuvwxyz",
  );
  await userEvent.click(screen.getByRole("button", { name: "Save webhook" }));
  expect(
    await screen.findByText("Enter a public HTTPS receiver URL."),
  ).toBeInTheDocument();
  expect(write).not.toHaveBeenCalled();
});
it("shows sanitized workflow API errors and permits retrying the form", async () => {
  vi.mocked(write).mockRejectedValue(
    new Error("Workflow changed. Reload before saving."),
  );
  setup(<WorkflowForm workflow={workflow} onDone={vi.fn()} />);
  await userEvent.click(screen.getByRole("button", { name: "Save workflow" }));
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "Workflow changed",
  );
  expect(screen.getByRole("button", { name: "Save workflow" })).toBeEnabled();
});
