"use client";
import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { Bell } from "lucide-react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { get, list, write } from "@/features/crm/api";
import { CrmState, DateText } from "@/features/crm/components";
import { Button } from "@/components/ui/button";
import { Pagination } from "@/components/tables/pagination";
export type WorkspaceNotification = {
  id: string;
  kind: string;
  message: string;
  task_title: string | null;
  action_url: string | null;
  read_at: string | null;
  created_at: string;
};
export function NotificationCenter() {
  const [open, setOpen] = useState(false);
  const [page, setPage] = useState(1);
  const [unread, setUnread] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  const client = useQueryClient();
  const count = useQuery({
    queryKey: ["notifications", "count"],
    queryFn: () => get<{ unread_count: number }>("/notifications/unread-count"),
    refetchInterval: 30000,
    refetchIntervalInBackground: false,
  });
  const query = useQuery({
    queryKey: ["notifications", "list", page, unread],
    queryFn: () =>
      list<WorkspaceNotification>("/notifications", {
        page,
        per_page: 5,
        ...(unread ? { unread: 1 } : {}),
      }),
    enabled: open,
    refetchInterval: open ? 30000 : false,
    refetchIntervalInBackground: false,
  });
  const mark = useMutation({
    mutationFn: (id?: string) =>
      write(
        id ? `/notifications/${id}/read` : "/notifications/read-all",
        id ? "PATCH" : "POST",
      ),
    onError: (error) => toast.error(error.message),
    onSettled: () => client.invalidateQueries({ queryKey: ["notifications"] }),
  });
  useEffect(() => {
    if (!open) return;
    function close(event: PointerEvent) {
      if (!root.current?.contains(event.target as Node)) setOpen(false);
    }
    function key(event: KeyboardEvent) {
      if (event.key === "Escape") {
        setOpen(false);
        root.current?.querySelector<HTMLButtonElement>("button")?.focus();
      }
    }
    document.addEventListener("pointerdown", close);
    document.addEventListener("keydown", key);
    return () => {
      document.removeEventListener("pointerdown", close);
      document.removeEventListener("keydown", key);
    };
  }, [open]);
  return (
    <div className="relative" ref={root}>
      <Button
        variant="ghost"
        size="icon"
        aria-label={`Notifications${count.data?.unread_count ? `, ${count.data.unread_count} unread` : ""}`}
        aria-expanded={open}
        aria-controls="notification-panel"
        onClick={() => setOpen(!open)}
      >
        <Bell className="size-5" />
        {!!count.data?.unread_count && (
          <span className="absolute -right-1 -top-1 rounded-full bg-primary px-1.5 text-[10px] text-white">
            {count.data.unread_count > 99 ? "99+" : count.data.unread_count}
          </span>
        )}
      </Button>
      {open && (
        <section
          id="notification-panel"
          aria-label="Workspace notifications"
          className="fixed left-3 right-3 top-20 z-50 max-h-[80vh] overflow-y-auto rounded-xl border border-border bg-card shadow-xl sm:absolute sm:left-auto sm:right-0 sm:top-12 sm:w-96"
        >
          <div className="space-y-3 border-b border-border p-4">
            <h2 className="font-semibold">Notifications</h2>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <label className="flex items-center gap-2 text-xs">
                <input
                  type="checkbox"
                  checked={unread}
                  onChange={(e) => {
                    setUnread(e.target.checked);
                    setPage(1);
                  }}
                />
                Unread only
              </label>
              <Button
                size="sm"
                variant="ghost"
                disabled={mark.isPending || !count.data?.unread_count}
                onClick={() => mark.mutate(undefined)}
              >
                Mark all read
              </Button>
            </div>
            {count.error && (
              <p role="alert" className="text-xs">
                {count.error.message}
              </p>
            )}
          </div>
          <CrmState
            pending={query.isPending}
            error={query.error}
            retry={() => query.refetch()}
          />
          {query.data?.data.length === 0 && (
            <p className="p-6 text-sm text-muted-foreground">
              You’re all caught up.
            </p>
          )}
          <ul className="divide-y divide-border">
            {query.data?.data.map((n) => (
              <li
                key={n.id}
                className={`space-y-2 p-4 text-sm ${!n.read_at ? "bg-primary/5" : ""}`}
              >
                <p>{n.message}</p>
                {n.action_url?.match(/^\/tasks\/\d+$/) && n.task_title && (
                  <Link
                    className="block break-words font-medium text-primary hover:underline"
                    href={n.action_url}
                    onClick={() => {
                      if (!n.read_at) mark.mutate(n.id);
                      setOpen(false);
                    }}
                  >
                    {n.task_title}
                  </Link>
                )}
                <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                  <DateText value={n.created_at} />
                  {!n.read_at && (
                    <button
                      type="button"
                      className="text-primary"
                      disabled={mark.isPending}
                      onClick={() => mark.mutate(n.id)}
                    >
                      Mark read
                    </button>
                  )}
                </div>
              </li>
            ))}
          </ul>
          {query.data && (
            <Pagination
              page={page}
              lastPage={query.data.meta.last_page}
              total={query.data.meta.total}
              onChange={setPage}
            />
          )}
        </section>
      )}
    </div>
  );
}
