"use client";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
export function useCrmWrite<TVariables, TData = unknown>(
  mutationFn: (variables: TVariables) => Promise<TData>,
  message: string,
  onSuccess?: (data: TData) => void,
) {
  const client = useQueryClient();
  return useMutation({
    mutationFn,
    onSuccess: (data) => {
      toast.success(message);
      onSuccess?.(data);
    },
    onError: (error) => toast.error(error.message),
    onSettled: () =>
      Promise.all([
        client.invalidateQueries({ queryKey: ["crm"] }),
        client.invalidateQueries({ queryKey: ["dashboard"] }),
        client.invalidateQueries({ queryKey: ["notifications"] }),
      ]),
  });
}
