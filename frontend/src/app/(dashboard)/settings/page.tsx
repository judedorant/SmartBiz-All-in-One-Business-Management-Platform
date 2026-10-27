"use client";
import Link from "next/link";
import { Button } from "@/components/ui/button";
import { useUser } from "@/hooks/use-user";
export default function Settings() {
  const { data: user } = useUser();
  if (!user) return null;
  return (
    <div className="max-w-3xl space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Account settings</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Your current profile and workspace access.
        </p>
      </div>
      {user.permissions.includes("integrations.manage") && (
        <Button asChild variant="outline">
          <Link href="/settings/integrations">
            Manage integrations and webhooks
          </Link>
        </Button>
      )}
      <section className="rounded-xl border border-border bg-card p-6">
        <h2 className="mb-5 font-semibold">Profile</h2>
        <dl className="grid gap-6 sm:grid-cols-2">
          {[
            ["Full name", user.name],
            ["Email address", user.email],
            ["Role", user.roles.join(", ")],
            ["Status", user.status],
          ].map(([label, value]) => (
            <div key={label}>
              <dt className="text-xs text-muted-foreground">{label}</dt>
              <dd className="mt-2 break-words text-sm font-medium">{value}</dd>
            </div>
          ))}
        </dl>
        <p className="mt-7 border-t border-border pt-4 text-xs leading-5 text-muted-foreground">
          Profile editing and user administration are planned for a later phase.
          Contact your administrator for account changes.
        </p>
      </section>
      <section className="rounded-xl border border-border bg-card p-6">
        <h2 className="font-semibold">Session security</h2>
        <p className="mt-3 text-sm leading-6 text-muted-foreground">
          SmartBiz uses an HTTP-only session cookie and CSRF protection. Sign
          out using the control in the top navigation when you finish on a
          shared device.
        </p>
      </section>
    </div>
  );
}
