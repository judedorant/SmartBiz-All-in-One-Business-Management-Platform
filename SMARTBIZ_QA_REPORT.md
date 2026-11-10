# SmartBiz QA report

Historical baseline report. The current October 28 QA pass and additive dataset are documented in [QA_TEST_REPORT.md](QA_TEST_REPORT.md), [REPORT_VALIDATION.md](REPORT_VALIDATION.md), and [SAMPLE_DATA_REPORT.md](SAMPLE_DATA_REPORT.md).

**Date:** 2026-10-27, America/New_York  
**Result:** All existing executable backend test cases passed across SQLite and native MySQL; all frontend tests passed. Live HTTP and real-browser checks passed. Requested features that do not exist remain explicitly listed below.

## Scope and environment

Audited the existing Laravel REST API, Next.js frontend, migrations, models and relationships, controllers/routes, request validation, services, policies, session/CSRF middleware, report/CSV generation, and automated tests. The architecture was preserved. No new business tables, package dependencies, or application redesign were introduced.

Verified local tools: PHP 8.5.4, Laravel 13.38.0, Sanctum 4.3.3, Spatie Permission 8.3.2, PHPUnit 12.5.40, Faker 1.24.1; Node 24.14.0, npm 11.9.0, Next.js 16.4.3, React 19.3, TypeScript 5.9, Vitest 3.2.7, and native MySQL.

Database boundaries:

| Database | Purpose | Verified outcome |
| --- | --- | --- |
| smartbiz | Original application database | Preserved: 4 users, 0 customers/leads/sales/appointments/tasks before and after |
| smartbiz_demo | Dedicated sample business data | 1,564 primary demo records; supporting records additional |
| smartbiz_qa_test | Disposable native MySQL tests | Created empty; schema resets confined here |
| SQLite :memory: | Default isolated backend tests | No application database involved |

The original `backend/.env` was not edited. Demo services ran separately at http://localhost:8001 and http://localhost:3001 with a distinct session cookie. Credential files and browser artifacts remain ignored by Git.

## Modules inspected and verified

| Module | Verified behavior | Coverage and limits |
| --- | --- | --- |
| Authentication | Login/logout, session rotation/invalidation, active-account checks, safe user resources, real CSRF enforcement | Backend tests, fourteen live HTTP accounts, three browser roles |
| Roles and Users | Admin-only list/create/update/reset, role/status filters, role assignments, self/last-Admin protection, stale-edit rejection | SQLite + MySQL tests; browser visibility/access; no user deletion endpoint by design |
| Customers | CRUD/archive, assignments, notes/history, duplicate email validation, pagination/search/filter/sort, derived Staff visibility | Backend tests and live list/detail boundaries; browser workspace loads |
| Leads | CRUD/archive, stage transitions, linked customers, conversion, version conflicts, activities, assignment restrictions | Backend tests and live HTTP; contact data remains on linked customer |
| Sales pipeline | Seven seeded stages, counts, pagination, drag/stage controls, failed/stale move handling | Backend and frontend tests; real-browser board loads |
| Sales ledger | Exact decimal recording, supported currencies, idempotent submissions, linked Won-lead validation, audited voids | SQLite + MySQL tests; live lists/validation; 200 demo entries |
| Dashboard | Eight current metrics and five current charts, scoped aggregation, date/currency filters, zero-filled periods, empty states | 1,792 demo SQL checks include dashboard/report reconciliation; real PHP chart and empty EUR state verified |
| Appointments | CRUD/archive/cancel/reschedule, calendars, active-owner/availability checks, completed-state rules, overlap prevention | Both database suites; two-process MySQL booking race passed |
| Staff schedules | Explicit weekday windows, overlap validation, timezone and DST boundaries | Backend tests; all 250 demo appointments checked against windows; calendar/availability pages load |
| Reminders | State/version rechecks, retry/suppression and queue behavior | Automated fake mail tests; no external inbox delivery was attempted |
| Tasks/comments | CRUD/archive, assignment/status/priority/due-date validation, completion timestamps, version checks, nested comments | SQLite + MySQL and frontend tests; seeded overdue work and comments |
| Notifications | Assignment/status/comment events, authorized recipients, read/unread, privacy after reassignment/archive | SQLite + MySQL + frontend tests; Staff notification loaded in seeded-data API test |
| Automation | Workflow CRUD, validation, queued execution, retry/uncertain outcome handling, loop guards | Automated provider simulations and live authorization; demo workflow deliberately disabled |
| Integrations/webhooks | OAuth state/PKCE/session binding, encrypted credentials, signatures/replay, outbound URL restrictions and safe resources | Automated fake HTTP providers; no real Google consent, email, Sheets, Calendar, or webhook delivery |
| Reports | Monthly sales, daily customer growth, daily task creation/completion, lead pipeline cohort | Every report cell reconciled against independent SQL; all rendered last-30-days tables matched live API |
| CSV exports | Existing four report exports, filters/context, exact numeric strings, authorization, formula escaping and standard quoting | Backend/HTTP tests and an actual browser sales CSV download |

