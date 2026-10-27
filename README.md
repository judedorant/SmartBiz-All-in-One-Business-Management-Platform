# SmartBiz

A decoupled Laravel REST API and Next.js business workspace. **Phases 1–6 are implemented: authentication, CRM, sales pipeline, analytics, sales ledger, appointment scheduling, staff availability, tasks, comments, in-app notifications, workflow automation, and optional integration support.** There are no mock business metrics or records. Planned navigation items are visibly unavailable until their phase is approved.

## Implemented

- Laravel 13 / PHP 8.4, Sanctum SPA cookie authentication, MySQL 8.4.
- Admin, Manager, and Staff roles through Spatie Permission; backend permission middleware and a user policy.
- CSRF protection, exact-origin credentialed CORS, encrypted HTTP-only sessions, login throttling, active-account revocation, and login/logout audit records.
- Next.js 16 App Router / React 19 / strict TypeScript / Tailwind 4; shadcn-style components using Radix and CVA, TanStack Query, React Hook Form, Zod, Lucide, Sonner, and light/dark themes.
- Login, protected client dashboard, account settings, role/permission display, and logout with private query-cache cleanup.

- Customer CRUD, multi-staff assignments, notes, and activity history; search, filters, sorting, and pagination.
- Lead CRUD, staff assignment, exact-decimal estimated values, activity notes, won/lost stages, and transactional customer conversion.
- A paginated Kanban pipeline with drag-and-drop, accessible stage selectors, real database counts, and version-based conflict detection.

- Eight authorized dashboard metrics and five Recharts visualizations, with presets/custom calendar ranges, timezone handling, and currency-separated sales.
- An audited sales ledger: Manager/Admin recording, idempotent retries, and version-protected voiding.

- Appointment CRUD, cancellations, daily/weekly/monthly FullCalendar views, drag/resizing, explicit weekly availability, and transactional overlap prevention.
- Queued staff email reminders with current-state checks, retry handling and delivery tracking.

- Task CRUD, assignment, priorities, due dates, completion tracking, comments, and database-backed notification dropdown.

- Admin workflow builder, durable triggers, queued actions, execution logs, safe retries and loop guards.
- Signed webhooks and optional Google OAuth adapters for Gmail, Sheets and Calendar. Provider behavior is tested with simulated responses; live external delivery requires configured credentials.

Reports will be introduced in Phase 7. Unavailable modules are labeled as planned. The frontend authorization guard improves navigation; Laravel enforces access on every API request.

## Native development (no containers)

Requirements: PHP 8.4+ with PDO MySQL, PDO SQLite, mbstring, DOM/XML, curl, zip, fileinfo and OpenSSL; Composer 2; Node.js 24; npm 11; MySQL 8.0+. PHP 8.4 is required by the locked PHPUnit 12 development dependencies. SQLite is used only for automated tests.

For a fresh checkout:

```bash
cd smartbiz/backend
composer install
cp .env.example .env
php artisan key:generate
```

Create an empty MySQL `smartbiz` database and a database-scoped application user using your native MySQL installation. Set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and a strong random `DB_PASSWORD` in `backend/.env`. A local Unix socket can instead be supplied as `DB_SOCKET`. Do not use a production database for local setup or automated tests.

```bash
php artisan migrate --seed
php artisan smartbiz:local-accounts
php artisan serve --host=0.0.0.0 --port=8000
```

The local-only account command creates one account per role, generates a separate random 24-character password per account, and saves credentials with mode 0600 in `backend/storage/app/private/local-accounts.json`. Read that file privately to sign in; it is ignored by Git. The command refuses to run outside `APP_ENV=local` or overwrite existing accounts/files. `db:seed` alone never creates password-bearing accounts.

In a second terminal:

```bash
cd smartbiz/frontend
cp .env.example .env.local
npm ci
npm run dev
```

Open **http://localhost:3000**. Use `localhost` consistently for both services, rather than mixing it with `127.0.0.1`. The API is at http://localhost:8000/api/v1. Restart Next.js after changing `NEXT_PUBLIC_API_URL`.

## This Codex workspace

Native PHP, Composer, and MySQL binaries were installed in `/workspace/.tools`, outside the application. No Docker command was executed during this implementation. These session-local tools and generated secrets are not application source.

