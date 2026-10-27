"use client";
import { useState } from "react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { useUser } from "@/hooks/use-user";
import { list, get, write } from "@/features/crm/api";
import {
  PageHeading,
  CrmState,
  Badge,
  DateText,
} from "@/features/crm/components";
import { Button } from "@/components/ui/button";
import { Field, Input, controlClass } from "@/components/forms/field";
import { Pagination } from "@/components/tables/pagination";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { useAutomationWrite } from "./hooks";
import { pretty, type Integrations, type Endpoint } from "./types";
export function validGoogleUrl(value: string) {
  try {
    const url = new URL(value);
    return (
      url.origin === "https://accounts.google.com" &&
      url.pathname === "/o/oauth2/v2/auth"
    );
  } catch {
    return false;
  }
}
const endpointSchema = z
  .object({
    name: z.string().trim().min(1, "Name this endpoint.").max(150),
    direction: z.enum(["inbound", "outbound"]),
    endpoint_url: z.string(),
    secret: z.string().max(256),
    is_active: z.boolean(),
  })
  .superRefine((v, ctx) => {
    if (v.secret && v.secret.length < 32)
      ctx.addIssue({
        code: "custom",
        path: ["secret"],
        message: "Use at least 32 characters.",
      });
    if (
      v.direction === "outbound" &&
      (!z.url().safeParse(v.endpoint_url).success ||
        !v.endpoint_url.startsWith("https://"))
    )
      ctx.addIssue({
        code: "custom",
        path: ["endpoint_url"],
        message: "Enter a public HTTPS receiver URL.",
      });
  });
