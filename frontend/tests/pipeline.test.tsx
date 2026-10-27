import React from "react";
import {
  render,
  screen,
  waitFor,
  fireEvent,
  within,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { vi, it, expect, beforeEach } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { PipelineBoard } from "@/features/leads/pipeline-board";
import { get, list, write } from "@/features/crm/api";
import { ApiError } from "@/lib/api";
import { stages, lead, page } from "./crm-fixtures";
vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock("@/hooks/use-user", () => ({
  useUser: () => ({ data: { id: 2, permissions: ["leads.update_assigned"] } }),
}));
vi.mock("@/features/crm/api", () => ({
  get: vi.fn(),
  list: vi.fn(),
  write: vi.fn(),
}));
function setup() {
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
      <PipelineBoard />
    </QueryClientProvider>,
  );
}
beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(get).mockResolvedValue(stages);
  vi.mocked(list).mockImplementation(async (_path, filters) =>
    page(Number(filters?.pipeline_stage_id) === 1 ? [lead] : []),
  );
  vi.mocked(write).mockResolvedValue({
    ...lead,
    pipeline_stage_id: 2,
    version: 2,
  });
});
it("uses database totals rather than the loaded-card count", async () => {
  vi.mocked(list).mockImplementation(async (_path, filters) =>
    page(
      Number(filters?.pipeline_stage_id) === 1 ? [lead] : [],
      Number(filters?.pipeline_stage_id) === 1 ? 42 : 0,
    ),
  );
  setup();
  const column = await screen.findByRole("region", { name: "New stage" });
  expect(await within(column).findByText("42")).toBeInTheDocument();
  expect(
    screen.queryByRole("link", { name: "New lead" }),
  ).not.toBeInTheDocument();
});
it("persists a stage selection with the lead version", async () => {
  setup();
  const select = await screen.findByRole("combobox", {
    name: "Move Support agreement to stage",
  });
  await userEvent.selectOptions(select, "2");
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith("/leads/5/stage", "PATCH", {
      pipeline_stage_id: 2,
      version: 1,
    }),
  );
});
it("sends drag-and-drop changes to the same authorized endpoint", async () => {
  setup();
  const link = await screen.findByRole("link", { name: "Support agreement" });
  const card = link.closest("article")!;
  fireEvent.dragStart(card, {
    dataTransfer: { setData: vi.fn(), effectAllowed: "" },
  });
  fireEvent.dragOver(screen.getByRole("region", { name: "Won stage" }));
  fireEvent.drop(screen.getByRole("region", { name: "Won stage" }));
  await waitFor(() =>
    expect(write).toHaveBeenCalledWith("/leads/5/stage", "PATCH", {
      pipeline_stage_id: 3,
      version: 1,
    }),
  );
});
it("reports stale changes without pretending the card moved", async () => {
  vi.mocked(write).mockRejectedValue(
    new ApiError("This lead changed. Reload it before saving again.", 409),
  );
  setup();
  await userEvent.selectOptions(
    await screen.findByRole("combobox", {
      name: "Move Support agreement to stage",
    }),
    "2",
  );
  expect(await screen.findByRole("alert")).toHaveTextContent(
    "This lead changed",
  );
  const column = screen.getByRole("region", { name: "New stage" });
  expect(
    within(column).getByRole("link", { name: "Support agreement" }),
  ).toBeInTheDocument();
});
it("renders hostile lead titles as text", async () => {
  vi.mocked(list).mockImplementation(async (_path, filters) =>
    page(
      Number(filters?.pipeline_stage_id) === 1
        ? [{ ...lead, title: '<img src=x onerror="alert(1)">' }]
        : [],
    ),
  );
  const { container } = render(
    <QueryClientProvider client={new QueryClient()}>
      <PipelineBoard />
    </QueryClientProvider>,
  );
  expect(
    await screen.findByText('<img src=x onerror="alert(1)">'),
  ).toBeInTheDocument();
  expect(container.querySelector("img")).toBeNull();
});
