"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Field, Input, controlClass } from "@/components/forms/field";
import { Pagination } from "@/components/tables/pagination";
import {
  CrmState,
  PageHeading,
  Badge,
  DateText,
} from "@/features/crm/components";
import { list, write } from "@/features/crm/api";
import { ApiError } from "@/lib/api";
import { useUser } from "@/hooks/use-user";
import { useDebounced } from "@/hooks/use-debounced";
import type { User } from "@/types/auth";
import { SendPasswordReset } from "./send-password-reset";

type ManagedUser = User & { revision: string };
type UserValues = {
  name: string;
  email: string;
  role: string;
  status: string;
  password: string;
  password_confirmation: string;
};

function UserForm({
  account,
  currentId,
  onClose,
}: {
  account: ManagedUser | null;
  currentId: number;
  onClose: () => void;
}) {
  const client = useQueryClient();
  const router = useRouter();
  const self = account?.id === currentId;
  const form = useForm<UserValues>({
    defaultValues: {
      name: account?.name ?? "",
      email: account?.email ?? "",
      role: account?.roles[0] ?? "Staff",
      status: account?.status ?? "active",
      password: "",
      password_confirmation: "",
    },
  });
  const [conflict, setConflict] = useState(false);

  async function submit(values: UserValues) {
    try {
      await write<ManagedUser>(
        account ? `/users/${account.id}` : "/users",
        account ? "PUT" : "POST",
        {
          ...values,
          ...(self ? { role: account.roles[0], status: account.status } : {}),
          password: values.password || undefined,
          password_confirmation: values.password
            ? values.password_confirmation
            : undefined,
          ...(account ? { revision: account.revision } : {}),
        },
      );
      toast.success(account ? "User updated" : "User created");
      if (self && values.password) {
        client.clear();
        router.replace("/login");
        return;
      }
      await client.invalidateQueries();
      onClose();
    } catch (error) {
      if (error instanceof ApiError) {
        for (const field of [
          "name",
          "email",
          "role",
          "status",
          "password",
          "password_confirmation",
        ] as const) {
          if (error.errors[field])
            form.setError(field, { message: error.errors[field][0] });
        }
        if (error.status === 409) setConflict(true);
      }
      form.setError("root", {
        message:
          error instanceof Error ? error.message : "Unable to save user.",
      });
    }
  }

  return (
    <section
      aria-label={account ? "Edit user" : "Create user"}
      className="rounded-xl border border-border bg-card p-5"
    >
      <h2 className="mb-4 text-lg font-semibold">
        {account ? `Edit ${account.name}` : "Create user"}
      </h2>
      <form onSubmit={form.handleSubmit(submit)} className="space-y-4">
        <fieldset
          disabled={form.formState.isSubmitting || conflict}
          className="grid gap-4 sm:grid-cols-2"
        >
          <Field label="Full name" error={form.formState.errors.name?.message}>
            <Input
              autoComplete="name"
              maxLength={255}
              required
              {...form.register("name")}
            />
          </Field>
          <Field
            label="Email address"
            error={form.formState.errors.email?.message}
          >
            <Input
              type="email"
              autoComplete="off"
              maxLength={255}
              required
              {...form.register("email")}
            />
          </Field>
          <Field label="Role" error={form.formState.errors.role?.message}>
            <select
              className={controlClass}
              {...form.register("role")}
              disabled={self}
            >
              <option>Staff</option>
              <option>Manager</option>
              <option>Admin</option>
            </select>
          </Field>
          <Field
            label="Account status"
            error={form.formState.errors.status?.message}
          >
            <select
              className={controlClass}
              {...form.register("status")}
              disabled={self}
            >
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </Field>
          <Field
            label={account ? "New password (optional)" : "Password"}
            error={form.formState.errors.password?.message}
          >
            <Input
              type="password"
              autoComplete="new-password"
              minLength={12}
              maxLength={128}
              required={!account}
              {...form.register("password")}
            />
          </Field>
          <Field
            label="Confirm password"
            error={form.formState.errors.password?.message}
          >
            <Input
              type="password"
              autoComplete="new-password"
              maxLength={128}
              required={!account}
              {...form.register("password_confirmation")}
            />
          </Field>
        </fieldset>
        <p className="text-xs text-muted-foreground">
          Passwords require at least 12 characters, uppercase and lowercase
          letters, a number, and a symbol. Leave both password fields empty to
          keep the current password. Role, status, or password changes sign the
          affected user out.
        </p>
        {self && (
          <p className="text-sm text-muted-foreground">
            Your own Admin role and active status are protected. Changing your
            password will return you to sign in.
          </p>
        )}
        {form.formState.errors.root && (
          <p role="alert" className="text-sm text-red-600">
            {form.formState.errors.root.message}
          </p>
        )}
        <div className="flex gap-2">
          <Button
            type="submit"
            disabled={form.formState.isSubmitting || conflict}
          >
            {form.formState.isSubmitting
              ? "Saving..."
              : account
                ? "Save user"
                : "Create user"}
          </Button>
          <Button
            type="button"
            variant="outline"
            disabled={form.formState.isSubmitting}
            onClick={onClose}
          >
            {conflict ? "Close and reload users" : "Cancel"}
          </Button>
        </div>
      </form>
    </section>
  );
}