Frontend component tests use jsdom. The separate Edge run is actual browser testing, not a claim that every CRUD workflow was driven through a browser. CRUD coverage principally comes from API/database feature tests and frontend form tests.

## Database inspection

All sixteen existing migrations were applied in the original local database. The same migrations were applied to the newly created demo database without schema changes. Forty demo tables were inspected:

```text
appointment_reminders, appointments, audit_logs,
automation_events, automation_logs, automation_steps, automation_workflows,
cache, cache_locks,
customer_activities, customer_notes, customer_user, customers,
failed_jobs, integration_accounts, job_batches, jobs,
lead_activities, leads, migrations,
model_has_permissions, model_has_roles, notifications, oauth_states,
password_reset_tokens, permissions, personal_access_tokens, pipeline_stages,
role_has_permissions, roles, sales, sessions,
staff_availability, staff_schedules, task_comments, tasks, users,
webhook_deliveries, webhook_endpoints, webhook_receipts
```

Reconciliation checked declared foreign keys through independent orphan queries, customer assignments, role counts, sales/customer/Won-lead agreement, supported PHP amount bounds, task completion-date consistency, appointment working windows, and active appointment overlaps. No orphan or overlap was found. Soft deletes, restrict/cascade behavior, and unique/index definitions were reviewed from migrations and exercised by feature tests.

There is no independent Opportunities table, customer acquisition-source column, lead contact-details column, lead follow-up-date column, or appointment No Show status. Those absences were reported before any schema changes; no such changes were made.

## Generated records

| Table | Count | Distribution |
| --- | ---: | --- |
| users | 14 | Admin 1; Manager 3; Staff 10 |
| customers | 500 | Active 400; Inactive 100 |
| leads | 300 | New/Contacted/Qualified/Proposal/Negotiation/Won 43 each; Lost 42 |
| sales | 200 | Completed 180; Voided 20 |
| appointments | 250 | Completed 160; Cancelled 40; Scheduled 25; Confirmed 25 |
| tasks | 300 | Pending/In Progress/Completed/Cancelled 75 each |
| **Primary total** | **1,564** | Additional supporting records excluded from this total |

Supporting data: 500 assignment pivots, 10 schedules, 50 working windows, 20 notes, 30 comments, 10 notifications, and one disabled workflow. No external accounts/endpoints or seed-generated queue/automation events were created.

The first 200 leads are labeled demo opportunities, **within** the 300 leads. The 200 sales rows are recognized/corrected payment records, not a substitute schema that pretends to implement a separate opportunity lifecycle. This distinction is a remaining mismatch with the requested six-entity description.

## Automated execution results

| Check | Final result |
| --- | --- |
| Full default backend suite, `php artisan test --compact` | 233 cases: **232 passed, 1 MySQL-only concurrency case skipped**, 1,255 assertions |
| Native MySQL suite, existing cases excluding the five SQLite-isolation demo cases | **228/228 passed**, 1,165 assertions; includes concurrent-booking test |
| New demo/safety/report tests | **5/5 passed**, included in the default suite |
| Full frontend suite, `npm.cmd run test` | **91/91 passed**, 15 files |
| TypeScript | Passed |
| ESLint | Passed |
| Isolated optimized production build | Passed; all 21 static pages generated and dynamic routes compiled |
| PHP Pint dirty-file formatting | Passed/fixed formatting; final files conform |
| Composer strict validation | Passed |
| Migration status and route enumeration | Passed; existing API routes inspected |
| Independent demo SQL reconciliation | **1,792 checks, zero failures** |
| Live cookie-session HTTP integration | **All 14 demo accounts passed**, including reports, exports, Users restrictions, CSRF, and logout |
| Empty-credential HTTP smoke negative case | Passed: rejected before HTTP requests |
| Real browser | Headless Microsoft Edge; Admin, Manager, Staff passed; report tables, date/currency filters, page access, and CSV download checked; zero uncaught JavaScript exceptions |

