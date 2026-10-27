# API v1 — Phases 1–4

Base URL: `http://localhost:8000/api/v1`. Send `Accept: application/json`. First-party requests use `credentials: include` and originate from the configured frontend origin. All write requests require the decoded `XSRF-TOKEN` cookie in the `X-XSRF-TOKEN` header.

| Method | Path | Access / result |
| --- | --- | --- |
| GET | `/sanctum/csrf-cookie` (outside API prefix) | 204; establishes CSRF/session cookies |
| POST | `/login` | email + password; active accounts only; 200 user data; 422 invalid credentials/validation; 429 rate limited |
| GET | `/me` | Authenticated active user; 200 profile; 401 anonymous; 403 inactive account with session invalidation |
| POST | `/logout` | Authenticated active user; 200 null data; invalidates session and records audit |
| GET | `/dashboard/access` | `dashboard.view`; current effective permission list |
| GET | `/team/access` | `tasks.assign`; Manager/Admin permission boundary; no task module operations |
| GET | `/admin/access` | UserPolicy viewAny → `users.manage`; Admin permission boundary; no user CRUD operations |
| GET | `/up` (outside API prefix) | Laravel process health; does not assert database health |

`/login` JSON: `{ "email": "your configured address", "password": "your local password" }`.

Success: `{ "success": true, "message": "Authenticated user", "data": { "id": 1, "name": "...", "email": "...", "status": "active", "last_login_at": "UTC ISO-8601 or null", "roles": ["Staff"], "permissions": ["dashboard.view"] } }`.

Errors: `{ "success": false, "message": "Validation failed", "errors": { "email": ["..."] } }`. Non-validation errors have an empty object. 401 means unauthenticated, 403 means forbidden, 419 means CSRF/session expired, 422 means invalid input, 429 means rate limited. 500 responses never expose exception details or traces, even with debug enabled. Appropriate Retry-After headers are preserved.

Login limits: 5 attempts/minute per normalized email+IP, plus 30/minute per IP. Versioned API routes additionally have a 120/minute user-or-IP limit. Persistent database cache backs production/local throttling; tests use independent array cache.

Task management, integration, and report routes are not implemented yet. Route names use the `api.v1.` prefix. Future list endpoints will include pagination, permitted filters, search, and sorting when those modules exist.

## CRM endpoints

All paths below are relative to `/api/v1`, require an active authenticated session, and require CSRF headers on writes. Invisible records return 404. Insufficient operation permissions return 403. Validation returns 422; stale version conflicts and disallowed conversion/link changes return 409.

| Method | Path | Purpose |
| --- | --- | --- |
| GET / POST | `/customers` | Scoped paginated list / Manager+ creation |
| GET / PUT / PATCH / DELETE | `/customers/{id}` | View / Manager+ edit / Manager+ soft delete |
| GET | `/customers/{id}/activities` | Paginated customer history |
| GET / POST | `/customers/{id}/notes` | Paginated notes / Manager+ add note |
| PUT / DELETE | `/customers/{id}/notes/{noteId}` | Manager+ edit/delete a note scoped to this customer |
| GET / POST | `/leads` | Scoped paginated list / Manager+ creation in New stage |
| GET / PUT / PATCH / DELETE | `/leads/{id}` | View / permitted edit / Manager+ soft delete |
| PATCH | `/leads/{id}/stage` | `{ "pipeline_stage_id": 3, "version": 1 }` |
| POST | `/leads/{id}/convert` | Manager+ transactional customer creation and lead linking |
| GET / POST | `/leads/{id}/activities` | Scoped paginated history / permitted activity note |
| GET | `/pipeline-stages` | Fixed ordered stage configuration |
| GET | `/crm/assignees` | Manager+ paginated active users, exposing id and name only |

### Customer payload

```json
{
  "first_name": "Ada",
  "last_name": "Lovelace",
  "email": "ada@example.test",
  "phone": null,
  "company": "Analytical Works",
  "address": null,
  "status": "active",
  "assigned_user_ids": [3]
}
```

