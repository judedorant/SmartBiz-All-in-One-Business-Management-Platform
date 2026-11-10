# QA test report — 2026-10-28

Branch: `qa/2026-10-28-e2e`. Work remains on this branch; no merge was performed. Pre-existing uncommitted project work was retained.

## Assessment

**PASS for the existing application features exercised.** The dedicated MySQL demo is populated, report calculations reconcile, and actual desktop/mobile browser workflows passed. Unsupported report variants and the blocked destructive concurrency fixture are listed explicitly; this is not a claim that every requested feature exists.

## Environment and architecture

- Laravel **13.38.0**, PHP **8.5.4**, Sanctum 4.3, Spatie Permission 8.3, PHPUnit 12.5 and Faker 1.24.
- Next.js **16.4.3** App Router, React 19.3, TypeScript 5.9, TanStack Query, React Hook Form, Recharts and FullCalendar; Node **24.14.0**, npm 11.9.
- Native MySQL **8.4.3**, Win64, with session timezone **+00:00**. Docker Desktop **29.9.2** is available, but this repository has no Dockerfile/Compose configuration and no container was changed.
- Browser: installed Microsoft Edge through its debugger protocol. Playwright is not installed; no new browser package was added.
- Browser → credentialed Next.js API wrapper → Laravel `/api/v1` → Eloquent/MySQL. Sanctum cookie sessions, exact-origin CORS, CSRF, active-user checks, policies and Spatie permissions remain enforced.
- Original environment: frontend 3000/API 8000/`smartbiz`. Demo QA: frontend **3001**/API **8001**/`smartbiz_demo`. Original environment files were not retargeted.

Migrations, models/factories/seeders, API routes/controllers, authorization, validation, services, frontend modules and existing tests were reviewed. All existing migrations are applied; no business schema migration was added. Route inventory was captured locally at `.runtime/qa-2026-routes.json`.

## Results actually executed

| Execution | Result |
| --- | --- |
| Full standard backend suite | **253 cases: 240 passed, 13 skipped**, zero failures. Twelve opt-in cases subsequently passed on MySQL. |
| Explicit live MySQL suite on smartbiz_demo | **12/12 passed**, 79 assertions; every mutation rolled back |
| New fixed-date scenario/safety regressions | **4/4 passed**, included in standard suite |
| Full frontend suite | **91/91 passed**, 15 files |
| Navigation regression after phase-label fix | **5/5 passed**, overlaps the 91 above |
| TypeScript and ESLint | PASS |
| Optimized isolated frontend build | PASS; 21 static pages plus dynamic routes compiled |
| PHP Pint and strict Composer validation | PASS |
| Independent SQL/report reconciliation | **1,571 checks; zero calculation/relationship failures** |
| Existing-account live HTTP smoke | **All 14 existing demo accounts passed** |
| Real browser initial pass | **22 scenario groups passed**, including actual create/edit/archive of one new QA customer |
| Final real browser regression | **23 scenario groups passed**, including the label fix, fully loaded mobile charts/calendar, four actual downloads, and no uncaught JavaScript exceptions |
| Accidental MySQL normal-suite guard | PASS: ordinary tests refused smartbiz_demo before any refresh |
| Seeder repeatability | PASS: existing batch/credentials unchanged on repeated --seed |

The backend default run skips the opt-in MySQL cases because they must be selected with `SMARTBIZ_QA_LIVE=1`; those cases subsequently passed against the real populated demo database. Across both runs, **252 of 253 distinct backend cases executed and passed**; the one legacy two-process concurrency test was **not executed in this pass** because it requires destructive schema resets. Its earlier results are not substituted for a current result.

The standard backend suite uses SQLite `:memory:` only, as pure isolated regression coverage. All actual MySQL integration/mutation QA targeted `smartbiz_demo`. No other MySQL database was used as a test target. The new base TestCase guard rejects normal tests against real databases.

## Modules and scope

