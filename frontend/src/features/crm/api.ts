import { api, request, csrf } from "@/lib/api";
import type { Page, Filters } from "@/types/crm";
export function queryString(filters: Filters = {}) {
  const q = new URLSearchParams();
  for (const [key, value] of Object.entries(filters))
    if (value !== undefined && value !== "") q.set(key, String(value));
  return q.toString();
}
export function list<T>(path: string, filters: Filters = {}) {
  return request<Page<T>>(`/api/v1${path}?${queryString(filters)}`);
}
export const get = api;
export async function write<T>(path: string, method: string, data?: unknown) {
  await csrf();
  return api<T>(path, {
    method,
    body: data === undefined ? undefined : JSON.stringify(data),
  });
}