The example is documentation only, not seeded production data. Names, status, and the assignment array are required (an empty array is valid). Email/phone/company/address are nullable. Email is trimmed/lowercased and unique, including archived records. Updates additionally require `version`. Do not supply customer_code or created_by. A DELETE request sends `{ "version": 1 }`. Notes and custom lead activity accept `{ "body": "..." }`, limited to 10,000 characters.

### Lead payload

```json
{
  "title": "Support agreement",
  "source": "Referral",
  "estimated_value": "1200.50",
  "assigned_to": 3,
  "customer_id": null,
  "expected_close_date": "2027-01-15",
  "notes": "Discuss scope."
}
```

Title, source, and non-negative value are required. Managers supply an active assigned_to user; customer_id must refer to a non-archived customer or be null. Updates require version. Staff must omit assigned_to and customer_id and may edit only their own leads. Status and pipeline_stage_id cannot be changed through this payload; use the dedicated stage endpoint. Resources return stage, status, a minimal assignee, a minimal visible customer summary, conversion time, and decimal value as a string.

Conversion accepts the customer payload plus the lead's current version. The owner is retained as assigned staff even when not listed. It returns the updated lead with customer_id, version, and converted_at (201). Only unlinked leads can convert; there is no automatic merge by email. Converted customer links are immutable. Conversion does not imply Won or create a sale.

### Lists and query parameters

Lists return `{ "success": true, "message": "...", "data": [], "links": {}, "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 0, "...": "Laravel pagination metadata" } }`. The example total is illustrative. Filters, search, and totals always apply after authorization scope. Default page size is 20; accepted range is 1–100.

- Customers: search name/email/company/phone/code, status=active|inactive, assigned_to, page, per_page, direction=asc|desc, sort=created_at|updated_at|first_name|last_name|company.
- Leads: search title/source, assigned_to, customer_id, pipeline_stage_id, page, per_page, direction=asc|desc, sort=created_at|updated_at|title|estimated_value|expected_close_date.
- Notes/activities: page and per_page, newest first.
- Assignees: search name, page, per_page; active accounts only.

Sort columns/directions are allowlisted, query values are parameter-bound, and a stable ID tie-breaker is applied. CRM clients should refresh and obtain a current version after a 409, rather than automatically resubmitting stale edits. Create/update return 201/200; soft deletes return 200 with null data. Note deletion is permanent and requires confirmation in the UI; customer/lead deletion archives records.

## Dashboard endpoints

`GET /dashboard/summary` and `GET /dashboard/analytics` require `dashboard.view` and apply record-level visibility. Both accept:

| Parameter | Contract |
| --- | --- |
| preset | today, last_7_days, last_30_days (default), this_month, custom |
| timezone | Valid IANA/browser-compatible timezone; defaults UTC |
| currency | USD (default), EUR or GBP; configured in backend/config/smartbiz.php |
| start_date / end_date | YYYY-MM-DD, required only for custom; 1900-01-01 through 2100-12-31; inclusive, ordered, at most 366 days |

Example: `/dashboard/summary?preset=custom&start_date=2026-10-01&end_date=2026-10-26&timezone=America%2FNew_York&currency=USD`. Invalid filters return 422. Custom date fields on non-custom presets are rejected. There is no all-time/unbounded analytics endpoint.

Both responses return the standard envelope. Their data.filters gives resolved dates, preset, timezone, currency, available_currencies and generated_at. Summary data.metrics includes total_customers, active_leads, qualified_leads, won_deals, total_recorded_sales (decimal string), pending_tasks, upcoming_appointments, overdue_tasks. data.lead_conversion includes total, won, lost, open and won_rate. See architecture.md for the exact date/status basis of each metric.

Analytics data contains monthly_sales `{month, amount}`, customer_growth `{date, new_customers, total_customers}`, task_completion `{date, created, completed}`, lead_conversion `{name, count}`, and pipeline `{id, name, slug, count, estimated_value}` arrays. Revenue is filtered by currency before SUM. Pipeline estimates are CRM amounts without an FX conversion model; they are never substituted for recorded revenue. Daily/monthly zero buckets represent missing database aggregate rows. Partial first/last months include only the requested calendar range.

## Recorded sales

