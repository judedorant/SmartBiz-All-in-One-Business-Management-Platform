import { it, expect, vi, afterEach } from "vitest";
import { authApi } from "@/features/auth/api";
import { request, ApiError, downloadCsv } from "@/lib/api";
afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  document.cookie = "XSRF-TOKEN=; Max-Age=0";
});
it("downloads CSV using session cookies and releases its temporary link", async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValue(
      new Response("Month,Amount\r\n2026-10,12.34", {
        headers: { "Content-Type": "text/csv" },
      }),
    );
  vi.stubGlobal("fetch", fetcher);
  const createObjectURL = vi.fn().mockReturnValue("blob:test");
  vi.stubGlobal(
    "URL",
    class extends URL {
      static createObjectURL = createObjectURL;
      static revokeObjectURL = vi.fn();
    },
  );
  const click = vi
    .spyOn(HTMLAnchorElement.prototype, "click")
    .mockImplementation(() => {});
  await downloadCsv("/reports/export?report=sales", "sales.csv");
  expect(fetcher).toHaveBeenCalledWith(
    "http://localhost:8000/api/v1/reports/export?report=sales",
    expect.objectContaining({ credentials: "include", cache: "no-store" }),
  );
  expect(createObjectURL).toHaveBeenCalledWith(expect.any(Blob));
  expect(click).toHaveBeenCalledOnce();
  expect(document.querySelector('a[download="sales.csv"]')).toBeNull();
});
it("surfaces export authorization failures instead of saving them as CSV", async () => {
  vi.stubGlobal(
    "fetch",
    vi
      .fn()
      .mockResolvedValue(
        Response.json({ message: "Unauthenticated." }, { status: 401 }),
      ),
  );
  const click = vi
    .spyOn(HTMLAnchorElement.prototype, "click")
    .mockImplementation(() => {});
  await expect(
    downloadCsv("/reports/export?report=sales", "sales.csv"),
  ).rejects.toMatchObject({ status: 401, message: "Unauthenticated." });
  expect(click).not.toHaveBeenCalled();
});
it("sends cookie credentials and decoded CSRF token after bootstrapping login", async () => {
  document.cookie = "XSRF-TOKEN=csrf%3Dvalue";
  const fetcher = vi
    .fn()
    .mockResolvedValueOnce(new Response(null, { status: 204 }))
    .mockResolvedValueOnce(Response.json({ success: true, data: { id: 1 } }));
  vi.stubGlobal("fetch", fetcher);
  await authApi.login({ email: "user@example.test", password: "secret" });
  expect(fetcher.mock.calls[0][0]).toBe(
    "http://localhost:8000/sanctum/csrf-cookie",
  );
  const [url, options] = fetcher.mock.calls[1];
  expect(url).toBe("http://localhost:8000/api/v1/login");
  expect(options.credentials).toBe("include");
  expect(options.headers.get("X-XSRF-TOKEN")).toBe("csrf=value");
  expect(options.headers.get("Authorization")).toBeNull();
});
it("bootstraps CSRF and posts logout", async () => {
  const fetcher = vi
    .fn()
    .mockResolvedValueOnce(new Response(null, { status: 204 }))
    .mockResolvedValueOnce(Response.json({ success: true, data: null }));
  vi.stubGlobal("fetch", fetcher);
  expect(await authApi.logout()).toBeNull();
  expect(fetcher.mock.calls[1][0]).toBe("http://localhost:8000/api/v1/logout");
  expect(fetcher.mock.calls[1][1].method).toBe("POST");
});
it("preserves validation errors and response status", async () => {
  vi.stubGlobal(
    "fetch",
    vi
      .fn()
      .mockResolvedValue(
        Response.json(
          { message: "Validation failed", errors: { email: ["Invalid"] } },
          { status: 422 },
        ),
      ),
  );
  await expect(request("/api/v1/login")).rejects.toMatchObject({
    status: 422,
    errors: { email: ["Invalid"] },
  });
});
it("turns network failures into a safe actionable message", async () => {
  vi.stubGlobal(
    "fetch",
    vi.fn().mockRejectedValue(new TypeError("network internals")),
  );
  await expect(request("/api/v1/me")).rejects.toBeInstanceOf(ApiError);
  await expect(request("/api/v1/me")).rejects.toMatchObject({
    status: 0,
    message: "Cannot reach SmartBiz. Check that the API is running.",
  });
});
