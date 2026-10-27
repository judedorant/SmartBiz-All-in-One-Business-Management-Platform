# Deployment

Phases 1–4 provide verified authentication, CRM, analytics, and scheduling, not a completed production business system. Hosting or production deployment was not performed. No external credentials were invented or configured.

## Native services

Run PHP 8.4+ under PHP-FPM and a reverse proxy, MySQL 8+, and the Next.js production server. Use separate OS service definitions or your hosting provider's process manager. No container setup is required. Do not expose `php artisan serve`, the local MySQL bootstrap, or developer tooling publicly in production.

Backend release: `composer install --no-dev --optimize-autoloader`, provision a persistent APP_KEY and real database-scoped credentials, then `php artisan migrate --force`, `php artisan db:seed --class=RolesAndPermissionsSeeder --force`, `php artisan db:seed --class=PipelineStageSeeder --force`, `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache`. Back up MySQL before schema changes. Never run `smartbiz:local-accounts` on production; create initial administrators through a controlled provisioning procedure before deployment. A production provisioning/password lifecycle is outside Phase 1.

Frontend release: `npm ci`, set NEXT_PUBLIC_API_URL to the public HTTPS API origin **before** `npm run build`, then `npm run start`. The API URL is public configuration, never a secret. Node 24 is the verified runtime. Lockfiles are committed inputs to reproducible installation. The only backend runtime dependencies needed are installed by Composer; scaffold Vite assets are unused by the API.

## Cookie domains

Recommended origins: `https://app.example.com` and `https://api.example.com`. Configure:

- APP_ENV=production; APP_DEBUG=false; APP_URL=https://api.example.com.
- FRONTEND_URL=https://app.example.com; SANCTUM_STATEFUL_DOMAINS=app.example.com.
- SESSION_DOMAIN=.example.com; SESSION_SECURE_COOKIE=true; SESSION_HTTP_ONLY=true; SESSION_ENCRYPT=true; SESSION_SAME_SITE=lax.
- Set a unique strong APP_KEY and random database credentials through deployment secrets. Preserve APP_KEY across replicas/releases; encrypting existing sessions depends on it.

Use HTTPS throughout, restrict MySQL to application hosts, use a shared session/cache store across API replicas, and configure only trusted reverse proxies for accurate HTTPS/IP detection. Limit CORS to the first-party origin. Session and CSRF cookies must be shared appropriately across these sibling subdomains.

**Unrelated top-level domains do not support this Sanctum SPA cookie design reliably.** Use a same-site reverse proxy, or a backend-for-frontend that completes an OAuth/OIDC authorization-code flow and holds credentials server-side while the browser receives only a secure HTTP-only session cookie. This alternative is a deployment design recommendation, not implemented in Phase 1. Do not solve it by persisting bearer tokens in localStorage or enabling wildcard credentialed CORS.

## Operational requirements

Do not deploy demo credentials, local `.env`, `.runtime`, `.tools`, private credential JSON, or dev dependencies. Keep writable access limited to Laravel storage/bootstrap cache. Set a log retention policy and access controls for audit data; avoid including request bodies in access logs. The auth audit records store only user IDs, action, and UTC time.

Before declaring production readiness: complete the remaining phases and their security tests, implement administrator provisioning and password recovery, verify MySQL concurrency as business transactions are added, review dependency advisories, configure backups/restore checks, HTTPS, monitoring, and service supervision. Queue worker/scheduler infrastructure exists in Laravel, but business jobs, OAuth integrations, notifications, and reminders are not implemented or required to run Phase 1.

## Phase 2 release notes

Run the CRM migration and PipelineStageSeeder before enabling CRM routes in a fresh deployment. The migration adds tables and does not reset existing application data. Back up before deploying schema changes; rollback of the CRM migration drops its tables and would lose CRM records, so do not roll it back on a populated deployment without a recovery plan.

No additional runtime dependencies or external services are required. All writes pass through Laravel. Native MySQL tests must use a dedicated disposable database (e.g. smartbiz_phase2_test), never the smartbiz development database or a production database. Run `DB_CONNECTION=mysql DB_DATABASE=smartbiz_phase2_test php artisan test --compact` only after verifying the database name and granting a development/test account access to that database. RefreshDatabase will recreate test schema. The default SQLite suite remains `php artisan test --compact`.

## Phase 3 release notes

Run `php artisan migrate --force` for the three analytics source tables and additive lead indexes, then re-run `RolesAndPermissionsSeeder` to grant canonical Manager/Admin roles `sales.manage`. Run `npm ci` and rebuild the frontend for Recharts/react-is. Preserve the existing APP_KEY, sessions and database secrets. No container, new credential, queue worker, or external provider is required by Phase 3.

Configure allowed/default sales currencies in `backend/config/smartbiz.php` before recording transactions; there is no exchange-rate conversion. MySQL sessions use UTC and dashboard ranges are converted from the user's timezone in PHP. No MySQL named-timezone table import is required. Re-login/refetch `/me` after permission seeding if an existing browser cache still reflects old permissions.

