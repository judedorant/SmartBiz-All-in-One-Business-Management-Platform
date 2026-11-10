# Architecture

Browser → Next.js client API wrapper → Laravel `/api/v1` → Eloquent → MySQL.

Next.js App Router supplies the root and login pages with Server Components. The dashboard layout, forms, and account views are Client Components because they use browser cookies, interactive controls, and TanStack Query. The browser never accesses MySQL. Protected content is withheld until `/me` succeeds; all API authorization is independently enforced by Laravel. The static output for `/dashboard` is an unauthenticated shell with no user records embedded.

## Authentication

1. Browser fetches `/sanctum/csrf-cookie` with credentials.
2. Laravel issues a readable XSRF cookie and an encrypted HTTP-only session cookie.
3. The API wrapper decodes the XSRF cookie and sends `X-XSRF-TOKEN` on POST requests.
4. Login validates credentials and active status, hashes are checked by Laravel, and the session ID is regenerated.
5. `/me` exposes an explicit UserResource allowlist, role names, effective permissions, and UTC last-login time.
6. Logout invalidates the session and rotates its CSRF token. The frontend clears all cached private data.

An inactive user is refused on existing authenticated sessions as well as at login. Session authentication is first-party only: no token issuing endpoint, bearer tokens, or localStorage token storage is implemented. CORS allows a single configured frontend origin. CSRF middleware remains enabled; tests include a request with Laravel's testing bypass disabled, and the live smoke check verifies actual HTTP enforcement.

## Authorization

Spatie roles use the `web` guard, shared by Sanctum's session authentication. Every role has `dashboard.view`. Staff permissions are restricted to assigned-resource abilities for future modules. Manager additionally has CRM/team management permissions. Admin additionally has user/role, automation, integration, and full reporting permissions. Policy discovery connects `UserPolicy` to `User`; the admin boundary route invokes that policy. CRM now uses CustomerPolicy and LeadPolicy, with query scopes applied before fetching individual records or paginated lists. Later modules must implement equivalent record scoping.

Authentication remains straightforward without repository wrappers. LeadService owns multi-step conversion, stage changes, transactional writes, row locks, optimistic version checks, and activity records. Customer writes use short transactions in the controller; no generic repository layer is introduced.

## Version decisions

The dependency solver selected Laravel 13.38, Sanctum 4.3, Spatie Permission 8.3, and PHPUnit 12.5, compatible with PHP 8.4.22. Next.js 16.4 and React 19.3 run on Node 24.19. Exact resolved versions are recorded in lockfiles. TypeScript 5.9 retains the compiler API; Next.js uses that API via `experimental.useTypeScriptCli=false` because CLI-output parsing failed in this runtime. Standalone `tsc --noEmit` and Next.js's build type check both remain enabled. Revisit this switch on framework upgrades.

ESLint 9.39.5 is pinned because the React, import, and accessibility plugins bundled with the selected Next.js config do not declare ESLint 10 compatibility. npm emits an ESLint 9 deprecation warning; upgrading the lint toolchain is tracked as tooling debt, rather than forcing incompatible peer dependencies. Vitest 3 and TypeScript 5 are retained as the tested toolchain.

Laravel Boost is a development-only dependency installed as required by the generated scaffold's AGENTS.md. The API does not require a Vite build. Recharts 3.10 and its compatible React-is 19 peer power Phase 3 charts. FullCalendar 6.1.22 powers Phase 4 scheduling. Phase 6 uses Laravel’s HTTP client for Google REST APIs without additional SDK packages. Phase 7 CSV exports use native PHP CSV serialization without additional packages.

## Runtime boundaries

MySQL is the default in application configuration and environment templates. PHPUnit explicitly selects SQLite `:memory:` for fast, independent tests. SQLite results cannot validate MySQL locking, concurrency, collation, or query plans. Phase 1 migrations, seeding, and real session requests were separately exercised against native MySQL. All stored timestamps use UTC; the frontend converts last-login display with the browser timezone.

## Phase 2 CRM rules

