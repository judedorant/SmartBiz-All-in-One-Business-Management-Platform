"use client";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
export function useAutomationWrite<V, T = unknown>(
  mutationFn: (value: V) => Promise<T>,
  message: string,
  onSuccess?: (data: T) => void,
) {
  const client = useQueryClient();
  return useMutation({
    mutationFn,
    onSuccess: (data) => {
      toast.success(message);
      onSuccess?.(data);
    },
    onError: (error) => toast.error(error.message),
    onSettled: () => client.invalidateQueries({ queryKey: ["automation"] }),
  });
}
