import type { Envelope } from "@/types/auth";
const baseUrl = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public errors: Record<string, string[]> = {},
  ) {
    super(message);
  }
}
function csrfCookie() {
  if (typeof document === "undefined") return undefined;
  const value = document.cookie
    .split("; ")
    .find((row) => row.startsWith("XSRF-TOKEN="))
    ?.split("=")
    .slice(1)
    .join("=");
  return value ? decodeURIComponent(value) : undefined;
}
export async function request<T>(
  path: string,
  init: RequestInit = {},
): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  headers.set("X-Requested-With", "XMLHttpRequest");
  if (init.body) headers.set("Content-Type", "application/json");
  const token = csrfCookie();
  if (token) headers.set("X-XSRF-TOKEN", token);
  let response: Response;
  try {
    response = await fetch(`${baseUrl}${path}`, {
      ...init,
      headers,
      credentials: "include",
      cache: "no-store",
    });
  } catch {
    throw new ApiError(
      "Cannot reach SmartBiz. Check that the API is running.",
      0,
    );
  }
  const body = await response.json().catch(() => ({}));
  if (!response.ok)
    throw new ApiError(
      body.message ?? "The request could not be completed.",
      response.status,
      body.errors ?? {},
    );
  return body as T;
}
export async function csrf() {
  await request<void>("/sanctum/csrf-cookie");
}
export async function downloadCsv(path: string, filename: string) {
  let response: Response;
  try {
    response = await fetch(`${baseUrl}/api/v1${path}`, {
      credentials: "include",
      cache: "no-store",
      headers: {
        Accept: "text/csv, application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
    });
  } catch {
    throw new ApiError(
      "Cannot reach SmartBiz. Check that the API is running.",
      0,
    );
  }
  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    throw new ApiError(
      body.message ?? "The export could not be completed.",
      response.status,
      body.errors ?? {},
    );
  }
  const url = URL.createObjectURL(await response.blob());
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
export async function api<T>(path: string, init?: RequestInit) {
  return (await request<Envelope<T>>(`/api/v1${path}`, init)).data;
}