| Module | Executed verification |
| --- | --- |
| Authentication | Existing backend valid/invalid/missing/wrong-password/session/CSRF/logout tests; real login/logout for 14 existing demo accounts; QA Admin browser login; Manager/Staff browser restrictions |
| Users/roles | Existing Admin/Manager/Staff policy tests; live role matrix; no unrestricted grant or existing password regeneration |
| Customers | Native MySQL create/read/update/archive, duplicate/invalid email, required values, version conflicts, search/filter/pagination; real browser create/edit/archive, confirmation cancel/accept, validation and empty results |
| Leads/pipeline | Existing CRUD/stage/conversion/version/scope tests; live endpoint checks; seven-stage annual counts/values independently reconciled |
| Sales | Native record/void, exact amounts, idempotency, zero/negative/excess-precision/future-close rejection, filters; independent daily/weekly/monthly/annual revenue |
| Appointments/schedules | Native create/edit/cancel, invalid dates and overlap rejection; SQL availability/overlap checks; real mobile calendar rendered |
| Tasks/comments | Native assignment/status/completion/overdue fixture; existing ownership/comment/version/priority tests; real lists and mobile views |
| Notifications | Existing privacy/read/assignment tests; 50 real local automation task notifications in addition to preserved sample notices |
| Automation | Fifty actual local create_task executions succeeded; disabled afterwards; workflow search/log display inspected in browser; existing retry/provider simulations |
| Dashboard | Five actual charts, eight current metrics, annual SQL revenue match, date presets, empty/loading states, mobile layout |
| Reports/CSV | Four existing report types; all annual cells against SQL; CSV parsed in native tests; four actual browser downloads |
| Error handling | Delayed report request showed loading; injected 500 showed a meaningful message; retry restored data |
| Responsive UI | 1440×1100 desktop and 390×844 mobile; dashboard/reports/customers/appointments/tasks had no document overflow or error alerts; mobile navigation opened/closed |

No auth middleware or permission policy was bypassed. Live SQL tests use a transaction for each case; they do not run migrations. The enabled-workflow precheck prevents accidental effects from operator-created live workflows.

## Data and preservation

See [SAMPLE_DATA_REPORT.md](SAMPLE_DATA_REPORT.md). The 1,564 earlier primary demo records were retained. The additive scenario contributes 245 primary records, 50 executed automation logs and supporting rows. One additional browser-created customer remains soft-archived, with its ID in the browser evidence.

Original business/authorization data and existing demo business/authorization rows match the pre-run hash snapshot. Normal authentication updates timestamps and rotates remember tokens. The original snapshot included those tokens; raw differences for the 14 existing demo user rows are retained, while every existing password, active status and role was independently rechecked. Byte-for-byte preservation of volatile authentication metadata is not claimed. The legacy snapshot cannot retrospectively isolate every user column; newer snapshots separately hash stable user fields. No passwords or tokens are included in tracked reports.

## Findings, fixes and remaining limits

[BUG_REPORT.md](BUG_REPORT.md) records severity, reproduction, cause and validation. Corrected: stale Phase 6 sidebar label; missing protection against destructive test-environment overrides; preservation monitor's handling of expected authentication metadata. The stopped demo services were restarted. No business-report calculation discrepancy was confirmed.

Not implemented: independent Opportunities table/lifecycle, pending/refunded sales, No Show appointment status, raw customer/lead/appointment CSVs, separate employee/customer revenue breakdowns, customer acquisition-source reporting, dedicated appointment/performance reports, PDF/Excel exports. Existing custom date filters support daily/weekly/yearly *periods*, but do not create nonexistent grouped report views.

Live Google/SMTP/Sheets/Calendar/webhook delivery was not attempted. Provider simulations passed in the existing suite. Real-time waiting for the entire session TTL, load testing, a penetration test and exhaustive accessibility testing were outside this executed pass. The destructive legacy MySQL concurrency fixture is blocked by the preservation requirement; sequential native overlap tests passed.

Application logs were checked. One missing-session error came from an early synthetic logout test without a stateful Origin; the test was corrected and rerun successfully. The deliberately injected browser 500 was a test interception, not an application failure. Final browser runs recorded zero uncaught JavaScript exceptions.

## Final category assessment

| Category | Result |
| --- | --- |
| Database Connection | PASS |
| Sample Data Generation | PASS — additive and repeatable |
| Authentication | PASS — supported scenarios |
| Customer Management | PASS |
| Sales Management | PASS — completed/voided ledger |
| Appointments | PASS — functional/conflict checks; legacy race fixture BLOCKED |
| Task Management | PASS |
| Dashboard | PASS |
| Reports | PASS — four existing reports |
| CSV Export | PASS — four aggregate exports |

Reproduce using [QA_SETUP_GUIDE.md](QA_SETUP_GUIDE.md). Expected figures and interpretation are in [REPORT_VALIDATION.md](REPORT_VALIDATION.md).
