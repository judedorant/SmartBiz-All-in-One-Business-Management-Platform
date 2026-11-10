# QA setup and reproduction guide

Use branch `qa/2026-10-28-e2e`. Preserve both existing MySQL databases. These commands do not require Docker; Docker Desktop is available, but the repository has no container configuration.

## 1. Prerequisites and database boundary

Use the existing PHP/Composer/MySQL and Node/npm installation. Existing requirements and fresh-install steps remain in README.md. Laravel is 13.38 and the frontend is Next.js 16.4.

Current local configuration (October 28, 2026): `localhost:3000` calls Laravel at `localhost:8000`, which uses `smartbiz_demo`. This authorized configuration change preserves the separate original `smartbiz` database. The session cookie is `smartbiz_demo_3000_session`; sign in again using a demo account. Original Local Admin credentials belong to the original database.

The QA administrator email is `qa2026.admin@example.test`; its existing password is available locally in `backend/storage/app/private/qa-2026-manifest.json` under `account`. Do not commit or publish this file. Existing demo role accounts are in the separate private `demo-accounts-smartbiz_demo.json`. The previous environment file is backed up privately as `backend-env-before-usd-demo.env`.

The USD batch is safely repeatable from `backend` with `php artisan db:seed --class=SmartBizQADemoSeeder --no-interaction`. Validate USD independently with `php artisan smartbiz:qa-2026 --currency=USD --no-interaction`; the default audit remains PHP. Never reset either database.

If the base demo database has never been initialized, the existing non-destructive `php artisan smartbiz:demo-setup --no-interaction` can create/migrate a separate demo database. Do not rerun destructive migration commands on either populated database.

## 2. Append the fixed scenario and validate

From the project root:

```powershell
cd backend
php artisan smartbiz:qa-2026 --seed --no-interaction
php artisan smartbiz:qa-2026 --no-interaction
```

The command explicitly uses local smartbiz_demo, verifies environment/host/DB_URL safety, snapshots existing business/grant rows, and appends the QA2026 batch only once. It does not modify .env, migrations, canonical grants, old passwords or existing business rows.

Reference: 2026-10-28 12:00 Asia/Manila. Source seeder: `Database\Seeders\Qa2026ScenarioSeeder`, reusing existing factories. A repeated --seed is a no-op for the batch and password.

Private files, all excluded from Git:

```text
backend/storage/app/private/qa-2026-manifest.json
backend/storage/app/private/qa-2026-baseline.json
backend/storage/app/private/qa-2026-validation.json
backend/storage/app/private/demo-accounts-smartbiz_demo.json
```

Open the QA manifest locally for the randomly generated password of `qa2026.admin@example.test`. The original fourteen demo passwords stay in the last file. Never copy passwords into tracked documentation. Do not use local-accounts.json to sign into the demo; it belongs to the original database.

Validation preserves raw user digest deltas caused by normal logout token rotation, independently checks existing credentials/roles, and strictly compares business/grant rows. Read the authentication note in the validation JSON. The legacy baseline is not silently replaced.

## 3. Run the demo API

In a dedicated terminal from backend:

```powershell
$env:DB_DATABASE='smartbiz_demo'
$env:DB_URL=''
$env:APP_URL='http://localhost:8001'
$env:FRONTEND_URL='http://localhost:3001'
$env:SANCTUM_STATEFUL_DOMAINS='localhost:3001'
$env:SESSION_COOKIE='smartbiz_demo_session'
$env:MAIL_MAILER='log'
php artisan serve --host=127.0.0.1 --port=8001 --no-reload
```

This leaves the original API/database on 8000 unchanged. Do not enable external workflows for QA. The new workflow is disabled after its fifty local actions finish.

## 4. Run the demo frontend

In another terminal from frontend:

```powershell
$env:NEXT_PUBLIC_API_URL='http://localhost:8001'
$env:SMARTBIZ_NEXT_DIST_DIR='.next-qa'
npm.cmd run build
npm.cmd run start -- --port 3001
```

Open **http://localhost:3001**. Use localhost consistently. The original localhost:3000 frontend still points to localhost:8000 and the original database.

For the demonstration, select **PHP**, then a custom period **2026-01-01 to 2026-12-31**. The UI uses the browser timezone. The fixed validation snapshot uses Asia/Manila; browser DevTools timezone emulation or the QA browser runner can reproduce it. For real-time fields such as upcoming appointments, use the generated timestamp rather than assuming wall-clock and fixed-reference results are identical.