export function UsersWorkspace() {
  const { data: user } = useUser();
  const [search, setSearch] = useState("");
  const term = useDebounced(search);
  const [role, setRole] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [editor, setEditor] = useState<{ account: ManagedUser | null } | null>(
    null,
  );
  const permitted = !!user?.permissions.includes("users.manage");
  const users = useQuery({
    queryKey: ["users", term, role, status, page],
    queryFn: () =>
      list<ManagedUser>("/users", { search: term, role, status, page }),
    enabled: permitted,
  });

  if (!user) return null;
  if (!permitted) return <p role="alert">Only Admins can manage users.</p>;

  return (
    <div className="space-y-6">
      <PageHeading
        title="Users"
        description="Manage accounts, roles, and access to your business workspace."
        action={
          <Button
            disabled={!!editor}
            onClick={() => setEditor({ account: null })}
          >
            Add user
          </Button>
        }
      />
      <div className="grid gap-3 text-sm sm:grid-cols-3">
        {[
          ["Admin", "Full business access and user management."],
          [
            "Manager",
            "Manage business records, assignments, and team reports.",
          ],
          [
            "Staff",
            "Work with assigned customers, leads, tasks, and appointments.",
          ],
        ].map(([name, description]) => (
          <div
            key={name}
            className="rounded-xl border border-border bg-card p-4"
          >
            <h2 className="font-semibold">{name}</h2>
            <p className="mt-1 text-muted-foreground">{description}</p>
          </div>
        ))}
      </div>
      {editor && (
        <UserForm
          key={editor.account?.id ?? "new"}
          account={editor.account}
          currentId={user.id}
          onClose={() => {
            setEditor(null);
            users.refetch();
          }}
        />
      )}
      <div className="grid gap-4 sm:grid-cols-3">
        <Field label="Search users">
          <Input
            value={search}
            maxLength={100}
            placeholder="Name or email"
            onChange={(event) => {
              setSearch(event.target.value);
              setPage(1);
            }}
          />
        </Field>
        <Field label="Filter by role">
          <select
            className={controlClass}
            value={role}
            onChange={(event) => {
              setRole(event.target.value);
              setPage(1);
            }}
          >
            <option value="">All roles</option>
            <option>Admin</option>
            <option>Manager</option>
            <option>Staff</option>
          </select>
        </Field>
        <Field label="Filter by status">
          <select
            className={controlClass}
            value={status}
            onChange={(event) => {
              setStatus(event.target.value);
              setPage(1);
            }}
          >
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </Field>
      </div>
      <CrmState
        pending={users.isPending}
        error={users.error}
        retry={() => users.refetch()}
        empty={users.data?.data.length === 0}
      />
      {users.data && !users.error && (
        <div className="overflow-hidden rounded-xl border border-border bg-card">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">
                User accounts and assigned roles
              </caption>
              <thead className="bg-muted">
                <tr>
                  {[
                    "Name",
                    "Email",
                    "Role",
                    "Status",
                    "Last sign in",
                    "Actions",
                  ].map((name) => (
                    <th
                      scope="col"
                      key={name}
                      className="px-4 py-3 font-medium"
                    >
                      {name}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {users.data.data.map((account) => (
                  <tr key={account.id} className="border-t border-border">
                    <td className="px-4 py-3 font-medium">
                      {account.name}
                      {account.id === user.id && " (you)"}
                    </td>
                    <td className="px-4 py-3">{account.email}</td>
                    <td className="px-4 py-3">
                      {account.roles.join(", ") || "No role"}
                    </td>
                    <td className="px-4 py-3">
                      <Badge>{account.status}</Badge>
                    </td>
                    <td className="px-4 py-3">
                      <DateText value={account.last_login_at} />
                    </td>
                    <td className="px-4 py-3">
                      <Button
                        variant="outline"
                        size="sm"
                        aria-label={`Edit ${account.name}`}
                        disabled={!!editor}
                        onClick={() => setEditor({ account })}
                      >
                        Edit
                      </Button>
                      <SendPasswordReset id={account.id} name={account.name} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination
            page={users.data.meta.current_page}
            lastPage={users.data.meta.last_page}
            total={users.data.meta.total}
            onChange={setPage}
          />
        </div>
      )}
    </div>
  );
}
