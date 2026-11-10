"use client";
import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useQueryClient } from "@tanstack/react-query";
import { useTheme } from "next-themes";
import { toast } from "sonner";
import {
  LayoutDashboard,
  Users,
  Contact,
  Columns3,
  CalendarDays,
  CheckSquare,
  Zap,
  BarChart3,
  UserCog,
  Settings,
  Layers,
  LogOut,
  SunMoon,
  Menu,
  X,
  Loader2,
  ShieldCheck,
  ChevronDown,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { useUser } from "@/hooks/use-user";
import { authApi } from "@/features/auth/api";
import { ApiError } from "@/lib/api";
import { NotificationCenter } from "@/features/tasks/notification-center";
import { cn } from "@/lib/utils";
export function DashboardShell({ children }: { children: React.ReactNode }) {
  const { data: user, isPending, error, refetch } = useUser();
  const router = useRouter();
  const pathname = usePathname();
  const client = useQueryClient();
  const { resolvedTheme, setTheme } = useTheme();
  const [open, setOpen] = useState(false);
  const [leaving, setLeaving] = useState(false);
  useEffect(() => {
    if (error instanceof ApiError && [401, 403, 419].includes(error.status)) {
      client.clear();
      router.replace("/login");
    }
  }, [error, router, client]);
  async function logout() {
    setLeaving(true);
    try {
      await authApi.logout();
      client.clear();
      router.replace("/login");
      toast.success("Signed out securely");
    } catch (e) {
      if (e instanceof ApiError && [401, 419].includes(e.status)) {
        client.clear();
        router.replace("/login");
      } else {
        toast.error(e instanceof Error ? e.message : "Sign out failed");
        setLeaving(false);
      }
    }
  }
  if (isPending)
    return (
      <div
        role="status"
        className="flex min-h-screen items-center justify-center gap-3"
      >
        <Loader2 className="animate-spin text-primary" />
        Loading your workspace…
      </div>
    );
  if (error || !user)
    return (
      <div className="mx-auto mt-28 max-w-md rounded-xl border border-border bg-card p-8">
        <h1 className="text-xl font-semibold">Workspace unavailable</h1>
        <p role="alert" className="my-4 text-muted-foreground">
          {error?.message ?? "Unable to load your profile."}
        </p>
        <Button onClick={() => refetch()}>Try again</Button>
      </div>
    );
  return (
    <div className="min-h-screen">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:fixed focus:z-50 focus:bg-card focus:p-4"
      >
        Skip to content
      </a>
      {open && (
        <button
          aria-label="Close navigation overlay"
          className="fixed inset-0 z-30 bg-black/40 lg:hidden"
          onClick={() => setOpen(false)}
        />
      )}
      <aside
        className={cn(
          "fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-border bg-card transition-transform lg:translate-x-0",
          open ? "translate-x-0" : "-translate-x-full",
        )}
      >
        <div className="flex h-20 items-center justify-between px-7">
          <Link
            href="/dashboard"
            className="flex items-center gap-2.5 text-xl font-bold"
          >
            <span className="rounded-lg bg-primary p-2 text-white">
              <Layers className="size-5" />
            </span>
            SmartBiz<span className="text-primary">.</span>
          </Link>
          <Button
            variant="ghost"
            size="icon"
            className="lg:hidden"
            aria-label="Close navigation"
            onClick={() => setOpen(false)}
          >
            <X />
          </Button>
        </div>
        <div className="mx-5 mb-7 rounded-lg border border-border bg-background px-3 py-3">
          <p className="text-xs text-muted-foreground">BUSINESS WORKSPACE</p>
          <p className="mt-1 flex items-center justify-between text-sm font-semibold">
            Your organization
            <ChevronDown className="size-4 text-muted-foreground" />
          </p>
        </div>
        <nav
          aria-label="Main navigation"
          className="min-h-0 flex-1 space-y-1 overflow-y-auto px-4"
        >
          <p className="mb-3 px-3 text-[10px] font-bold uppercase tracking-widest text-muted-foreground">
            Workspace
          </p>
          <Link
            onClick={() => setOpen(false)}
            href="/dashboard"
            aria-current={pathname === "/dashboard" ? "page" : undefined}
            className={cn(
              "flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm",
              pathname === "/dashboard"
                ? "bg-primary/10 font-semibold text-primary"
                : "text-muted-foreground hover:bg-muted",
            )}
          >
            <LayoutDashboard className="size-[18px]" />
            Dashboard
          </Link>
          {[
            {
              name: "Customers",
              icon: Users,
              path: "/customers",
              permitted:
                user.permissions.includes("customers.manage") ||
                user.permissions.includes("customers.view_assigned"),
            },
            {
              name: "Leads",
              icon: Contact,
              path: "/leads",
              permitted:
                user.permissions.includes("leads.manage") ||
                user.permissions.includes("leads.update_assigned"),
            },
            {
              name: "Sales Pipeline",
              icon: Columns3,
              path: "/pipeline",
              permitted:
                user.permissions.includes("leads.manage") ||
                user.permissions.includes("leads.update_assigned"),
            },
            {
              name: "Automation",
              icon: Zap,
              path: "/automation",
              permitted: user.permissions.includes("automations.manage"),
            },
            {
              name: "Reports",
              icon: BarChart3,
              path: "/reports",
              permitted:
                user.permissions.includes("reports.team") ||
                user.permissions.includes("reports.all"),
            },
            {
              name: "Users",
              icon: UserCog,
              path: "/users",
              permitted: user.permissions.includes("users.manage"),
            },
            {
              name: "Tasks",
              icon: CheckSquare,
              path: "/tasks",
              permitted:
                user.permissions.includes("tasks.assign") ||
                user.permissions.includes("tasks.manage_assigned"),
            },
            {
              name: "Appointments",
              icon: CalendarDays,
              path: "/appointments",
              permitted:
                user.permissions.includes("appointments.manage") ||
                user.permissions.includes("appointments.view_assigned"),
            },
          ]
            .filter((item) => item.permitted)
            .map(({ name, icon: Icon, path }) => (
              <Link
                key={path}
                href={path}
                onClick={() => setOpen(false)}
                aria-current={pathname.startsWith(path) ? "page" : undefined}
                className={cn(
                  "flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm",
                  pathname.startsWith(path)
                    ? "bg-primary/10 font-semibold text-primary"
                    : "text-muted-foreground hover:bg-muted",
                )}
              >
                <Icon className="size-[18px]" />
                {name}
              </Link>
            ))}
          <Link
            href="/settings"
            onClick={() => setOpen(false)}
            aria-current={pathname === "/settings" ? "page" : undefined}
            className={cn(
              "flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm",
              pathname === "/settings"
                ? "bg-primary/10 text-primary"
                : "text-muted-foreground hover:bg-muted",
            )}
          >
            <Settings className="size-[18px]" />
            Settings
          </Link>
        </nav>
        <div className="m-4 rounded-xl bg-primary/5 p-4">
          <ShieldCheck className="mb-2 size-5 text-primary" />
          <p className="text-xs font-semibold">A secure foundation</p>
          <p className="mt-1 text-xs leading-5 text-muted-foreground">
            Access is controlled by your assigned role and permissions.
          </p>
        </div>
        <div className="border-t border-border px-5 py-4">
          <p className="text-xs text-muted-foreground">SmartBiz · Phase 7</p>
        </div>
      </aside>
      <div className="lg:pl-64">
        <header className="sticky top-0 z-20 flex h-20 items-center justify-between border-b border-border bg-card/95 px-5 backdrop-blur sm:px-8">
          <div className="flex items-center gap-3">
            <Button
              className="lg:hidden"
              variant="ghost"
              size="icon"
              aria-label="Open navigation"
              onClick={() => setOpen(true)}
            >
              <Menu />
            </Button>
            <span className="text-sm text-muted-foreground">
              Workspace <span className="mx-2 text-border">/</span>
              <span className="font-medium text-foreground">
                {pathname.startsWith("/users")
                  ? "Users"
                  : pathname.startsWith("/reports")
                    ? "Reports"
                    : pathname.startsWith("/automation")
                      ? "Automation"
                      : pathname.startsWith("/tasks")
                        ? "Tasks"
                        : pathname.startsWith("/appointments")
                          ? "Appointments"
                          : pathname.startsWith("/customers")
                            ? "Customers"
                            : pathname.startsWith("/leads")
                              ? "Leads"
                              : pathname === "/pipeline"
                                ? "Sales Pipeline"
                                : pathname === "/settings"
                                  ? "Settings"
                                  : "Overview"}
              </span>
            </span>
          </div>
          <div className="flex items-center gap-3">
            <NotificationCenter />
            <Button
              variant="ghost"
              size="icon"
              aria-label="Toggle color theme"
              onClick={() =>
                setTheme(resolvedTheme === "dark" ? "light" : "dark")
              }
            >
              <SunMoon className="size-5" />
            </Button>
            <div className="hidden border-l border-border pl-4 text-right sm:block">
              <p className="text-sm font-semibold">{user.name}</p>
              <p className="text-xs text-muted-foreground">
                {user.roles.join(", ")}
              </p>
            </div>
            <span className="flex size-9 items-center justify-center rounded-full bg-indigo-100 text-sm font-bold text-indigo-700">
              {user.name.charAt(0).toUpperCase()}
            </span>
            <Button
              variant="ghost"
              size="icon"
              aria-label="Sign out"
              disabled={leaving}
              onClick={logout}
            >
              {leaving ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <LogOut className="size-4" />
              )}
            </Button>
          </div>
        </header>
        <main id="main" className="mx-auto max-w-[1500px] p-5 sm:p-8">
          {children}
        </main>
        <footer className="px-8 pb-6 text-xs text-muted-foreground">
          SmartBiz · Your business, connected.
        </footer>
      </div>
    </div>
  );
}