Do not roll back a populated analytics source migration without preserving ledger/task/appointment data. Voided sales are retained for audit rather than deleted. The sales ledger is manual recording, not a payment processor or full accounting system. Task/appointment source schemas do not mean scheduling/task workflows are released; their operational safeguards and interfaces remain in their phases.

## Phase 4 release and native processes

Run `php artisan migrate --force` for appointment versions, availability and reminder tracking; no data reset or new permission names are required. Run `npm ci` and rebuild/restart Next.js for the matched FullCalendar 6.1.22 packages. Configure real staff availability before accepting bookings; an absent schedule is unavailable. Existing Phase 3 appointments are retained and may require operational review against the newly configured working hours.

For local reminders, run `php artisan queue:work --tries=3 --timeout=45` and `php artisan schedule:work` in separate terminals. `php artisan smartbiz:appointment-reminders` manually scans the next 30 minutes. The default database queue uses the existing jobs/cache infrastructure. Ensure queue retry_after exceeds the 45-second worker/job timeout. In production, supervise workers and run the scheduler once per minute via cron/process supervision; preserve shared cache/queue storage across replicas. Use Laravel `queue:failed` and `queue:retry <id>` for failed jobs and monitor appointment_reminders status/error_code. Do not run multiple independent schedulers without shared locking.

Default local MAIL_MAILER=log writes development messages to protected storage logs and never proves external inbox delivery. For SMTP set MAIL_MAILER=smtp, MAIL_HOST, MAIL_PORT, MAIL_SCHEME, MAIL_USERNAME, MAIL_PASSWORD and a verified MAIL_FROM_ADDRESS/NAME through environment secrets, then clear/rebuild config cache and restart workers. A real provider was not configured or contacted during verification. Set provider timeouts below the worker timeout. Keep mail logs/failed-job payloads private and apply a retention policy; generic application error codes do not include credentials.

Reminder delivery to active assigned staff is at-least-once across uncertain external provider/database crashes, with known-sent suppression during normal retries. Do not advertise exactly-once inbox delivery. No customer reminder emails or Google credentials are invented. Optional Google Calendar OAuth/refresh-token storage/sync is reserved for the integration phase; native scheduling remains independent.

The automated MySQL concurrency test creates two PHP processes against a disposable database ending in `_test`, never the development database. It migrates that test database and verifies one creation/one 409 for an initially empty slot under repeatable-read contention. Keep it in a dedicated sequential test job, not against a shared or production database.

## Phase 5 release

Apply `php artisan migrate --force` for task versions/indexes/comments and Laravel database notifications. No reset, credential change, new permission name or dependency is required. Existing roles already include tasks.assign/tasks.manage_assigned. Run `npm ci`, rebuild and restart Next.js. Preserve APP_KEY and database contents. Rollback drops comments/notification history and task versions, so preserve data before any rollback.

In-app notices require no new worker, SMTP or Google connection: Laravel's database channel writes inside task transactions. Existing queue/scheduler processes remain needed for Phase 4 mail reminders. Monitor notifications/audit growth and define an organization-specific retention policy; no unrequested automatic purge is enabled. Keep Laravel database notifications private, and do not replace scoped resource serialization with raw notification JSON. Default production database remains MySQL. No Docker command is needed for setup or verification.

## Phase 6 release

Back up the database and preserve APP_KEY, then apply `php artisan migrate --force` for automation/integration tables, expanded encrypted action storage and task origin references. No dependencies or canonical role names change. Rebuild/restart Next.js; restart supervised Laravel queue workers (`php artisan queue:restart`, followed by your supervisor/process restart) so model observers, event listeners and action handlers load the new code. Refresh route/config caches and confirm `php artisan event:list` and `php artisan schedule:list` include automation handling/scans.

Run the database queue worker with --tries=3 --timeout=45 and the scheduler once per minute. Keep retry_after greater than 45 seconds, use shared database/cache locks, and monitor pending events, failed/uncertain runs and queue failures. The scheduled scanner reconciles post-commit queue failures, old pending runs and interrupted leases; it prunes expired OAuth states after a day. Define explicit, privacy-aware retention/backups for event/log/delivery/receipt history, preserving delivery-ID deduplication. Do not blindly retry uncertain external outcomes.

Set backend-only GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and exact GOOGLE_REDIRECT_URI to enable optional OAuth. Configure the consent application/API access and actual provider credentials yourself. Set exact trusted DNS hosts in SMARTBIZ_WEBHOOK_HOSTS for outbound receivers; defaults permit none. Keep TLS/proxy/CA trust enabled; enforce public-only, approved-host egress at any forward proxy, since remote proxy DNS resolution cannot be pinned by client curl settings. Production callbacks require HTTPS and compatible same-site session settings (SameSite=Lax for top-level Google redirect). Redact callback query parameters/codes in access logs.

Connecting an account does not start an export. Calendar synchronization uses explicitly enabled paired created/updated workflows, while Google-side changes are not imported. Local mail remains log-only until a provider is configured. Secrets and action settings are encrypted with APP_KEY and are absent from resources/job payloads. Securely provision/rotate webhook secrets at both ends; the UI cannot retrieve stored values. Read automation.md for protocol and uncertainty limits. No production deployment, live Google consent or external inbox/webhook delivery is claimed by the automated tests.
