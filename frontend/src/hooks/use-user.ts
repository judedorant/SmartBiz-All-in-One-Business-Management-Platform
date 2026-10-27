"use client";
import { useQuery } from "@tanstack/react-query";
import { authApi } from "@/features/auth/api";
export const userKey = ["auth", "me"] as const;
export function useUser() {
  return useQuery({
    queryKey: userKey,
    queryFn: authApi.me,
    retry: false,
    staleTime: 60_000,
  });
}
