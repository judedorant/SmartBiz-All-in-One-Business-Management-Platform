# SmartBiz sample data

Current QA2026 additions are documented in [SAMPLE_DATA_REPORT.md](SAMPLE_DATA_REPORT.md) and [QA_SETUP_GUIDE.md](QA_SETUP_GUIDE.md). The counts below describe the preserved earlier baseline, not the current extended dataset.

Verified on 2026-10-27. The original local `smartbiz` database and `backend/.env` were preserved. Demo data is in the separate MySQL database **smartbiz_demo**.

## What was generated

| Existing table | Records | Meaning |
| --- | ---: | --- |
| users | 14 | 1 Admin, 3 Managers, 10 Staff |
| customers | 500 | 400 active, 100 inactive; each assigned to a staff member |
| leads | 300 | All seven existing stages; 43 Won, 42 Lost, 215 open |
| sales | 200 | 180 completed PHP transactions, 20 voided entries |
| appointments | 250 | 160 completed, 40 cancelled, 25 scheduled, 25 confirmed |
| tasks | 300 | 75 each Pending, In Progress, Completed, Cancelled |
| **Primary records** | **1,564** | Supporting records are additional |

Supporting records: 500 customer/staff pivot assignments, 10 staff schedules, 50 weekday availability windows, 20 customer notes, 30 task comments, 10 database notifications, one disabled workflow, and the existing role/permission/pipeline configuration. There are no connected external accounts or webhook endpoints. Seeding queues no jobs or automation events.

There is **no separate Opportunities table**. The first 200 of the 300 existing leads are titled “DEMO Opportunity”; these are a subset, not another 200 leads. The additional 200 primary records above are **sales ledger entries**, not a newly invented opportunity entity. Multiple payments can reference the same won lead. A voided sale is a payment correction, not a lost opportunity.

Lead contact details use the linked customer. Expected closing dates use the existing lead field; follow-up work uses linked tasks. There are no new contact or follow-up columns. “No Show” is not a supported appointment status and was not inserted.

## Locale and dates

- Faker uses `en_PH`, Philippine addresses, common Philippine surnames, +63 phone numbers, synthetic business names, and reserved `@demo.smartbiz.test` emails.
- PHP amounts range from 1,000.00 to 250,000.00, stored as exact decimal strings.
- The current seed covers twelve calendar months: **2025-11-01 through 2026-10-27**, in Asia/Manila.
- The 200 historical appointments cover that period. Fifty appointments intentionally use the next five working days to exercise upcoming scheduling.
- Weekly staff availability is Monday–Friday, 09:00–17:00 Asia/Manila. Appointments last one hour and do not overlap for the same staff member.
- A fresh seed on another date anchors to that day's Asia/Manila date. Rerunning an existing seed preserves its original dates and passwords.
- Customers/leads/tasks have DEMO codes/titles or reserved emails where their existing schema permits. An audit marker `demo.seed.v1` identifies successful initialization. No demo flag columns were introduced.

## Create or reuse the demo database

Requirements: the existing local PHP/Composer/MySQL setup and an account allowed to create a database. From the project root, in PowerShell:

```powershell
cd backend
php artisan smartbiz:demo-setup --no-interaction
php artisan smartbiz:qa-data --no-interaction
```

`SetupDemoDatabase` creates/migrates the separate target and invokes `Database\Seeders\SmartBizDemoSeeder`. It does not edit `.env`. Only local MySQL hosts, local/testing environments, uncached configuration without DB_URL, and database names matching `smartbiz_demo` or `smartbiz_demo_<letters-or-digits>` are accepted.

The seeder refuses a nonempty unmarked database. A successfully seeded database is left unchanged on reruns. Credentials are never regenerated just because the command is run again. The standard `DatabaseSeeder` still creates only canonical roles/permissions/stages, not demo records.

For a manually configured, already migrated demo database, the direct seeder is also available:

```powershell
$env:DB_DATABASE='smartbiz_demo'
php artisan db:seed --class=SmartBizDemoSeeder --no-interaction
```

Use a separate terminal for normal automated tests so demo-specific environment variables do not carry over.

## Demo account credentials

Each account has a separately generated random 24-character password. Open this ignored private file locally:

```text
backend/storage/app/private/demo-accounts-smartbiz_demo.json
```

It contains `database`, `start_date`, `reference_date`, and fourteen `accounts` with `role`, `email`, and `password`.

| Role | Emails |
| --- | --- |
| Admin | admin1@demo.smartbiz.test |
| Manager | manager1@demo.smartbiz.test through manager3@demo.smartbiz.test |
| Staff | staff1@demo.smartbiz.test through staff10@demo.smartbiz.test |

Passwords are intentionally kept out of tracked documentation. These accounts belong to `smartbiz_demo` and will not sign in against the original `smartbiz` database. Existing `local-accounts.json` credentials were not changed.

## Run the isolated demo application

The QA session started the demo API on port **8001** and the production demo frontend on port **3001**. Open **http://localhost:3001**, using the demo credentials above. If the processes stop, restart them as follows.

Backend terminal, from `backend`:

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

Frontend terminal, from `frontend`:

```powershell
$env:NEXT_PUBLIC_API_URL='http://localhost:8001'
$env:SMARTBIZ_NEXT_DIST_DIR='.next-qa'
npm.cmd run build
npm.cmd run start -- --port 3001
```

The optional Next.js build directory isolates the demo build from the original `.next` build. Without `SMARTBIZ_NEXT_DIST_DIR`, normal behavior is unchanged. Always use `localhost` in the browser. Environment assignments here affect that terminal, not your saved `.env`.

Select **PHP** in the dashboard/report revenue currency filter; the normal default remains USD. Choose a custom range for all twelve months. The UI uses the browser timezone, while the exact reconciliation figures below use Asia/Manila; records near midnight can fall on different local dates.

The Admin can inspect Users, Reports, Automation, and Integrations. Staff sees assigned business records and a seeded task notification. No worker or scheduler is needed to view the data. The example workflow is disabled, and external integrations are unconnected.

## Expected reconciliation snapshot

For `2025-11-01–2026-10-27`, Asia/Manila, PHP:

| Metric | Expected |
| --- | ---: |
| Customers created in range | 500 |
| Open leads created in range | 215 |
| Qualified leads | 43 |
| Won leads | 43 |
| Lost leads | 42 |
| Completed recorded sales | PHP 23,377,171.33 |
| Pending + In Progress tasks | 150 |
| Overdue unfinished tasks | 140 |
| Completed tasks | 75 |
| Task completion rate over all tasks | 25% |
| Won / all cohort leads | 14.33% |
| Upcoming appointments within that historical range | 0 |

The forward-looking 31-day appointment range includes the fifty future appointments. The normal last-30-days dashboard is a different cohort and should not display the all-period totals.

`php artisan smartbiz:qa-data` validates counts, foreign keys, appointment availability/overlaps, dashboard values, and all cells in the four existing reports against independent queries. Results are saved to:

```text
backend/storage/app/private/qa-results-smartbiz_demo.json
```

## Safely create a fresh sample snapshot

Use a **new** dedicated database instead of deleting the existing one:

```powershell
php artisan smartbiz:demo-setup --database=smartbiz_demo_run2 --no-interaction
php artisan smartbiz:qa-data --database=smartbiz_demo_run2 --no-interaction
```

This leaves the first snapshot intact. The new passwords are saved in `demo-accounts-smartbiz_demo_run2.json`; start its API using `DB_DATABASE=smartbiz_demo_run2`.

No reset/delete command was added. Do not run `migrate:fresh` against `smartbiz` or a demo snapshot you want to keep. The separate `smartbiz_qa_test` database is disposable test infrastructure; its schema resets are unrelated to demo setup.
