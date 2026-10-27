"use client";
import { useState } from "react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { useUser } from "@/hooks/use-user";
import { useDebounced } from "@/hooks/use-debounced";
import { list } from "@/features/crm/api";
import { AssigneePicker } from "@/features/crm/pickers";
import {
  CrmState,
  PageHeading,
  Badge,
  DateText,
} from "@/features/crm/components";
import { Pagination } from "@/components/tables/pagination";
import { Button } from "@/components/ui/button";
import { Input, controlClass } from "@/components/forms/field";
import type { Person } from "@/types/crm";
import { statuses, priorities, label, type Task } from "./types";
export function TaskList() {
  const { data: user } = useUser();
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [priority, setPriority] = useState("");
  const [overdue, setOverdue] = useState(false);
  const [owner, setOwner] = useState<Person[]>([]);
  const [sort, setSort] = useState("due_date");
  const [direction, setDirection] = useState("asc");
  const [page, setPage] = useState(1);
  const term = useDebounced(search);
  const query = useQuery({
    queryKey: [
      "crm",
      "tasks",
      term,
      status,
      priority,
      overdue,
      owner[0]?.id,
      sort,
      direction,
      page,
    ],
    queryFn: () =>
      list<Task>("/tasks", {
        search: term,
        status,
        priority,
        ...(overdue ? { overdue: 1 } : {}),
        assigned_to: owner[0]?.id,
        sort,
        direction,
        page,
        per_page: 20,
      }),
  });
  return (
    <>
      <PageHeading
        title="Tasks"
        description="Keep ownership clear and move work forward."
        action={
          user?.permissions.includes("tasks.assign") && (
            <Button asChild>
              <Link href="/tasks/new">New task</Link>
            </Button>
          )
        }
      />
      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Input
          aria-label="Search tasks"
          placeholder="Search tasks"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
        />
        <select
          aria-label="Filter task status"
          className={controlClass}
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            setPage(1);
          }}
        >
          <option value="">All statuses</option>
          {statuses.map((s) => (
            <option key={s} value={s}>
              {label(s)}
            </option>
          ))}
        </select>
        <select
          aria-label="Filter task priority"
          className={controlClass}
          value={priority}
          onChange={(e) => {
            setPriority(e.target.value);
            setPage(1);
          }}
        >
          <option value="">All priorities</option>
          {priorities.map((p) => (
            <option key={p}>{p}</option>
          ))}
        </select>
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={overdue}
            onChange={(e) => {
              setOverdue(e.target.checked);
              setPage(1);
            }}
          />
          Overdue only
        </label>
        <select
          aria-label="Sort tasks"
          className={controlClass}
          value={sort}
          onChange={(e) => {
            setSort(e.target.value);
            setPage(1);
          }}
        >
          <option value="due_date">Due date</option>
          <option value="priority">Priority</option>
          <option value="title">Title</option>
          <option value="created_at">Created</option>
          <option value="updated_at">Updated</option>
        </select>
        <select
          aria-label="Sort direction"
          className={controlClass}
          value={direction}
          onChange={(e) => {
            setDirection(e.target.value);
            setPage(1);
          }}
        >
          <option value="asc">Ascending</option>
          <option value="desc">Descending</option>
        </select>
      </div>
      {user?.permissions.includes("tasks.assign") && (
        <div className="mb-5 max-w-md">
          <AssigneePicker
            value={owner}
            onChange={(v) => {
              setOwner(v);
              setPage(1);
            }}
            multiple={false}
            endpoint="/tasks/staff"
            label="Filter by task owner"
          />
        </div>
      )}
      <CrmState
        pending={query.isPending}
        error={query.error}
        retry={() => query.refetch()}
        empty={query.data?.data.length === 0}
      />
      {query.data && query.data.data.length > 0 && (
        <div className="overflow-x-auto rounded-xl border border-border bg-card">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-border bg-muted/40">
              <tr>
                {["Task", "Owner", "Status", "Priority", "Due"].map((h) => (
                  <th key={h} scope="col" className="p-4 font-medium">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {query.data.data.map((task) => (
                <tr
                  key={task.id}
                  className="border-b border-border last:border-0"
                >
                  <td className="p-4">
                    <Link
                      className="font-medium text-primary hover:underline"
                      href={`/tasks/${task.id}`}
                    >
                      {task.title}
                    </Link>
                    {task.is_overdue && (
                      <span className="ml-2 text-xs font-semibold text-red-600">
                        Overdue
                      </span>
                    )}
                  </td>
                  <td className="p-4">
                    {task.assignee?.name ?? "Unavailable"}
                  </td>
                  <td className="p-4">
                    <Badge>{label(task.status)}</Badge>
                  </td>
                  <td className="p-4 capitalize">{task.priority}</td>
                  <td className="whitespace-nowrap p-4">
                    <DateText value={task.due_date} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {query.data && (
        <Pagination
          page={page}
          lastPage={query.data.meta.last_page}
          total={query.data.meta.total}
          onChange={setPage}
        />
      )}
    </>
  );
}