The single SQLite skip is covered by the successful native MySQL run. All 233 distinct backend test cases were therefore exercised successfully across the two engines; the counts above are overlapping runs, not 461 different cases.

The five demo tests verify exact counts, fourteen persisted credential entries and password hashes, repeatability without password changes, no queued seed effects, populated recent pending/overdue cohorts, all four API reports/CSV files, independent sales SQL, response changes after a database mutation, Staff scope/management restrictions, production refusal, wrong-database refusal, and nonempty-database refusal.

Windows sandbox restrictions on spawning esbuild/Next workers required approved execution outside the sandbox. No dependency installation or version change was needed. The MySQL concurrency suite resets schemas only in the explicitly verified disposable `smartbiz_qa_test` database.

## Report and calculation reconciliation

Snapshot: **2025-11-01 through 2026-10-27, Asia/Manila, PHP**.

| Calculation | Database result | Existing application result |
| --- | ---: | --- |
| Customers created in cohort | 500 | 500 |
| Open leads | 215 | 215 |
| Qualified leads | 43 | 43 |
| Won leads | 43 | 43 |
| Lost leads | 42 | 42 |
| Completed sales SUM(amount), PHP only | 23,377,171.33 | 23,377,171.33 |
| Pending + In Progress tasks created in cohort | 150 | 150 |
| Unfinished tasks overdue at verification time | 140 | 140 |
| Won / total cohort leads × 100 | 14.33% | 14.33% |
| Upcoming appointments inside historical period | 0 | 0 |
| Upcoming appointments inside forward-looking 31-day range | 50 | 50 |

Additional independent SQL facts, **not claims of existing dashboard cards**: active customers 400, inactive customers 100; tasks completed 75/300 = 25%; converted_at populated on 43 leads; calendar-2026 recognized revenue PHP 19,420,336.08; average completed transaction PHP 129,873.17. The average completed transaction is not automatically an average opportunity/deal value.

Monthly completed PHP sales, independently grouped using local calendar months:

| Month | Transactions | Revenue |
| --- | ---: | ---: |
| 2025-11 | 13 | 1,962,425.56 |
| 2025-12 | 13 | 1,994,409.69 |
| 2026-01 | 16 | 2,070,412.12 |
| 2026-02 | 14 | 1,553,799.55 |
| 2026-03 | 15 | 1,843,298.28 |
| 2026-04 | 18 | 1,984,856.07 |
| 2026-05 | 11 | 1,159,727.40 |
| 2026-06 | 18 | 1,927,581.75 |
| 2026-07 | 14 | 1,819,888.21 |
| 2026-08 | 15 | 2,426,378.19 |
| 2026-09 | 14 | 2,070,056.99 |
| 2026-10 | 19 | 2,564,337.52 |

Revenue uses actual completed ledger entries, excludes voids, and never mixes currencies. Won/lost stages alone do not recognize revenue. Customer and lead cohorts use creation dates/current status; task completed series uses completion dates of currently completed tasks. Archived records are excluded where the existing service defines them as excluded.

The existing “lead conversion” chart is explicitly a Won/Open/Lost cohort outcome chart. It does **not** implement a distinct `converted_at / all leads` dashboard metric. The seeded dataset happens to have 43 Won and 43 converted leads, so those measures coincide here; they are not assumed interchangeable.

All four report row sets were compared to SQL using UTC half-open bounds derived from the selected local dates. Missing buckets were checked as zeros. Existing tests cover DST, custom-range limits, empty datasets, precise fractions, currency/status filtering, and data changes. SQL sums matched every existing reported value; no existing business-calculation defect was confirmed.

## Bugs and corrections

