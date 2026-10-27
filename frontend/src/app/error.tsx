"use client";
import { Button } from "@/components/ui/button";
export default function ErrorPage({
  reset,
}: {
  error: Error;
  reset: () => void;
}) {
  return (
    <div className="m-10 rounded-xl border border-border bg-card p-8">
      <h1 className="text-xl font-semibold">Something went wrong</h1>
      <p className="my-4 text-muted-foreground">
        Please try loading this page again.
      </p>
      <Button onClick={reset}>Try again</Button>
    </div>
  );
}