```bash
export PATH="/workspace/.tools:$PATH"
export COMPOSER_HOME=/workspace/.cache/composer
export npm_config_cache=/workspace/.cache/npm
cd /workspace/smartbiz
```

MySQL has been initialized and migrated. Its data is in `/workspace/.runtime/mysql`; the backend's private `.env` holds the generated database credentials and socket path. If the process has stopped, run this in a dedicated terminal:

```bash
LD_LIBRARY_PATH=/workspace/.tools/libaio/lib/x86_64-linux-gnu:/workspace/.tools/libaio/usr/lib/x86_64-linux-gnu \
  /workspace/.tools/mysql/bin/mysqld --no-defaults \
  --basedir=/workspace/.tools/mysql --datadir=/workspace/.runtime/mysql \
  --socket=/workspace/.runtime/mysql.sock --pid-file=/workspace/.runtime/mysql.pid \
  --port=3306 --bind-address=127.0.0.1 --mysqlx=0 \
  --log-error=/workspace/.runtime/mysql.log
```

Then run the backend and frontend commands above in separate terminals. Where Codex requires it, grant normal command network access for downloads, local server binds, or database socket access. No Docker/socket escalation is needed.

## CRM usage

Sign in as Admin or Manager and open `/customers` or `/leads` from the sidebar. Create a customer, assign staff, and add notes. Create a lead with an active owner and an optional customer link. Open `/pipeline` to drag a card between stages or select a stage from its labeled control. Unlinked leads can be converted into a new customer from their detail page.

Staff sees only assigned leads, plus customers explicitly assigned or linked to their assigned leads/appointments/tasks. Customer notes are read-only for Staff. Staff cannot create/delete leads, change ownership/customer links, or convert leads. Managers/Admins can manage all CRM records. This is a single-organization application; tenancy is not implemented.

After pulling database changes, run `php artisan migrate --seed` in `backend`, then rebuild/restart the frontend. Seeding adds the seven pipeline stages and canonical permissions without creating business/demo records. Existing users and records are retained. Updates, deletes, and stage changes require the record's current `version`; a stale write returns 409. Reload the record before retrying.

## Dashboard and sales

Open `/dashboard` to view your authorized data. Choose Today, Last 7 days, Last 30 days, This month, or a custom range of at most 366 days. Calendar boundaries use your browser timezone; revenue uses the selected currency (USD/EUR/GBP). No exchange-rate conversion or mixed-currency totals are performed. Use each chart's data disclosure for an accessible, exact-value table.

Managers/Admins can open **Sales ledger** from the dashboard, select a customer, and record an actual transaction. Linking a lead is optional; it must already be Won and linked to that customer. A sale is independent of estimated lead value. Corrections void the original entry with a reason, keeping the audit trail and excluding the entry from revenue. Staff sees only sales linked to currently assigned leads and permitted customers.

Tasks and appointments have working management interfaces and authorized analytics scopes. Until populated, source tables yield actual zero counts and chart empty states. No demo business records are automatically inserted.

Upgrade after pulling schema changes (preserves existing data):

```bash
cd smartbiz/backend
php artisan migrate --seed
cd ../frontend
npm ci
npm run build
```

Restart the frontend after the build. Existing sessions can refresh `/me` or sign in again to pick up the new Manager/Admin `sales.manage` permission.

## Scheduling and reminders

Open `/appointments`. Managers/Admins first open **Staff availability**, select a staff member, set the IANA timezone, and add weekly working windows. An empty schedule is unavailable; no work hours are silently assumed. Then create an appointment linked to a visible customer and active eligible owner. Staff can view their own calendar and working hours, but cannot change appointments or availability.

Bookings must be in the future within one year, last 5 minutes–8 hours, and fit one working window on a single date in the owner's timezone. Adjacent appointments are allowed. Active overlaps for the same staff member are rejected. Cancelling releases the slot; archiving removes the record from views while retaining history. Completed appointments preserve their time/status. List view provides keyboard-friendly access alongside the month/week/day calendar. Calendar drag/resize failures revert the visual move.

Reminders go to the assigned active staff member within 30 minutes of the start time. In separate native terminals:

```bash
cd smartbiz/backend
php artisan queue:work --tries=3 --timeout=45
```

```bash
cd smartbiz/backend
php artisan schedule:work
```

For a manual scan use `php artisan smartbiz:appointment-reminders`. The local default mailer is `log`, so local reminders are logged, not delivered to an external inbox. Configure real `MAIL_*` SMTP/provider values through the environment for external mail delivery. Never commit credentials. Reminder jobs re-check cancellation, assignment, permissions, archive status and version, and suppress known-sent duplicates. SMTP cannot guarantee exactly-once delivery across a provider-acceptance/database-commit crash.

Google Calendar OAuth is optional and configured through Phase 6 integration settings and explicit workflows. Native scheduling works independently of Google. No Google credentials are included.

## Tasks and in-app notifications

Open `/tasks`. Managers/Admins create, assign, edit, and archive tasks; Staff can view and update only their assigned tasks. Staff cannot create/archive tasks or change ownership and CRM links. Filter by search, status, priority, overdue state, and (management only) owner; sort and paginate from real API queries. Due dates are optional and displayed in browser local time. Past due dates are allowed for backlog tracking. Only Pending/In Progress tasks are overdue. Completing sets a server-owned UTC completion date; reopening clears it.

Comments inherit task access. Staff edits/deletes only their own comments; Managers/Admins may moderate all. Writes use record versions and transactional current-row locks. Stale writes return 409. Archiving tasks retains rows/comments but revokes task-derived customer access. Linked leads remain independently authorized.

The header bell shows database notifications for assignments, status/detail changes, comments, and archiving. Task titles/links are resolved against current access on each read; old notices become generic after reassignment/archive. Use individual or all-read controls and the unread filter. The foreground count and open dropdown poll every 30 seconds. Task writes refresh notifications and analytics immediately in that browser. Notifications use Laravel’s synchronous database channel inside the task transaction; no mail provider, Google credentials, or new worker is needed for these in-app notices. No email/inbox delivery is claimed for task notifications.

## Automation and integrations

Admins open `/automation` to create, order, enable/disable, and inspect workflows. Actions include task creation, in-app notification, email through the configured provider or connected Gmail, a Google Sheets row, signed webhook delivery, and Google Calendar event synchronization. Logs show each step, attempts and safe failure codes. Known-safe failed steps can be retried; uncertain external delivery is held for review rather than blindly resent.

Open `/settings/integrations` to connect individual Google services, disconnect accounts, and configure inbound/outbound webhooks. Local email defaults to logs rather than an external inbox. OAuth requires server-side `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and the exact `GOOGLE_REDIRECT_URI`. Outbound receivers require exact approved hosts in `SMARTBIZ_WEBHOOK_HOSTS`; this defaults to empty. Signing secrets are encrypted and write-only. No external accounts are silently connected or data exported merely by connecting an account.

Run the existing native queue worker and scheduler for automation. `php artisan smartbiz:automations` manually scans scheduled triggers and reconciles pending outbox work. See [automation setup and safety](docs/automation.md) for configuration, workflow examples, retry rules, webhook signatures, Google consent requirements, and Calendar lifecycle setup. No automated business records or example workflows are seeded.

## Verification

```bash
cd smartbiz/backend
php artisan test --compact                 # SQLite in-memory; no MySQL required
composer validate --strict --no-check-publish
php vendor/bin/pint --format agent

cd ../frontend
npm ci
npm run test
npm run typecheck
npm run lint
npm run build
npm run start

cd ..
node scripts/smoke-auth.mjs                 # running MySQL API + frontend + local accounts required
```

The live smoke check uses real cookie sessions and MySQL data, verifies all roles and CRM list/metadata access, and ensures logout invalidates the session. It never prints credentials. See [verification](docs/verification.md) for measured results and limits.

## Documentation

- [Architecture and decisions](docs/architecture.md)
- [Database schema](docs/database-schema.md)
- [API contract](docs/api-documentation.md)
- [Deployment and security configuration](docs/deployment.md)
- [Automation and integration operator guide](docs/automation.md)

Phase 6 is complete. Phase 7 (reporting and exports) awaits approval.
