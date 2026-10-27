# Automation and integrations — Phase 6

The native application and local actions work without Google or an external email provider. Integration implementation is verified with fake HTTP responses and database tests, not a claim of successful consent or delivery to a real external account. No live credentials are included.

## Workflow setup

An Admin opens `/automation`, chooses New workflow, names it, selects a trigger and adds up to eight ordered actions. New forms default to disabled. Validate the recipient, owner, destination and provider before enabling. Text substitution supports only `{{id}}`, `{{title}}`, `{{status}}`, `{{trigger}}`; it never evaluates Blade/PHP/JavaScript/shell/SQL. Record text substituted into an email remains plain text. Task titles over 200 characters after rendering fail safely.

| Trigger | Meaning |
| --- | --- |
| customer_created | A customer was saved, including lead conversion |
| lead_created | A lead was saved |
| lead_status_changed | Stage changed; optional destination-stage condition |
| appointment_created | A booking was saved |
| appointment_updated | Booking title/time/status/owner changed, or it was archived; supports Calendar lifecycle updates |
| appointment_approaching | Active booking starts within 30 minutes; scheduler scan |
| task_overdue | Pending/In Progress manual task is past due; scheduler scan |
| webhook_received | A correctly signed inbound event was accepted; raw sender payload is not used as CRM data |

Scheduled triggers are deduplicated per trigger/record/version. An edit that increments a version can make a new occurrence eligible. Completed/cancelled/archived sources are rechecked before acting. Automation-created tasks carry a server-owned origin FK and are excluded from overdue triggers to prevent recursive task chains. They still appear in normal task lists and dashboard counts. Webhook forwarding carries a bounded hop count.

| Action | Configuration |
| --- | --- |
| create_task | title, priority, due_in_hours 0–8760; optional assigned_to override |
| send_notification | Optional user_id override; safe generic database notice, no private source details stored in the alert |
| send_email | recipient=assignee/customer/fixed; fixed requires to; subject/body; transport=mail/gmail; Gmail requires own connected integration_account_id |
| google_sheets_row | Own connected Sheets account, spreadsheet_id, range; writes seven fixed columns using RAW values |
| webhook | An active outbound webhook_endpoint_id |
| google_calendar_event | Own Calendar account, calendar_id; only appointment triggers |

Default owner/notification recipient is the current source owner, then workflow creator. Email assignee recipients must be active; customer recipients require a visible linked customer email (unlinked leads have no email field). Invalid/inactive destinations fail with a safe error code. A generated task uses the workflow creator as its actor and passes through TaskService permission/link validation. Owner overrides must also have task access at execution.

Only Admin has automations.manage/integrations.manage in the canonical roles. All Admins may view, disable or archive workflows. Only the original workflow creator can change its configuration, because Google accounts are private to their owner. API validation only accepts that creator's connected account for the correct service. If the creator is deactivated or loses automation permission, queued work skips. Source access, active workflow state and endpoint/account availability are rechecked; already in-flight external calls cannot be recalled.

## Native processing

In separate terminals:

```bash
cd smartbiz/backend
php artisan queue:work --tries=3 --timeout=45
```

```bash
cd smartbiz/backend
php artisan schedule:work
```

Manual reconciliation: `php artisan smartbiz:automations`. Production runs Laravel's scheduler once per minute and supervises queue workers. Keep queue retry_after greater than the worker timeout (the current database default is 90 seconds), use shared queue/cache storage, and restart workers after code/config changes. No container is required.

Business observers emit a Laravel event. The synchronous listener persists an outbox row in the business transaction, capturing eligible workflow IDs. A post-commit job materializes one run per workflow/event, snapshots encrypted action settings and dispatches one action per job. Dispatch failures leave durable pending work for the scheduled reconciler. New workflows do not process earlier captured occurrences. Runs use configuration at materialization; subsequent edits do not rewrite their action snapshots. Disabling/archiving still stops remaining actions.

Local task/notification effects and step completion commit atomically. Completed steps are never reset on retry. External work is claimed with a two-minute lease, with provider timeouts bounded below the 45-second job timeout. A live lease prevents duplicate workers. A crashed external worker eventually becomes uncertain; the reconciler does not guess whether delivery happened.

| Outcome | Behavior |
| --- | --- |
| Successful step | Record success; queue next step |
| Explicit rate-limit rejection | Retry at most three times, using queue backoff 30/120 seconds |
| Webhook 5xx / deterministic Calendar failure | Retryable; receivers must deduplicate stable delivery IDs |
| SMTP exception, non-idempotent Google timeout/5xx, interrupted external worker | Uncertain; no automatic or UI retry |
| Invalid configuration, rejected permission, provider 4xx | Failed; safe code only, no provider response/credentials in logs |
| Known-safe failed step after exhaustion | Admin retry endpoint resets only that failed step's attempts; completed steps remain successful |

There is no exactly-once claim for external email, non-idempotent Sheets appends, or uncertain third-party delivery. Verify the receiver before intentionally creating another occurrence. Laravel queue:retry cannot bypass a stored uncertain step. Local default MAIL_MAILER=log records development mail and does not prove inbox delivery. SMTP configuration uses existing MAIL_* environment variables; Gmail uses OAuth below.

## Google OAuth configuration

1. In your Google Cloud project enable Gmail, Sheets and/or Calendar APIs, configure the consent screen and authorized test users, and create a Web application OAuth client.
2. Configure backend environment secrets, never NEXT_PUBLIC_* variables:

```dotenv
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://localhost:8000/integrations/google/callback
```

