"use client";
import Link from "next/link";
import { Loader2, ArrowLeft } from "lucide-react";
import { Button } from "@/components/ui/button";
export function CrmState({
  pending,
  error,
  retry,
  empty = false,
}: {
  pending?: boolean;
  error?: Error | null;
  retry?: () => void;
  empty?: boolean;
}) {
  if (pending)
    return (
      <div
        role="status"
        className="flex items-center gap-3 p-10 text-muted-foreground"
      >
        <Loader2 className="size-5 animate-spin" />
        Loading records…
      </div>
    );
  if (error)
    return (
      <div className="rounded-xl border border-border bg-card p-7">
        <p role="alert" className="mb-4 text-red-600">
          {error.message}
        </p>
        {retry && (
          <Button variant="outline" onClick={retry}>
            Try again
          </Button>
        )}
      </div>
    );
  if (empty)
    return (
      <div className="p-12 text-center">
        <h2 className="font-semibold">No matching records</h2>
        <p className="mt-2 text-sm text-muted-foreground">
          Try different filters, or create your first record if you have access.
        </p>
      </div>
    );
  return null;
}
export function PageHeading({
  title,
  description,
  action,
  back,
}: {
  title: string;
  description: string;
  action?: React.ReactNode;
  back?: string;
}) {
  return (
    <div className="mb-7 space-y-4">
      {back && (
        <Link
          className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-primary"
          href={back}
        >
          <ArrowLeft className="size-4" />
          Back
        </Link>
      )}
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">{title}</h1>
          <p className="mt-2 text-sm text-muted-foreground">{description}</p>
        </div>
        {action}
      </div>
    </div>
  );
}
export function Badge({ children }: { children: React.ReactNode }) {
  return (
    <span className="inline-flex rounded-full bg-primary/10 px-2.5 py-1 text-xs font-medium capitalize text-primary">
      {children}
    </span>
  );
}
export function Value({ value }: { value: string }) {
  return (
    <span className="tabular-nums">
      {new Intl.NumberFormat(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(Number(value))}
    </span>
  );
}
export function DateText({ value }: { value: string | null }) {
  return (
    <>
      {value
        ? new Date(
            value.length === 10 ? value + "T00:00:00" : value,
          ).toLocaleString(
            undefined,
            value.length === 10
              ? { year: "numeric", month: "short", day: "numeric" }
              : { dateStyle: "medium", timeStyle: "short" },
          )
        : "—"}
    </>
  );
}