type EndpointValues = z.infer<typeof endpointSchema>;
export function EndpointForm({
  endpoint,
  onDone,
}: {
  endpoint?: Endpoint;
  onDone: () => void;
}) {
  const {
    register,
    handleSubmit,
    control,
    setValue,
    setError,
    getValues,
    formState: { errors },
  } = useForm<EndpointValues>({
    resolver: zodResolver(endpointSchema),
    defaultValues: {
      name: endpoint?.name ?? "",
      direction: endpoint?.direction ?? "inbound",
      endpoint_url: endpoint?.endpoint_url ?? "",
      secret: "",
      is_active: endpoint?.is_active ?? true,
    },
  });
  const direction = useWatch({ control, name: "direction" });
  const save = useAutomationWrite(
    async (values: EndpointValues) => {
      if (!endpoint && !values.secret) {
        setError("secret", { message: "Enter or generate a signing secret." });
        throw new Error("A signing secret is required.");
      }
      try {
        return await write(
          endpoint ? `/webhooks/${endpoint.id}` : "/webhooks",
          endpoint ? "PUT" : "POST",
          {
            name: values.name,
            direction: values.direction,
            endpoint_url:
              values.direction === "outbound" ? values.endpoint_url : null,
            is_active: values.is_active,
            ...(endpoint ? { version: endpoint.version } : {}),
            ...(values.secret ? { secret: values.secret } : {}),
          },
        );
      } catch (e) {
        if (e instanceof ApiError)
          for (const [key, messages] of Object.entries(e.errors))
            if (["name", "endpoint_url", "secret", "direction"].includes(key))
              setError(key as keyof EndpointValues, { message: messages[0] });
        throw e;
      }
    },
    "Webhook saved",
    onDone,
  );
  return (
    <form
      className="space-y-4 rounded-xl border border-border bg-card p-6"
      onSubmit={handleSubmit((v) => save.mutate(v))}
    >
      <h3 className="text-lg font-semibold">
        {endpoint ? "Edit webhook" : "New webhook"}
      </h3>
      <Field label="Endpoint name" error={errors.name?.message}>
        <Input {...register("name")} />
      </Field>
      {endpoint ? (
        <>
          <input type="hidden" {...register("direction")} />
          <p className="text-sm capitalize">Direction: {endpoint.direction}</p>
        </>
      ) : (
        <Field label="Direction">
          <select className={controlClass} {...register("direction")}>
            <option value="inbound">Receive signed events</option>
            <option value="outbound">Send workflow events</option>
          </select>
        </Field>
      )}
      {direction === "outbound" && (
        <>
          <Field
            label="Receiver HTTPS URL"
            error={errors.endpoint_url?.message}
          >
            <Input {...register("endpoint_url")} />
          </Field>
          <p className="text-xs text-muted-foreground">
            The receiver host must be approved by your administrator.
          </p>
        </>
      )}
      <Field
        label={
          endpoint
            ? "Replace signing secret (leave blank to retain)"
            : "Signing secret"
        }
        error={errors.secret?.message}
      >
        <Input
          type="password"
          autoComplete="new-password"
          {...register("secret")}
        />
      </Field>
      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => {
            const bytes = crypto.getRandomValues(new Uint8Array(32));
            setValue(
              "secret",
              Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join(
                "",
              ),
              { shouldValidate: true, shouldDirty: true },
            );
          }}
        >
          Generate secret
        </Button>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={async () => {
            const value = getValues("secret");
            if (!value) {
              toast.error("Enter or generate a secret first.");
              return;
            }
            try {
              await navigator.clipboard.writeText(value);
              toast.success("Secret copied");
            } catch {
              toast.error(
                "Clipboard access is unavailable. Copy your secret from a secure local source.",
              );
            }
          }}
        >
          Copy entered secret
        </Button>
      </div>
      <p className="text-xs leading-5 text-muted-foreground">
        Save the same secret securely in the sending or receiving service before
        submitting. Stored secrets are encrypted and cannot be read back here.
      </p>
      <label className="flex items-center gap-2 text-sm">
        <input type="checkbox" {...register("is_active")} />
        Enable endpoint
      </label>
      {save.error && <p role="alert">{save.error.message}</p>}
      <div className="flex gap-2">
        <Button disabled={save.isPending}>
          {save.isPending ? "Saving…" : "Save webhook"}
        </Button>
        <Button type="button" variant="outline" onClick={onDone}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
type Delivery = {
  id: number;
  delivery_id: string;
  event_type: string;
  status: string;
  attempts: number;
  last_attempt_at: string | null;
};
function Deliveries({ endpoint }: { endpoint: Endpoint }) {
  const [page, setPage] = useState(1);
  const query = useQuery({
    queryKey: ["automation", "deliveries", endpoint.id, page],
    queryFn: () =>
      list<Delivery>(`/webhooks/${endpoint.id}/deliveries`, { page }),
    refetchInterval: 15000,
  });
  return (
    <section className="space-y-4 rounded-xl border border-border bg-card p-5">
      <h3 className="font-semibold">Delivery history · {endpoint.name}</h3>
      <CrmState
        pending={query.isPending}
        error={query.error}
        retry={() => query.refetch()}
        empty={query.data?.data.length === 0}
      />
      {query.data?.data.map((d) => (
        <div
          key={d.id}
          className="flex flex-wrap justify-between gap-3 rounded-lg border border-border p-3 text-sm"
        >
          <div>
            <p className="capitalize">{pretty(d.event_type)}</p>
            <p className="mt-1 break-all text-xs text-muted-foreground">
              {d.delivery_id}
            </p>
          </div>
          <div>
            <Badge>{d.status}</Badge>
            <p className="mt-1 text-xs">
              {d.attempts} attempts · <DateText value={d.last_attempt_at} />
            </p>
          </div>
        </div>
      ))}
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
export function IntegrationWorkspace({ oauth }: { oauth?: string }) {
  const { data: user } = useUser();
  const allowed = !!user?.permissions.includes("integrations.manage");
  const [page, setPage] = useState(1);
  const [editing, setEditing] = useState<Endpoint | "new" | null>(null);
  const [history, setHistory] = useState<Endpoint | null>(null);
  const [revocationWarning, setRevocationWarning] = useState(false);
  const integrations = useQuery({
    queryKey: ["automation", "integrations"],
    queryFn: () => get<Integrations>("/integrations"),
    enabled: allowed,
  });
  const endpoints = useQuery({
    queryKey: ["automation", "webhooks", page],
    queryFn: () => list<Endpoint>("/webhooks", { page, per_page: 10 }),
    enabled: allowed,
  });
  const connect = useAutomationWrite(
    async (provider: string) => {
      const response = await write<{ url: string }>(
        `/integrations/google/${provider}/connect`,
        "POST",
      );
      if (!validGoogleUrl(response.url))
        throw new Error("Invalid Google authorization response.");
      return response;
    },
    "Opening Google consent",
    (data) => window.location.assign(data.url),
  );
  const disconnect = useAutomationWrite(
    (id: number) =>
      write<{ revocation_confirmed: boolean }>(`/integrations/${id}`, "DELETE"),
    "Account disconnected",
    (data) => setRevocationWarning(!data.revocation_confirmed),
  );
  const archive = useAutomationWrite(
    (endpoint: Endpoint) =>
      write(`/webhooks/${endpoint.id}`, "DELETE", {
        version: endpoint.version,
      }),
    "Webhook archived",
    () => setHistory(null),
  );
  if (!user) return <CrmState pending />;
  if (!allowed)
    return (
      <p role="alert">You do not have permission to manage integrations.</p>
    );
  return (
    <>
      <PageHeading
        title="Integrations"
        description="Connect Google services and signed webhook receivers."
        back="/settings"
        action={
          <Button asChild variant="outline">
            <Link href="/automation">Automation workflows</Link>
          </Button>
        }
      />
      {oauth === "connected" && (
        <p role="status" className="mb-5 rounded-lg bg-primary/10 p-4">
          Google account connected.
        </p>
      )}
      {oauth === "failed" && (
        <p role="alert" className="mb-5 rounded-lg border border-border p-4">
          Google connection was not completed. Check consent, session and OAuth
          configuration, then try again.
        </p>
      )}
      <section className="space-y-4 rounded-xl border border-border bg-card p-6">
        <h2 className="text-xl font-semibold">Google services</h2>
        {integrations.data?.mail_mode === "local" && (
          <p className="mb-5 rounded-lg border border-border bg-card p-4 text-sm">
            Email actions currently use local log delivery. Configure an email
            provider before expecting messages in an external inbox.
          </p>
        )}
        <CrmState
          pending={integrations.isPending}
          error={integrations.error}
          retry={() => integrations.refetch()}
        />
        {integrations.data && (
          <>
            <p className="text-sm text-muted-foreground">
              Each service requests its own permissions. Connecting is optional;
              tasks, scheduling and local workflows work independently.
            </p>
            {!integrations.data.google_configured && (
              <p className="rounded-lg bg-muted p-3 text-sm">
                Google OAuth is not configured. Ask your administrator to
                configure the Google client and callback URL before connecting.
              </p>
            )}
            <div className="flex flex-wrap gap-2">
              {integrations.data.providers.map((p) => (
                <Button
                  key={p}
                  variant="outline"
                  disabled={
                    !integrations.data?.google_configured || connect.isPending
                  }
                  onClick={() => connect.mutate(p)}
                >
                  Connect {pretty(p).replace("google ", "")}
                </Button>
              ))}
            </div>
            {integrations.data.accounts.length === 0 && (
              <p className="text-sm text-muted-foreground">
                No Google accounts connected.
              </p>
            )}
            <div className="divide-y divide-border">
              {integrations.data.accounts.map((account) => (
                <div
                  key={account.id}
                  className="flex flex-wrap items-center justify-between gap-3 py-4"
                >
                  <div>
                    <p className="text-sm font-medium">
                      {account.display_email ?? "Google account"}
                    </p>
                    <p className="mt-1 text-xs capitalize text-muted-foreground">
                      {pretty(account.provider)} · {account.status}
                    </p>
                  </div>
                  {account.status === "connected" && (
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={disconnect.isPending}
                      onClick={() => {
                        if (
                          window.confirm(
                            "Disconnect this Google service? Workflows using it will stop at that action.",
                          )
                        )
                          disconnect.mutate(account.id);
                      }}
                    >
                      Disconnect
                    </Button>
                  )}
                </div>
              ))}
            </div>
          </>
        )}
        {(connect.error || disconnect.error) && (
          <p role="alert">
            {connect.error?.message ?? disconnect.error?.message}
          </p>
        )}
        {revocationWarning && (
          <p role="alert">
            Disconnected locally. Open your Google account permissions to revoke
            the grant if it is still listed.
          </p>
        )}
      </section>
      <section className="mt-7 space-y-5">
        <div className="flex items-center justify-between">
          <h2 className="text-xl font-semibold">Webhooks</h2>
          <Button onClick={() => setEditing("new")}>New webhook</Button>
        </div>
        {editing ? (
          <EndpointForm
            key={editing === "new" ? "new" : `${editing.id}-${editing.version}`}
            endpoint={editing === "new" ? undefined : editing}
            onDone={() => setEditing(null)}
          />
        ) : (
          <>
            <CrmState
              pending={endpoints.isPending}
              error={endpoints.error}
              retry={() => endpoints.refetch()}
              empty={endpoints.data?.data.length === 0}
            />
            {endpoints.data?.data.map((endpoint) => (
              <article
                key={endpoint.id}
                className="space-y-3 rounded-xl border border-border bg-card p-5"
              >
                <div className="flex justify-between gap-3">
                  <h3 className="font-semibold">{endpoint.name}</h3>
                  <Badge>{endpoint.is_active ? "Enabled" : "Disabled"}</Badge>
                </div>
                <p className="break-all text-xs text-muted-foreground">
                  {endpoint.direction}:{" "}
                  {endpoint.receive_url ?? endpoint.endpoint_url}
                </p>
                <div className="flex flex-wrap gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setEditing(endpoint)}
                  >
                    Edit endpoint
                  </Button>
                  {endpoint.direction === "outbound" && (
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => setHistory(endpoint)}
                    >
                      Delivery history
                    </Button>
                  )}
                  <Button
                    size="sm"
                    variant="ghost"
                    className="text-red-600"
                    disabled={archive.isPending}
                    onClick={() => {
                      if (
                        window.confirm(
                          `Archive “${endpoint.name}”? Deliveries will stop.`,
                        )
                      )
                        archive.mutate(endpoint);
                    }}
                  >
                    Archive endpoint
                  </Button>
                </div>
              </article>
            ))}
            {archive.error && <p role="alert">{archive.error.message}</p>}
            {endpoints.data && (
              <Pagination
                page={page}
                lastPage={endpoints.data.meta.last_page}
                total={endpoints.data.meta.total}
                onChange={setPage}
              />
            )}
          </>
        )}
        {history && <Deliveries key={history.id} endpoint={history} />}
      </section>
    </>
  );
}
