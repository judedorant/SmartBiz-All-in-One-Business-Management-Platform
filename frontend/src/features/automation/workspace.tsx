"use client";
import { useState } from "react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { useUser } from "@/hooks/use-user";
import { useDebounced } from "@/hooks/use-debounced";
import { list, write } from "@/features/crm/api";
import {
  PageHeading,
  CrmState,
  Badge,
  DateText,
} from "@/features/crm/components";
import { Input, controlClass } from "@/components/forms/field";
import { Button } from "@/components/ui/button";
import { Pagination } from "@/components/tables/pagination";
import { WorkflowForm } from "./form";
import { useAutomationWrite } from "./hooks";
import { pretty, type Workflow, type Execution } from "./types";
export function ExecutionLogs({ workflow }: { workflow: Workflow }) {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState("");
  const query = useQuery({
    queryKey: ["automation", "logs", workflow.id, page, status],
    queryFn: () =>
      list<Execution>(`/automations/${workflow.id}/logs`, {
        page,
        per_page: 10,
        execution_status: status,
      }),
    refetchInterval: 15000,
  });
  const retry = useAutomationWrite(
    (log: Execution) =>
      write(`/automations/${workflow.id}/logs/${log.id}/retry`, "POST"),
    "Retry queued",
  );
  return (
    <section className="mt-6 space-y-4 rounded-xl border border-border bg-card p-6">
      <h2 className="text-lg font-semibold">
        Execution logs · {workflow.name}
      </h2>
      <select
        aria-label="Execution status"
        className={controlClass}
        value={status}
        onChange={(e) => {
          setStatus(e.target.value);
          setPage(1);
        }}
      >
        <option value="">All outcomes</option>
        {[
          "pending",
          "running",
          "succeeded",
          "failed",
          "skipped",
          "uncertain",
        ].map((s) => (
          <option key={s}>{s}</option>
        ))}
      </select>
      <CrmState
        pending={query.isPending}
        error={query.error}
        retry={() => query.refetch()}
        empty={query.data?.data.length === 0}
      />
      {query.data?.data.map((log) => (
        <article
          key={log.id}
          className="space-y-3 rounded-lg border border-border p-4"
        >
          <div className="flex flex-wrap justify-between gap-3">
            <span className="text-sm">
              Run #{log.id} · version {log.workflow_version} ·{" "}
              <DateText value={log.created_at} />
            </span>
            <Badge>{log.execution_status}</Badge>
          </div>
          {log.error_message && (
            <p className="text-sm text-muted-foreground">
              {pretty(log.error_message)}
            </p>
          )}
          {log.execution_status === "uncertain" && (
            <p className="text-sm text-amber-700 dark:text-amber-400">
              Delivery may have completed. Check the receiving service before
              creating another execution.
            </p>
          )}
          <ol className="space-y-2 text-sm">
            {log.steps.map((step) => (
              <li key={step.id}>
                {step.action_index + 1}. {pretty(step.action_type)} ·{" "}
                {step.status} · {step.attempts} attempt
                {step.attempts === 1 ? "" : "s"}
              </li>
            ))}
          </ol>
          {workflow.is_active &&
            log.execution_status === "failed" &&
            log.steps.some((s) => s.status === "failed" && s.retryable) && (
              <Button
                size="sm"
                variant="outline"
                disabled={retry.isPending}
                onClick={() => retry.mutate(log)}
              >
                Retry failed step
              </Button>
            )}
        </article>
      ))}
      {retry.error && <p role="alert">{retry.error.message}</p>}
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
export function AutomationWorkspace() {
  const { data: user } = useUser();
  const allowed = !!user?.permissions.includes("automations.manage");
  const [search, setSearch] = useState("");
  const term = useDebounced(search);
  const [page, setPage] = useState(1);
  const [active, setActive] = useState("");
  const [editing, setEditing] = useState<Workflow | "new" | null>(null);
  const [logs, setLogs] = useState<Workflow | null>(null);
  const query = useQuery({
    queryKey: ["automation", "workflows", page, term, active],
    queryFn: () =>
      list<Workflow>("/automations", {
        page,
        search: term,
        is_active: active,
        per_page: 10,
      }),
    enabled: allowed,
  });
  const toggle = useAutomationWrite(
    (workflow: Workflow) =>
      write<Workflow>(`/automations/${workflow.id}/active`, "PATCH", {
        version: workflow.version,
        is_active: !workflow.is_active,
      }),
    "Workflow state updated",
    (v) => {
      if (logs?.id === v.id) setLogs(v);
    },
  );
  const archive = useAutomationWrite(
    (workflow: Workflow) =>
      write(`/automations/${workflow.id}`, "DELETE", {
        version: workflow.version,
      }),
    "Workflow archived",
    () => {
      setLogs(null);
      setEditing(null);
    },
  );
  if (!user) return <CrmState pending />;
  if (!allowed)
    return <p role="alert">You do not have permission to manage automation.</p>;
  return (
    <>
      <PageHeading
        title="Automation"
        description="Turn repeatable business steps into tracked workflows."
        action={
          <div className="flex gap-2">
            <Button asChild variant="outline">
              <Link href="/settings/integrations">Integrations</Link>
            </Button>
            <Button
              onClick={() => {
                setEditing("new");
                setLogs(null);
              }}
            >
              New workflow
            </Button>
          </div>
        }
      />
      {editing ? (
        <WorkflowForm
          key={editing === "new" ? "new" : `${editing.id}-${editing.version}`}
          workflow={editing === "new" ? undefined : editing}
          onDone={() => setEditing(null)}
        />
      ) : (
        <>
          <div className="mb-5 flex flex-wrap gap-3">
            <Input
              aria-label="Search workflows"
              className="max-w-md"
              placeholder="Search workflows"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
            <select
              aria-label="Workflow status"
              className={`${controlClass} max-w-xs`}
              value={active}
              onChange={(e) => {
                setActive(e.target.value);
                setPage(1);
              }}
            >
              <option value="">All workflows</option>
              <option value="1">Enabled</option>
              <option value="0">Disabled</option>
            </select>
          </div>
          <CrmState
            pending={query.isPending}
            error={query.error}
            retry={() => query.refetch()}
            empty={query.data?.data.length === 0}
          />
          <div className="grid gap-4">
            {query.data?.data.map((workflow) => (
              <article
                className="rounded-xl border border-border bg-card p-5"
                key={workflow.id}
              >
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h2 className="font-semibold">{workflow.name}</h2>
                    <p className="mt-2 text-sm capitalize text-muted-foreground">
                      {pretty(workflow.trigger_type)} ·{" "}
                      {workflow.configuration.actions.length} action
                      {workflow.configuration.actions.length === 1 ? "" : "s"}
                    </p>
                  </div>
                  <Badge>{workflow.is_active ? "Enabled" : "Disabled"}</Badge>
                </div>
                <div className="mt-4 flex flex-wrap gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setEditing(workflow)}
                  >
                    Edit workflow
                  </Button>
                  <Button
                    size="sm"
                    variant="outline"
                    disabled={toggle.isPending}
                    onClick={() => toggle.mutate(workflow)}
                  >
                    {workflow.is_active ? "Disable" : "Enable"}
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => setLogs(workflow)}
                  >
                    Execution logs
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    className="text-red-600"
                    disabled={archive.isPending}
                    onClick={() => {
                      if (
                        window.confirm(
                          `Archive “${workflow.name}”? Pending actions will stop.`,
                        )
                      )
                        archive.mutate(workflow);
                    }}
                  >
                    Archive
                  </Button>
                </div>
              </article>
            ))}
          </div>
          {(toggle.error || archive.error) && (
            <p role="alert" className="mt-4">
              {toggle.error?.message ?? archive.error?.message}
            </p>
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
      )}
      {logs && !editing && <ExecutionLogs key={logs.id} workflow={logs} />}
    </>
  );
}
