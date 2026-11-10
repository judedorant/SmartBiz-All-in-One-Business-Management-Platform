"use client";
import { useMutation } from "@tanstack/react-query";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { write } from "@/features/crm/api";
export function SendPasswordReset({ id, name }: { id: number; name: string }) {
  const reset = useMutation({
    mutationFn: () => write(`/users/${id}/send-password-reset`, "POST"),
    onSuccess: () =>
      toast.success(
        "If this account is active, a reset link has been sent to its registered email.",
      ),
    onError: (error) => toast.error(error.message),
  });
  return (
    <Button
      type="button"
      variant="outline"
      size="sm"
      className="ml-2"
      aria-label={`Send Password Reset to ${name}`}
      disabled={reset.isPending}
      onClick={() => reset.mutate()}
    >
      {reset.isPending ? "Sending…" : "Send Password Reset"}
    </Button>
  );
}