- The app currently serves one organization. Managers/Admins can manage all CRM data; Staff can update only their assigned leads. Customer visibility comes from an explicit staff pivot assignment **or** a non-deleted lead/appointment/task assigned to the user. Reassigning/archiving the last linked lead/appointment/task revokes that derived customer access unless another qualifying relationship remains.
- Customer notes and history inherit customer visibility; Staff cannot change notes. A customer detail's linked-lead list is independently lead-scoped, so access to a customer never reveals coworkers' leads. Nested note writes constrain both customer and note IDs.
- Customer and lead deletion archives rows through soft deletes. Activities remain for audit. Archiving a customer preserves linked leads but hides customer details; lead resources then return a null customer summary and the retained FK. Archived email addresses remain reserved by the unique constraint.
- The fixed stage table has seven entries seeded idempotently. `pipeline_stage_id` is the sole stored stage; the API derives `status` from its slug. This avoids inconsistent status/stage columns. Any of the seven stages can transition to any other, including reopening won/lost leads. Won/lost changes do not create sales transactions.
- Multi-step writes use transactions and bounded deadlock retries. Lead mutation queries lock the row and re-check authorization after the lock. Customer/lead edits, deletes, and stage changes compare the submitted version under a row lock. Stale changes return 409 without overwriting the winning write. A no-op stage move does not increment version or add an activity.
- Converting an unlinked lead creates a customer, assigns selected staff plus the lead owner, links the lead, timestamps conversion, and writes both histories in one transaction. It does not change the stage or record a sale. A linked/converted lead cannot be converted again; a converted lead's customer link cannot be changed. A non-converted lead can instead be linked to an existing customer through editing.
- Estimated values use DECIMAL(15,2), serialize as strings, and accept at most two decimal places. There is no multi-currency accounting feature in Phase 2.
- The pipeline fetches independently paginated stages rather than silently truncating the whole board. Counts come from each authorized list's total metadata. Drag/drop and keyboard/touch stage selectors use the same endpoint. Failed/stale changes display errors and refetch; the UI does not pretend failed moves succeeded.

## Phase 3 analytics

`DashboardService` applies existing visibility scopes before aggregation, with no frontend-side record filtering. Managers/Admins see team data, while Staff sees their permitted customers and assigned leads/tasks/appointments. Sale visibility for Staff additionally requires a currently visible assigned lead and customer; unlinked sales are management-only. New `sales.manage` permissions grant recording/voiding to canonical Manager and Admin roles. Default role seeding now creates 16 permissions.

`DateRange` converts validated local calendar dates to UTC half-open bounds `[start, next-day start)`. Today/last-7/last-30 include the current local date; This month runs from its first day through today. Custom dates are inclusive, bounded to 1900–2100 and at most 366 days. The timezone rule accepts browser aliases and backward-compatible IANA identifiers. Local boundaries are recalculated at each day/month, including midnight DST changes. MySQL connections explicitly use UTC. No database timezone tables are required.

Daily/monthly data is grouped inside SQL through a bounded CASE expression over UTC bucket ends. The number of SQL queries is independent of the range's day count. PHP fills missing calendar buckets from aggregate results rather than loading entire business tables or issuing a query per day. Revenue uses database DECIMAL SUM results and Brick Math decimal-string normalization; financial text remains exact through a BigInt-based whole-part formatter. Recharts alone receives floating-point plotting coordinates; its sales tooltip and data table retain the original exact decimal strings.

Metric definitions:

- Total customers: currently visible, non-archived customers created in range (all active/inactive customer statuses).
- Active leads: visible non-archived leads created in range whose current stage is neither Won nor Lost. Qualified/Won counts use the current stage of that same cohort. Won rate = won / all cohort leads, not won / closed leads. This is a current-outcome snapshot, not a historical stage-transition report.
- Recorded sales: completed ledger entries with close time in range and the selected currency. Archived customers/leads do not erase management financial records; Staff still requires current record visibility.
- Pending tasks: visible non-archived tasks created in range with Pending/In Progress status. Overdue is the subset with a non-null UTC due time before the request's current time. This is a creation cohort, not the all-time backlog.
- Upcoming appointments: visible, non-archived Scheduled/Confirmed appointments starting within the range and at or after the request time. Past and Cancelled/Completed appointments are excluded.
- Customer growth starts with the current visible customer count created before the range, then adds daily creations. Archiving/reassignment changes the currently visible population, so this is not a historical ownership snapshot.
- Task completion series groups creations by created_at and currently Completed tasks by completed_at. Completions can belong to older tasks; no misleading completion ratio is inferred from mismatched cohorts.