| Method | Path | Contract |
| --- | --- | --- |
| GET | `/sales/options` | Permitted sales readers; available currencies and default |
| GET | `/sales` | Scoped paginated ledger; filters currency/status/customer_id; sort closed_at/amount/created_at; asc/desc; page/per_page 1–100 |
| POST | `/sales` | `sales.manage`; create a manually recorded completed sale |
| PATCH | `/sales/{id}/void` | `sales.manage`; `{ "version": 1, "reason": "Correction explanation" }` |

POST body:

```json
{
  "customer_id": 10,
  "lead_id": null,
  "amount": "1200.50",
  "currency": "USD",
  "closed_at": "2026-10-20T12:00:00Z",
  "request_id": "d909d0ec-28ad-4cdd-8d02-8805464fa3d5"
}
```

This documentation example is not seeded data. customer_id must refer to a non-archived customer. Optional lead_id must refer to a non-archived Won lead linked to the same customer. Amount is strictly positive with at most two decimal places and a DECIMAL(15,2) limit. closed_at must be canonical UTC seconds with Z, not in the future. The actor and completed status are server-controlled; recorded_by/status/voided_by payloads are rejected.

Generate a new UUID for each genuine sale and retain it for retries. An identical valid payload with the same UUID/actor returns the existing sale (200); a new record returns 201; reuse with different data/actor returns 409. More than one genuine transaction can reference a lead. There is no automatic sale from a Won stage change and no payment-provider call.

Resources expose safe customer/lead summaries and recorder id/name, plus amount, currency, status, version and close/void metadata. The request UUID and user private fields are not exposed. Staff read only sales tied to their current assigned leads and visible customers; they cannot record or void. Managers/Admins can see retained sales even when related CRM records are archived. Stale/already-voided writes return 409. Voided entries are preserved and excluded from revenue. No sale-edit/delete endpoint exists.

## Appointment scheduling

All routes require an active authenticated session. Managers/Admins use appointments.manage for writes. Staff uses appointments.view_assigned for read-only access to assigned records. Invisible records return 404; forbidden writes return 403. POST/PUT/PATCH/DELETE require the existing cookie/CSRF flow.

| Method | Path | Contract |
| --- | --- | --- |
| GET / POST | `/appointments` | Paginated scoped list / create booking |
| GET / PUT / PATCH / DELETE | `/appointments/{id}` | View / full edit / archive |
| PATCH | `/appointments/{id}/reschedule` | start_at, end_at, version; active appointments only |
| PATCH | `/appointments/{id}/cancel` | version; marks Cancelled and releases slot |
| GET | `/appointments/calendar` | Required UTC start_at/end_at, optional assigned_to; overlapping visible events; at most 62 days (+ DST allowance), at most 1,000 events |
| GET | `/appointments/staff` | Management-only paginated active users with appointment permissions; optional name search; id/name only |
| GET / PUT | `/staff/{userId}/availability` | Managers read/edit any staff schedule; Staff reads own only |

Create/full-edit body:

```json
{
  "title": "Discovery meeting",
  "description": "Discuss project scope",
  "customer_id": 10,
  "assigned_to": 3,
  "start_at": "2026-11-02T10:00:00Z",
  "end_at": "2026-11-02T11:00:00Z",
  "status": "scheduled"
}
```

Examples are documentation only, not seeded appointments. New booking status is Scheduled or Confirmed. Full edits additionally require version and can set Completed/Cancelled. created_by/reminder_sent_at are server-owned and prohibited. Dates are canonical UTC seconds with Z. Active bookings must be future, within one year and 5 minutes–8 hours, with an active eligible owner and a permitted non-archived customer. They must fit one configured staff working window. Completed appointments cannot move/reopen or be cancelled; they cannot be marked completed before their end. Cancelled bookings can be reopened through a validated full edit. Archiving preserves the row and its audit history.

List parameters: search title/description, status, assigned_to, customer_id, page/per_page (1–100), sort=start_at|created_at|title, direction=asc|desc. Default sorting is start_at ascending. Calendar ranges use overlap rather than start-only filtering; the end bound is exclusive. An oversized/dense feed returns 422 with a field error asking for narrower dates/staff filters. It returns the standard envelope with an array of safe AppointmentResources; lists use normal pagination metadata. Conflicts and stale versions return 409 without changing the booking. Staff cannot use calendar drag/resize to bypass write permission.

Availability PUT:

```json
{
  "version": 0,
  "timezone": "America/New_York",
  "windows": [
    {"day_of_week": 1, "start_time": "09:00", "end_time": "12:00"},
    {"day_of_week": 1, "start_time": "13:00", "end_time": "17:00"}
  ]
}
```

version 0 creates a missing header; later writes require its current version. At most 42 windows, valid IANA timezone (including browser aliases), and HH:mm local times are accepted. Window end must be after start; same-day overlaps and overnight windows are rejected. An empty array means unavailable all week. A schedule update that excludes an upcoming active booking returns 409 and rolls back. GET returns `{user_id, timezone, version, windows}` and does not persist defaults. Staff reads of another user's schedule return 404.

Reminders are queued internally, not sent by a public API. Staff email processing occurs within 30 minutes of start, with version/current-state checks, three attempts and retained execution state. Mail transport configuration is required for external delivery; log mail is local-only. This phase has no Google connection API; optional Google OAuth/sync belongs to Phase 6.

## Tasks and comments — Phase 5

All paths below are under `/api/v1`, require an active Sanctum session, and use CSRF on mutations. Manager/Admin (`tasks.assign`) can create, reassign, edit and archive any task. Staff (`tasks.manage_assigned`) can read/update assigned tasks only, with no ownership/link changes or archive permission. Invisible tasks return 404. Unauthorized creation/archive returns 403; prohibited fields return 422.

| Method | Path | Contract |
| --- | --- | --- |
| GET / POST | `/tasks` | Scoped paginated list / manager creation |
| GET / PUT / PATCH / DELETE | `/tasks/{id}` | Detail / full edit / soft archive |
| PATCH | `/tasks/{id}/status` | status and version |
| GET | `/tasks/staff` | Management-only eligible active users, paginated name search; id/name only |
| GET / POST | `/tasks/{id}/comments` | Paginated comments / add comment |
| PUT / DELETE | `/tasks/{id}/comments/{commentId}` | Edit / delete nested comment using its version |

Create/full-edit example (documentation only):

```json
{
  "title": "Prepare follow-up",
  "description": "Review the agreed next steps",
  "assigned_to": 3,
  "customer_id": null,
  "lead_id": null,
  "priority": "high",
  "status": "pending",
  "due_date": "2026-11-02T12:00:00Z"
}
```

Title (max 200), priority and status are required on full edits; updates additionally require version. Description is nullable (max 10,000); due_date is nullable canonical UTC seconds with Z, and can be past. Staff must omit assigned_to/customer_id/lead_id entirely. Manager edits can omit unchanged assignment/link fields. Owner must be active and have task access when created/changed. Nullable lead/customer links must be visible, non-archived and consistent. A lead's linked customer is inferred if no customer is specified. Clear both fields to unlink both. created_by/completed_at are prohibited, server-owned fields.

Statuses: pending, in_progress, completed, cancelled. Priorities: low, medium, high, urgent. Transitions are allowed between all four statuses. Entering completed sets completed_at; reopening clears it. Descriptive edits preserve completion time. Overdue means a due date before now on a pending/in_progress task. API resources include is_overdue, UTC dates, version, safe owner/creator summaries, and scoped customer/lead summaries. Hidden/archived links return null summaries with retained FK IDs. Task assignment does not grant permission to read/edit an unrelated lead.

List filters: search title/description (max 100), status, priority, assigned_to, customer_id, lead_id, overdue=1. Pagination page/per_page (1–100); sort=due_date|priority|title|created_at|updated_at and direction=asc|desc. Default due_date ascending, nulls last; priority uses low < medium < high < urgent, with stable ID tie-breaking. Archive DELETE requires version and preserves comments/audits. Version conflicts are 409 with no side effects.

Comment create: `{ "comment": "Follow-up complete" }` (1–5,000 characters). Edit adds the comment's current version; delete requires that version. Staff can change only their own comments on currently assigned tasks; Manager/Admin can moderate all. Nested IDs are constrained to their parent. Comment authors and parent IDs cannot be supplied. Comments return author id/name, version and timestamps; text is rendered as escaped plain text in the frontend.

## In-app notifications