| Finding | Root cause | Smallest correction | Verification |
| --- | --- | --- | --- |
| Full suite failed on Windows private-file mode assertion | Windows does not expose Unix 0600 mode bits as Unix does | Retain exact 0600 check on Unix; check file readability/writability on Windows | LocalAccounts tests passed on Windows in both database suites; Windows ACL hardening is not claimed |
| Native MySQL notification test failed intermittently | Two events share second-resolution timestamps; test assumed an undefined tie order | Compare the exact notification-kind multiset without an order assumption; freeze same-second time | Targeted notification/concurrency tests and full native suite passed |
| HTTP smoke check could report success with zero account checks | Credentials array was never required to contain roles | Require nonempty valid credentials for Admin, Manager and Staff before network requests | Negative empty-file check passed; full positive run exercised all fourteen accounts |
| Philippine demo transactions could not be selected through existing currency validation | Supported currency list omitted PHP | Add PHP to existing configuration; preserve USD default and separate-currency accounting | Seeded API/report tests, SQL reconciliation, real PHP UI charts/download |
| Initial new demo credential manifest was empty | Arrow closure captured the accumulator by value | Return generated accounts from the transaction; assert fourteen credential entries and password hashes | Regression tests passed; credentials regenerated only for the fourteen marked demo accounts; original users untouched |
| Generated QA build code was linted as source | A separate .next-qa directory was not covered by existing ignore rules | Ignore .next-qa in Git/ESLint and support an optional isolated Next build directory | Lint, type checks, and isolated build passed |

No unrelated features or schema redesigns were introduced. During demo implementation, a permission-cache issue caused by suppressing all model events was corrected by seeding roles normally and suppressing only the subsequent synthetic business-data block. Monthly task statuses were balanced to avoid accidentally placing every task in one month in the same status. These are sample-data implementation corrections, not defects attributed to existing business modules.

## Requested capabilities that remain absent

These are verified scope gaps, **not failed tests for features that exist**:

- Additional dashboard cards: separately labeled annual revenue, average deal value, revenue growth, active/inactive customer totals, distinct converted-lead rate, appointment status totals, and task completion rate. The current dashboard has eight defined metrics and five charts.
- Additional reports: daily/weekly/annual sales views, employee/customer revenue breakdowns, revenue-growth reports, customer acquisition sources, leads-by-source, dedicated appointment status reporting, and employee performance.
- Sales line and appointment status charts. Current charts are monthly sales bars, customer growth area, lead outcome bars, task completion lines, and pipeline bars.
- Raw customer/lead/appointment exports. Existing CSVs are **aggregate** sales, customer growth, task completion and pipeline reports; they are not raw entity exports. PDF and Excel workbooks also do not exist.
- Independent opportunity records/lifecycle, standalone lead contact/follow-up fields, customer acquisition-source data, and No Show appointment status.
- Actual delivery/consent against Google, SMTP/Gmail, Sheets, Calendar, and live webhook receivers remains unverified. Automated simulations passed.
- This was not a production deployment, load test, penetration test, exhaustive accessibility audit, or complete browser-driven CRUD suite. No guarantees beyond the executed checks are implied.

No schema changes were made to conceal these gaps. Implementing them would be a separate feature increment rather than a QA bug fix.

## Evidence and reproduction

- [Sample data setup, account access, expected counts, and safe fresh snapshots](SMARTBIZ_SAMPLE_DATA.md)
- Seeder: `backend/database/seeders/SmartBizDemoSeeder.php`
- Commands: `smartbiz:demo-setup`, `smartbiz:qa-data`
- Regression coverage: `SmartBizDemoTest`, `LocalAccountsTest`, `WorkspaceNotificationTest`, and the existing module suites
- Live integration runner: `scripts/smoke-auth.mjs`; accepts `SMARTBIZ_API_URL`, `SMARTBIZ_FRONTEND_URL`, and `SMARTBIZ_ACCOUNTS_FILE`
- SQL evidence: `backend/storage/app/private/qa-results-smartbiz_demo.json`
- Browser evidence: `.runtime/qa-artifacts/browser-results.json`, `dashboard-php.png`, `reports-sales-php.png`, `users.png`, and the downloaded CSV. These generated artifacts are local/ignored rather than source-controlled credentials or exports.
- Temporary browser runner: `.runtime/qa-browser.mjs`, using the installed Edge debugger protocol without adding a browser dependency.

In a clean terminal, default backend checks are `php artisan test --compact` and `composer validate --strict --no-check-publish` from `backend`. Frontend checks are `npm.cmd run test`, `npm.cmd run typecheck`, `npm.cmd run lint`, and `npm.cmd run build` from `frontend`. Demo setup and reconciliation require a local MySQL account; follow the sample-data guide for isolation.