Sales recording uses a client-generated request UUID. The server serializes same-actor submissions, returns the existing entry for identical valid payloads, and returns 409 for identifier reuse with changed data. An optional lead must be Won and linked to the selected live customer; both records are checked under locks. Multiple genuine transactions may reference the same lead (installments, for example). Amount/currency/customer/close-time are immutable once recorded. Voiding requires management permission, a current version and a reason, and writes an audit record. No deletion or payment-provider capture is implemented; this is a manually recorded sales ledger, not accounting software.

At the Phase 3 checkpoint task/appointment models were analytics foundations; Phase 4 scheduling is documented below. Phase 5 task workflows are documented below. No source table is populated with automatic business demo data. CRM and sales writes invalidate dashboard query caches in the frontend; changing the date/currency produces a distinct query key.

## Phase 4 booking transactions

AppointmentService applies AppointmentPolicy, optimistic versions, fixed status/duration/horizon rules, and customer/owner checks. Staff reads only assigned non-archived appointments. Managers/Admins perform writes. Staff gains customer visibility through assigned appointments, consistently with the existing lead-derived visibility model. Reminder delivery does not grant additional record access.

Every appointment write locks the affected staff user row(s), ordered by ID, before locking the appointment and checking conflicts. This serializes the first booking even when the staff member has no appointment rows. Conflict checks use half-open intervals (`existing.start < proposed.end AND existing.end > proposed.start`), excluding the edited row and Cancelled/Completed/archived entries. Adjacent bookings are valid. Live customer checks and availability header/windows use locking reads as well.

**MySQL repeatable-read detail:** a mutex by itself is insufficient if an outer transaction already established an older consistent-read snapshot before waiting for that mutex. The initial two-process test exposed two successful inserts in this situation. The final overlap check uses a locking current read (`SELECT ... FOR UPDATE`), as do availability and existing-booking checks, so it sees committed changes after the wait. The regression test deliberately opens the outer transaction before contention. Creation and rescheduling cannot bypass the same service rules; full edits, cancellation and archiving increment version. Deadlocks have bounded transaction retries.

Availability uses one schedule header per user (timezone + version) and multiple weekly windows. Window timezone is normalized in the header. Windows must be non-overlapping within a day; overnight windows are unsupported. Booking must fit one window on one staff-local date. Availability changes share the staff lock and reject changes that would exclude active upcoming appointments, rolling the change back. DST transition checkpoints inside a booking are evaluated as well as its endpoints, preventing a fall-back interval from escaping a narrow working window. Unchanged datetime-local form values preserve their original UTC instant during repeated DST hours.

The calendar feed takes a bounded visible range and caps results at 1,000 with an explicit validation error; it never silently truncates. A paginated list remains available for search/filter/sort and accessible record navigation. Calendar times display in the browser timezone, while working windows use the configured staff timezone. All persisted event timestamps remain UTC. FullCalendar 6.1.22 is selected as a matched React/core/daygrid/timegrid/interaction package family; the registry's newer React wrapper did not have matching separate plugin releases.

## Phase 4 reminder pipeline

Laravel Scheduler runs `smartbiz:appointment-reminders` each minute. It scans active appointments starting within 30 minutes and inserts a unique appointment+version reminder record. The ShouldBeUnique queued job uses the same staff/appointment locking order, re-reads current state, and sends a Laravel mail notification only when the appointment/version/assignee is still eligible. Sent records and reminder_sent_at suppress normal repeat delivery. Cancellation, archive, inactive/revoked staff, expired starts and superseded versions are skipped. Time/owner changes reset the marker; descriptive changes do not resend a known-sent reminder.