| Method | Path | Contract |
| --- | --- | --- |
| GET | `/notifications` | Own notifications, page/per_page (1–100), optional unread=1 |
| GET | `/notifications/unread-count` | `{ "unread_count": 0 }` in standard data envelope |
| PATCH | `/notifications/{uuid}/read` | Mark one owned notice read, idempotently |
| POST | `/notifications/read-all` | Mark only the current user's unread notices read |

A notification resource contains id, kind, fixed message, nullable task_title/action_url, read_at, created_at. Context is re-authorized at read time; inaccessible/archived tasks return null title/link. Stored notification data and ownership internals are never exposed. Action links are server-generated `/tasks/{id}` paths. Another user's notification UUID returns 404. Unknown types serialize as generic workspace notices. Mark-read endpoints return success/data:null. Task writes and database notices are atomic; no external email delivery is part of these endpoints.

## Phase 6 automation, integrations and webhooks

Every management route requires an active session plus automations.manage or integrations.manage. Existing canonical roles grant these only to Admin. Mutation CSRF remains enabled except the signature-authenticated receive route. Responses follow the existing success/message/data envelope and pagination metadata.

| Method | Path under /api/v1 | Contract |
| --- | --- | --- |
| GET | /automations/catalog | Fixed trigger/action/placeholder allowlists |
| GET / POST | /automations | Paginated workflow list / validated creation |
| PUT / PATCH / DELETE | /automations/{id} | Full versioned edit / soft archive (DELETE requires version) |
| PATCH | /automations/{id}/active | is_active boolean and version; no need to resubmit provider settings |
| GET | /automations/{id}/logs | Retained paginated execution/step statuses, attempts and safe error codes |
| POST | /automations/{id}/logs/{log}/retry | Only known-safe failed steps; uncertain outcomes return 409 |
| GET | /integrations | Google readiness, local/provider mail mode, supported services, own safe account metadata |
| POST | /integrations/google/{provider}/connect | SPA session required; returns Google consent URL with PKCE/state |
| DELETE | /integrations/{id} | Disconnect own account, clear local credentials; returns revocation_confirmed |
| GET / POST | /webhooks | Paginated endpoint list / endpoint creation |
| PUT / DELETE | /webhooks/{id} | Versioned update / archive; direction immutable |
| GET | /webhooks/{id}/deliveries | Paginated safe delivery metadata, no body or secret |
| POST | /webhooks/{id}/receive | Public signed JSON receive; timestamp, HMAC, UUID and replay checks |

Google callback is `GET /integrations/google/callback` outside /api/v1 to use Laravel's web-session middleware for the top-level OAuth redirect. It consumes one user/session-bound state, exchanges the code, validates identity/scope, and redirects only to configured frontend `/settings/integrations?oauth=connected|failed`. No tokens/codes are returned to frontend JavaScript.

Workflow POST/full-edit requires name (max 150), trigger_type from catalog, is_active boolean, configuration with 1–8 actions. Full edits require version and the original creator. Configuration permits only actions and optional lead_status (stage-change triggers only). Each action accepts only its type-specific settings; unsupported actions, executable/unknown placeholders, cross-owner Google accounts, wrong service grants and unknown settings return 422. Optional creator/owner/version inputs cannot bypass backend policies. New records return 201, updates 200, stale writes 409. Disabling/archiving stops remaining work while retaining logs.

Workflow lists support search, is_active, sort=name|created_at, direction, page/per_page (1–100). Logs support execution_status, page/per_page. Logs include workflow version and safe trigger/source IDs in execution_data, plus step index/type/status/attempts/retryable/error_code; encrypted configuration is absent. Webhook and delivery lists validate pagination. Archived workflow/endpoint IDs remain usable for authorized history APIs.

Webhook create requires name, direction=inbound|outbound, is_active, and a write-only 32–256 character secret. Outbound also requires a public approved HTTPS endpoint_url. Updates require version and omit secret to retain it. Resources return safe metadata and a generated inbound receive URL, never secrets. Public receive is size/rate limited and accepts a fixed webhook_received trigger; it cannot select an internal CRM event or mutate arbitrary records. See [automation operator guide](automation.md) for exact signing input, headers, action schemas, OAuth scopes, Calendar lifecycle setup, retries and deployment limitations.