Views: Dashboard, Customers, Leads, Sales Pipeline, Sales ledger, Appointments/availability, Tasks, Automation execution logs, Reports and Users. Admin/Manager can download the four existing aggregate CSVs through Reports. Staff is intentionally restricted.

## 5. Run normal isolated tests

Use a **new clean terminal** without a MySQL DB override:

```powershell
cd backend
php artisan test --compact
composer validate --strict --no-check-publish
php vendor/bin/pint --dirty --format agent
```

Normal tests may use only SQLite :memory:. The guard refuses MySQL before refresh traits execute. The live demo cases are skipped here by design. The legacy destructive concurrency fixture also skips under SQLite and was not executed against either preserved database.

Frontend, from frontend:

```powershell
npm.cmd run test
npm.cmd run typecheck
npm.cmd run lint
```

## 6. Run transaction-only real MySQL integration

From backend in a separate terminal:

```powershell
$env:SMARTBIZ_QA_LIVE='1'
$env:DB_CONNECTION='mysql'
$env:DB_DATABASE='smartbiz_demo'
$env:DB_URL=''
$env:CACHE_STORE='array'
php artisan test --compact tests/Feature/QaDemoLiveTest.php
```

The class asserts the exact local demo connection and rolls back each case. It never migrates or resets schemas. Enabled workflows cause mutation cases to skip pending review. Normal test classes remain blocked on MySQL even when this flag is set.

This covers native CRUD/validation, conflicts, sales amounts/idempotency/voiding, report/CSV SQL reconciliation, time filters, auth metadata and role restrictions. MySQL auto-increment counters may advance through rolled-back inserts; gaps are expected and are not missing business records.

## 7. Run live HTTP and desktop/mobile browser checks

From the project root, with both demo servers running:

```powershell
$env:SMARTBIZ_API_URL='http://localhost:8001'
$env:SMARTBIZ_FRONTEND_URL='http://localhost:3001'
$env:SMARTBIZ_ACCOUNTS_FILE='backend/storage/app/private/demo-accounts-smartbiz_demo.json'
node scripts/smoke-auth.mjs
node scripts/qa-browser.mjs
```

The browser runner uses installed Edge, an isolated profile, desktop 1440×1100 and mobile 390×844, and Asia/Manila timezone. If Edge is installed elsewhere, set `SMARTBIZ_QA_BROWSER` to the browser executable. No Playwright installation is required.

The first run creates/edits/archives exactly one identifiable QA customer and records its ID. It does not touch an old customer. Reruns reuse the completed browser record rather than duplicating it. A partial record state intentionally stops for review. Report fault injection and delayed responses occur only inside the isolated browser; they do not modify the backend.

Artifacts and actual CSV downloads:

```text
.runtime/qa2026-browser/results.json
.runtime/qa2026-browser/results-initial.json
.runtime/qa2026-browser/browser-customer.json
.runtime/qa2026-browser/desktop-dashboard-2026.png
.runtime/qa2026-browser/desktop-sales-report-2026.png
.runtime/qa2026-browser/desktop-automation-logs.png
.runtime/qa2026-browser/mobile-*.png
.runtime/qa2026-browser/smartbiz-*-2026-01-01-2026-12-31.csv
```

They are ignored local artifacts. Review screenshots and the JSON check list; do not treat a partially completed runner as a pass.

## 8. Reset only disposable test data

There is no destructive reset command in this QA workflow. SQLite test data disappears when the test process exits. Real MySQL test inserts/edits automatically roll back. The one browser-created customer is already soft-archived.

The persistent demo scenario is intentionally retained for demonstrations. Re-running its seeder does not reset it. Do not run migrate:fresh, db:wipe or TRUNCATE on smartbiz or smartbiz_demo. Any future hard cleanup of persistent QA rows requires explicit approval and a reviewed list of manifest IDs; no cleanup of old business records is implied.

See [QA_TEST_REPORT.md](QA_TEST_REPORT.md), [SAMPLE_DATA_REPORT.md](SAMPLE_DATA_REPORT.md), [REPORT_VALIDATION.md](REPORT_VALIDATION.md) and [BUG_REPORT.md](BUG_REPORT.md) for results, limits and expected values.