Use the exact HTTPS backend callback in production. The frontend and backend must use compatible same-site Sanctum cookie settings; connecting across unrelated top-level domains requires the deployment redesign described in deployment.md. Keep SESSION_SAME_SITE=lax for the top-level Google callback and use secure cookies with HTTPS in production. Do not log OAuth callback query strings/codes in reverse proxy or application access logs.

3. Clear/rebuild Laravel config cache and restart workers. Open `/settings/integrations` as the Admin who owns the workflow and connect the desired service. Each connection requests OpenID/email identity plus only its service scope: gmail.send, spreadsheets, or calendar.events. There is no shared all-services grant. Google may require app/consent verification for public deployment.
4. State expires in ten minutes, is hashed in the database, bound to user/session, and consumed once. PKCE S256 protects the authorization code. Tokens and refresh tokens are encrypted at rest and absent from API resources, queue payloads, logs and browser storage. Missing/denied/offline/scoped consent returns a generic failure status to the fixed frontend page. Refresh occurs under an account lock. Revoked/expired grants require reconnecting; Google testing-mode grants may expire according to its policy.
5. Disconnect clears credentials locally even if Google revocation fails; the UI tells you to review the grant in Google account permissions. Existing remote events/rows/messages are not removed by disconnecting.

Google APIs use Laravel's installed HTTP client and fixed Google URLs rather than extra SDK packages. TLS verification and the environment's proxy/CA trust remain enabled. HTTP provider tests use fakes and reject stray network calls.

### Calendar lifecycle

Connecting a Calendar account alone does not export bookings. Create a workflow for **Appointment created** with a Google Calendar action, then another for **Appointment updated** with the same account/calendar ID. Calendar IDs may be primary or a calendar the account can edit. Each SmartBiz appointment has a deterministic Google event ID. A confirmed-existing event is updated; cancelled/archived appointments delete that ID, treating already-missing events as success. The paired update workflow is necessary for edits/cancellations; approaching-only workflows do not provide immediate update synchronization.

This is one-way, workflow-driven SmartBiz → Google synchronization. Google-side edits are not imported, recurrence/free-busy/attendee invitations are not implemented, and disconnect/archive of a workflow does not retroactively clean remote records. Never infer that Google Calendar replaces SmartBiz's transactional overlap/availability rules.

### Sheets and Gmail

A Sheets row contains event key, trigger, source type, source ID, title, status and occurrence time. Values use RAW mode, preventing customer-controlled leading formulas from being evaluated. Choose an existing spreadsheet/range the account can edit. Ambiguous appends are held for review.

Gmail sends RFC MIME text/plain using a validated recipient, encoded subject and base64 body. The configured Laravel provider uses a fixed plain-text mail view. No user-supplied template is compiled or evaluated. Recipient access and provider API success are tested; real inbox arrival is not verified.

## Signed webhooks

Create inbound/outbound endpoints under integration settings. Enter or generate a separate strong signing secret (at least 32 characters), securely copy it to the other service, then save. Secrets are encrypted and write-only; blank edit fields retain the existing secret. Rotate on both sides together. Inbound URLs are displayed after creation. Outbound hosts require operator approval:

```dotenv
SMARTBIZ_WEBHOOK_HOSTS=hooks.example.com,receiver.example.org
```

The allowlist is exact, lowercase DNS hostnames, not wildcard patterns. Only HTTPS port 443 is accepted, without URL credentials/fragments/IP literals. All resolved addresses must be public; private/reserved/shared/metadata ranges and unsafe IPv6 are rejected. Revalidate on every attempt, disable redirects, and pin the selected address with curl options. A forward HTTP proxy may resolve the destination itself; production egress/proxy rules must also reject private destinations and restrict approved hosts. Keep inherited sandbox/production proxies and TLS trust intact. Do not approve untrusted, user-controlled DNS or put secrets in receiver URLs. No default outbound host is approved.

Send POST raw JSON (up to 64 KiB) containing an event string up to 100 characters. Headers:

- X-SmartBiz-Id: stable UUID delivery ID
- X-SmartBiz-Timestamp: Unix seconds, within ±300 seconds
- X-SmartBiz-Nonce: fresh UUID per delivery attempt
- X-SmartBiz-Signature: v1= plus lowercase hex HMAC-SHA256

The exact signing input is `timestamp + "." + nonce + "." + deliveryId + "." + rawBody`, keyed by the shared secret. Do not reserialize the body before verifying it. Signature comparison is constant-time. Timestamp, nonce and delivery IDs are authenticated. Endpoint/receipt checks use transactions and current row locks. A valid duplicate ID with identical body returns 200/duplicate:true without executing again; the same ID with changed body or a nonce reused for a different ID returns 409. Old timestamps fail even for an earlier accepted delivery. Receipts persist only hashes/IDs, not incoming customer payloads.

Outgoing payloads contain id, event, origin=smartbiz, depth and safe database-derived data. The body/delivery ID is fixed across retries; nonce/timestamp/signature are fresh per attempt. Receiving services must deduplicate the stable ID before side effects, particularly when returning 5xx. SmartBiz preserves forwarded depth and rejects hops beyond three to bound forwarding loops. The inbound trigger is always webhook_received, never a caller-selected internal CRM trigger, and cannot directly write arbitrary CRM fields.

Only signature-verified webhook receive paths are exempt from CSRF; other API mutations retain Sanctum's cookie/CSRF requirements. Public receivers are rate limited. Delivery/log APIs never serialize secrets, stored action settings or raw payloads. Set retention and access policies for outbox, execution, OAuth, receipt and delivery tables; OAuth states older than expiry plus a day are pruned by the scanner. Receipt retention must preserve the receiver's delivery deduplication contract.
