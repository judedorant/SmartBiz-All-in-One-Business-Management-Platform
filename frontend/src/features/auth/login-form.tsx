"use client";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { ArrowRight, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { ApiError } from "@/lib/api";
import { userKey } from "@/hooks/use-user";
import { loginSchema, type LoginValues } from "./schema";
import { authApi } from "./api";
import Link from "next/link";
export function LoginForm() {
  const router = useRouter();
  const client = useQueryClient();
  const {
    register,
    handleSubmit,
    formState: { errors },
    setError,
  } = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: "", password: "" },
  });
  const login = useMutation({
    mutationFn: authApi.login,
    onSuccess: (user) => {
      client.clear();
      client.setQueryData(userKey, user);
      toast.success("Welcome back, " + user.name);
      router.replace("/dashboard");
    },
    onError: (error) => {
      if (error instanceof ApiError)
        for (const key of ["email", "password"] as const)
          if (error.errors[key])
            setError(key, { message: error.errors[key][0] });
    },
  });
  return (
    <form
      onSubmit={handleSubmit((values) => login.mutate(values))}
      className="space-y-5"
      noValidate
    >
      <div>
        <label htmlFor="email" className="mb-2 block text-sm font-medium">
          Email address
        </label>
        <Input
          id="email"
          type="email"
          autoComplete="username"
          placeholder="you@company.com"
          aria-invalid={!!errors.email}
          aria-describedby={errors.email ? "email-error" : undefined}
          {...register("email")}
        />
        {errors.email && (
          <p
            id="email-error"
            role="alert"
            className="mt-2 text-sm text-red-600"
          >
            {errors.email.message}
          </p>
        )}
      </div>
      <div>
        <label htmlFor="password" className="mb-2 block text-sm font-medium">
          Password
        </label>
        <Input
          id="password"
          type="password"
          autoComplete="current-password"
          aria-invalid={!!errors.password}
          aria-describedby={errors.password ? "password-error" : undefined}
          {...register("password")}
        />
        {errors.password && (
          <p
            id="password-error"
            role="alert"
            className="mt-2 text-sm text-red-600"
          >
            {errors.password.message}
          </p>
        )}
      </div>
      {login.error && (
        <p
          role="alert"
          className="rounded-lg bg-red-500/10 p-3 text-sm text-red-600"
        >
          {login.error.message}
        </p>
      )}
      <Button className="w-full" disabled={login.isPending}>
        {login.isPending ? (
          <Loader2 className="size-4 animate-spin" />
        ) : (
          <ArrowRight className="size-4" />
        )}
        {login.isPending ? "Signing in…" : "Sign in"}
      </Button>
      <Link
        href="/forgot-password"
        className="block text-center text-sm text-primary hover:underline"
      >
        Forgot Password?
      </Link>
      <p className="text-center text-xs leading-5 text-muted-foreground">
        Need access or help with your password?
        <br />
        Contact your workspace administrator.
      </p>
    </form>
  );
}
