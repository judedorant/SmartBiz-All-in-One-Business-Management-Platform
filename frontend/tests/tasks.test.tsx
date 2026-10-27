import React from "react";
import { beforeEach, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { TaskForm } from "@/features/tasks/form";
import { TaskList } from "@/features/tasks/list";
import { TaskComments } from "@/features/tasks/comments";
import { NotificationCenter } from "@/features/tasks/notification-center";
import { taskPayload } from "@/features/tasks/schema";
import { localDateTime } from "@/lib/date-time";
import { get, list, write } from "@/features/crm/api";
import { person, customer, lead, page } from "./crm-fixtures";
import type { Task } from "@/features/tasks/types";
const { push, profile } = vi.hoisted(() => ({
  push: vi.fn(),
  profile: { id: 2, name: "Taylor Reed", permissions: ["tasks.assign"] },
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));
vi.mock("@/hooks/use-user", () => ({ useUser: () => ({ data: profile }) }));
vi.mock("@/features/crm/api", () => ({
  get: vi.fn(),
  list: vi.fn(),
  write: vi.fn(),
}));
vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
const task: Task = {
  id: 30,
  title: "Follow up",
  description: "Check in",
  assigned_to: 2,
  created_by: 2,
  customer_id: 10,
  lead_id: null,
  status: "pending",
  priority: "high",
  version: 4,
  due_date: "2026-11-01T06:30:00Z",
  completed_at: null,
  is_overdue: true,
  assignee: person,
  creator: person,
  customer,
  lead: null,
  created_at: "2026-10-26T12:00:00Z",
  updated_at: "2026-10-26T12:00:00Z",
};
function setup(node: React.ReactNode) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  render(<QueryClientProvider client={client}>{node}</QueryClientProvider>);
  return client;
}
beforeEach(() => {
  vi.clearAllMocks();
  profile.permissions = ["tasks.assign"];
  vi.mocked(write).mockResolvedValue(task);
  vi.mocked(list).mockImplementation(async (path) =>
    path === "/tasks/staff"
      ? page([person])
      : path === "/customers"
        ? page([customer])
        : path === "/leads"
          ? page([lead])
          : page([]),
  );
  vi.mocked(get).mockResolvedValue({ unread_count: 1 });
});
it("validates an empty task before sending it", async () => {
  setup(<TaskForm />);
  await userEvent.click(screen.getByRole("button", { name: "Create task" }));
  expect(await screen.findByText("Enter a task title")).toBeInTheDocument();
  expect(write).not.toHaveBeenCalled();
});
it("saves creation with the real current owner and nullable optional fields", async () => {
  setup(<TaskForm />);
  await userEvent.type(screen.getByLabelText("Title"), "New work");
  await userEvent.click(screen.getByRole("button", { name: "Create task" }));
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith(
      "/tasks",
      "POST",
      expect.objectContaining({
        title: "New work",
        assigned_to: 2,
        customer_id: null,
        lead_id: null,
        due_date: null,
        description: null,
      }),
    ),
  );
  expect(push).toHaveBeenCalledWith("/tasks/30");
});
it("omits assignment and CRM links from staff edits", async () => {
  profile.permissions = ["tasks.manage_assigned"];
  setup(<TaskForm task={task} />);
  expect(screen.queryByText("Task owner")).not.toBeInTheDocument();
  await userEvent.click(screen.getByRole("button", { name: "Save task" }));
  await waitFor(() => expect(write).toHaveBeenCalled());
  const body = vi.mocked(write).mock.calls[0][2] as Record<string, unknown>;
  expect(body.version).toBe(4);
  expect(body).not.toHaveProperty("assigned_to");
  expect(body).not.toHaveProperty("customer_id");
  expect(body).not.toHaveProperty("lead_id");
});
it("omits unchanged manager links and preserves UTC even in a repeated DST hour", () => {
  const offset = vi
    .spyOn(Date.prototype, "getTimezoneOffset")
    .mockReturnValue(300);
  try {
    const result = taskPayload(
      {
        title: "Follow up",
        description: "",
        priority: "high",
        status: "pending",
        due_date: localDateTime(task.due_date!),
        assigned_to: 2,
        customer_id: 10,
        lead_id: null,
      },
      true,
      task.due_date!,
      4,
      {},
    );
    expect(result.due_date).toBe("2026-11-01T06:30:00Z");
    expect(result).not.toHaveProperty("assigned_to");
    expect(result).not.toHaveProperty("customer_id");
  } finally {
    offset.mockRestore();
  }
});
it("displays server conflicts without navigating", async () => {
  vi.mocked(write).mockRejectedValue(
    new Error("This task changed. Reload it before retrying."),
  );
  setup(<TaskForm task={task} />);
  await userEvent.click(screen.getByRole("button", { name: "Save task" }));
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "This task changed",
  );
  expect(push).not.toHaveBeenCalled();
});
it("confirms cancellation before saving", async () => {
  const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);
  try {
    setup(<TaskForm task={task} />);
    await userEvent.selectOptions(screen.getByLabelText("Status"), "cancelled");
    await userEvent.click(screen.getByRole("button", { name: "Save task" }));
    expect(confirm).toHaveBeenCalled();
    expect(write).not.toHaveBeenCalled();
  } finally {
    confirm.mockRestore();
  }
});
it("renders overdue tasks and sends owner-scoped filters", async () => {
  profile.permissions = ["tasks.manage_assigned"];
  vi.mocked(list).mockResolvedValue(page([task]));
  setup(<TaskList />);
  expect(
    await screen.findByRole("link", { name: "Follow up" }),
  ).toHaveAttribute("href", "/tasks/30");
  expect(screen.getByText("Overdue")).toBeInTheDocument();
  expect(
    screen.queryByRole("link", { name: "New task" }),
  ).not.toBeInTheDocument();
  await userEvent.click(screen.getByLabelText("Overdue only"));
  await userEvent.selectOptions(
    screen.getByLabelText("Filter task status"),
    "in_progress",
  );
  await waitFor(() =>
    expect(list).toHaveBeenLastCalledWith(
      "/tasks",
      expect.objectContaining({ overdue: 1, status: "in_progress" }),
    ),
  );
});
it("adds comments and only offers staff author controls for own comments", async () => {
  profile.permissions = ["tasks.manage_assigned"];
  vi.mocked(list).mockResolvedValue(
    page([
      {
        id: 1,
        comment: "Another author",
        user: { id: 3, name: "Alex" },
        version: 1,
        created_at: task.created_at,
        updated_at: task.created_at,
      },
    ]),
  );
  setup(<TaskComments taskId={30} />);
  expect(await screen.findByText("Another author")).toBeInTheDocument();
  expect(
    screen.queryByRole("button", { name: "Edit comment" }),
  ).not.toBeInTheDocument();
  await userEvent.type(screen.getByLabelText("Add a comment"), "  Ready  ");
  await userEvent.click(screen.getByRole("button", { name: "Add comment" }));
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith("/tasks/30/comments", "POST", {
      comment: "Ready",
    }),
  );
});
it("loads notifications on opening, marks reads, invalidates count, and closes with Escape", async () => {
  vi.mocked(list).mockResolvedValue(
    page([
      {
        id: "abc",
        kind: "task_assigned",
        message: "A task was assigned to you.",
        task_title: "Follow up",
        action_url: "/tasks/30",
        read_at: null,
        created_at: task.created_at,
      },
    ]),
  );
  setup(<NotificationCenter />);
  expect(list).not.toHaveBeenCalled();
  await userEvent.click(
    await screen.findByRole("button", { name: "Notifications, 1 unread" }),
  );
  expect(
    await screen.findByRole("link", { name: "Follow up" }),
  ).toHaveAttribute("href", "/tasks/30");
  await userEvent.click(screen.getByRole("button", { name: "Mark read" }));
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith("/notifications/abc/read", "PATCH"),
  );
  await userEvent.keyboard("{Escape}");
  expect(
    screen.queryByRole("region", { name: "Workspace notifications" }),
  ).not.toBeInTheDocument();
  expect(
    screen.getByRole("button", { name: "Notifications, 1 unread" }),
  ).toHaveFocus();
});
it("does not create links for inaccessible tasks or unsafe action URLs", async () => {
  vi.mocked(list).mockResolvedValue(
    page([
      {
        id: "abc",
        kind: "task_unassigned",
        message: "A task assignment changed.",
        task_title: null,
        action_url: null,
        read_at: null,
        created_at: task.created_at,
      },
      {
        id: "def",
        kind: "other",
        message: "Other",
        task_title: "Unsafe",
        action_url: "https://evil.example",
        read_at: null,
        created_at: task.created_at,
      },
    ]),
  );
  setup(<NotificationCenter />);
  await userEvent.click(screen.getByRole("button", { name: /Notifications/ }));
  expect(
    await screen.findByText("A task assignment changed."),
  ).toBeInTheDocument();
  expect(screen.queryByRole("link")).not.toBeInTheDocument();
  await userEvent.click(screen.getByRole("button", { name: "Mark all read" }));
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith("/notifications/read-all", "POST"),
  );
});
it("shows a retry control when notifications cannot load", async () => {
  vi.mocked(list).mockRejectedValue(new Error("Unavailable"));
  setup(<NotificationCenter />);
  await userEvent.click(screen.getByRole("button", { name: /Notifications/ }));
  expect(await screen.findByRole("alert")).toHaveTextContent("Unavailable");
  expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
});
