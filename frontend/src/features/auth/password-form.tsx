"use client";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Field, Input } from "@/components/forms/field";
import { ApiError, api, csrf } from "@/lib/api";

type Values = {
  email: string;
  current_password: string;
  password: string;
  password_confirmation: string;
};
export function PasswordForm({
  mode,
  email = "",
  token = "",
}: {
  mode: "forgot" | "reset" | "change";
  email?: string;
  token?: string;
}) {
  const form = useForm<Values>({
    defaultValues: {
      email,
      current_password: "",
      password: "",
      password_confirmation: "",
    },
  });
  const [show, setShow] = useState(false);
  const [message, setMessage] = useState("");
  const router = useRouter();
  const client = useQueryClient();
  const reset = mode === "reset";
  const change = mode === "change";
  const forgot = mode === "forgot";
  const missingLink = reset && (!token || !email);
  async function submit(values: Values) {
    setMessage("");
    try {
      await csrf();
      const path = forgot
        ? "/forgot-password"
        : reset
          ? "/reset-password"
          : "/profile/change-password";
      const payload = forgot
        ? { email: values.email }
        : {
            password: values.password,
            password_confirmation: values.password_confirmation,
            ...(reset
              ? { email: values.email, token }
              : { current_password: values.current_password }),
          };
      await api<null>(path, {
        method: change ? "PUT" : "POST",
        body: JSON.stringify(payload),
      });
      if (forgot) {
        setMessage(
          "If an account exists with this email, a password reset link has been sent.",
        );
      } else {
        form.reset();
        client.clear();
        toast.success("Password updated. Sign in with your new password.");
        router.replace("/login");
      }
    } catch (error) {
      if (error instanceof ApiError) {
        for (const key of [
          "email",
          "current_password",
          "password",
          "password_confirmation",
        ] as const) {
          if (error.errors[key])
            form.setError(key, { message: error.errors[key][0] });
        }
      }
      form.setError("root", {
        message:
          error instanceof ApiError
            ? (error.errors.token?.[0] ?? error.message)
            : "Unable to update your password. Please try again.",
      });
    }
  }
  return (
    <form onSubmit={form.handleSubmit(submit)} className="space-y-5">
      {missingLink && (
        <p role="alert">
          This reset link is incomplete.{" "}
          <Link className="text-primary underline" href="/forgot-password">
            Request a new reset link
          </Link>
          .
        </p>
      )}
      <fieldset
        disabled={form.formState.isSubmitting || missingLink}
        className="space-y-4"
      >
        {!change && (
          <Field
            label="Email address"
            error={form.formState.errors.email?.message}
          >
            <Input
              type="email"
              autoComplete="email"
              required
              maxLength={255}
              {...form.register("email")}
            />
          </Field>
        )}
        {change && (
          <Field
            label="Current password"
            error={form.formState.errors.current_password?.message}
          >
            <Input
              type={show ? "text" : "password"}
              autoComplete="current-password"
              required
              maxLength={128}
              {...form.register("current_password")}
            />
          </Field>
        )}
        {!forgot && (
          <>
            <p id="password-guidance" className="text-sm text-muted-foreground">
              Use 12–72 characters, including uppercase and lowercase letters, a
              number, and a symbol. Choose a password different from your current
              password.
            </p>
            <Field
              label="New password"
              error={form.formState.errors.password?.message}
            >
              <Input
                type={show ? "text" : "password"}
                autoComplete="new-password"
                aria-describedby="password-guidance"
                required
                minLength={12}
                maxLength={72}
                {...form.register("password")}
              />
            </Field>
            <Field
              label="Confirm new password"
              error={form.formState.errors.password_confirmation?.message}
            >
              <Input
                type={show ? "text" : "password"}
                autoComplete="new-password"
                required
                {...form.register("password_confirmation", {
                  validate: (value) =>
                    value === form.getValues("password") ||
                    "Passwords must match.",
                })}
              />
            </Field>
            <Button
              type="button"
              variant="ghost"
              aria-pressed={show}
              onClick={() => setShow((value) => !value)}
            >
              {show ? "Hide passwords" : "Show passwords"}
            </Button>
            {change && (
              <p className="text-sm text-muted-foreground">
                Changing your password signs you out on all devices.
              </p>
            )}
          </>
        )}
        {form.formState.errors.root && (
          <p role="alert" className="text-sm text-red-600">
            {form.formState.errors.root.message}
            {reset && (
              <>
                {" "}
                <Link className="underline" href="/forgot-password">
                  Request a new reset link
                </Link>
              </>
            )}
          </p>
        )}
        <Button type="submit" disabled={form.formState.isSubmitting}>
          {form.formState.isSubmitting
            ? "Please wait…"
            : forgot
              ? "Send reset link"
              : reset
                ? "Reset password"
                : "Change password"}
        </Button>
      </fieldset>
      {message && (
        <p role="status" className="text-sm text-emerald-600">
          {message}
        </p>
      )}
      {!change && (
        <Link
          className="block text-sm text-primary hover:underline"
          href="/login"
        >
          Back to sign in
        </Link>
      )}
    </form>
  );
}