Jobs retry up to three times with 60/300/900-second backoff and a 45-second timeout. Retryable transport failures retain pending state and a generic code; terminal failures are tracked and can be retried through Laravel's failed-job tooling. Raw provider exception messages are replaced before reaching failed-job logs. Mail dispatch is inside the booking lock to prevent a concurrent cancellation from racing the final eligibility check. It is not automatically retried as a database transaction, since repeating external mail after a commit error is unsafe.

An external email provider can accept a message before a subsequent database commit/process failure. Without provider-side idempotency, exactly-once delivery cannot be guaranteed; this limitation is explicit. reminder_sent_at means transport processing/acceptance, not verified inbox delivery. The local log transport and fake notifications are used for development/tests. Customer reminder emails, preference management, Google OAuth/sync and other integrations remain later-phase work; the scheduler/calendar do not require Google.

## Phase 5 tasks and database notifications

TaskPolicy and TaskCommentPolicy enforce management versus assigned-record permissions; the list and detail queries scope records before fetching. TaskService serializes writes on a current locking task read and compares versions inside the transaction. Comment writes lock the parent first, so reassignment/archive cannot race an authorization decision; comment edit/delete additionally compares the comment version. Bounded deadlock retries cover database-only work. Every change and its audit/notification writes commit or roll back together. Descriptive no-op task edits do not bump versions or create duplicate alerts.

Task completion timestamps belong to the server: entering Completed sets now, descriptive edits preserve it, reopening clears it. Legacy completion dates are not invented. Linked lead/customer consistency and active eligible assignees are checked on creation or relationship changes; unchanged inactive/archived references do not block unrelated task edits. A task grants its owner read access to its customer, while a linked lead still uses the lead scope. Resource summaries hide inaccessible/archived references.

TaskNotificationService selects active, authorized owners/creators and suppresses repeated recipient IDs and most actor notices. New assignment includes self-assignment; reassignment notifies the new owner and gives the old owner a generic change notice. Laravel database-channel delivery is synchronous within the transaction, avoiding async ordering and access races for this local operation. External email and automation remain later-phase work. Notification resources serialize a fixed safe shape and resolve task context with one batch-scoped query per page. Unknown notification types become generic. Losing task access removes the title/link from historical notices, without deleting read history.

TanStack Query caches task and notification endpoints separately. Task mutations invalidate CRM, dashboard, and notification caches. Notification read controls invalidate only notification queries. Foreground polling is every 30 seconds; the list fetches only while open. All private caches clear on logout. Shared datetime helpers preserve unchanged UTC instants during repeated DST hours for both appointments and task due dates. No additional packages were needed in Phase 5.

## Phase 6 workflow execution

Observers for customer/lead/appointment writes emit AutomationTriggered. CaptureAutomationEvent writes a transaction-bound, unique event occurrence only when an enabled matching workflow exists, capturing its eligible workflow IDs. No external calls occur inside business writes. A post-commit queue dispatch and scheduled reconciliation provide durable handoff. AutomationService materializes unique workflow/event runs with encrypted, ordered action snapshots. AutomationRunner claims one step under a run-row lock and short lease; local effects use a nested savepoint, external effects run outside the database transaction. Success is recorded before advancing, and retries preserve completed steps.

External outcomes are classified rather than claimed exactly-once. Confirmed transient rejections retry within bounded attempts, while ambiguous sends/appends or worker interruption become uncertain. Calendar IDs are deterministic, webhook IDs/bodies are stable, and signed inbound receipts deduplicate before emitting their fixed trigger. Automation-created task origin FKs and three-hop webhook forwarding limits bound feedback loops. Credentials/action snapshots/payloads are encrypted where persisted and excluded from resources; logs contain operation IDs and safe error codes.

Google OAuth uses per-service grants, PKCE, hashed user/session-bound one-use state, encrypted credentials and serialized refreshes. The callback is a web-session GET, while the SPA connect route uses existing Sanctum/CSRF. Webhook receive routes alone have a targeted CSRF exemption because signature/timestamp/replay verification is their authentication boundary. The approved-host/public-DNS/no-redirect policy is applied at configuration and send time; an egress proxy must enforce the same public-address boundary. See automation.md for operational limits and provider setup.
