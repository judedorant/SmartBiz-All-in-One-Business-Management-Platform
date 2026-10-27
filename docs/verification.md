# Verification log

## Phase 1 checkpoint (historical)

Verified in the Codex workspace on 2026-10-26 using native PHP 8.4.22, Composer 2.10.3, Node 24.19.0, npm 11.9.0, and MySQL Community Server 8.4.6. Docker was neither used nor required for this implementation.

| Check | Result |
| --- | --- |
| Composer install from lock / platform requirements | Passed |
| Composer strict validation | Passed |
| Laravel Pint | Passed after formatting; `--dirty` was unavailable because the workspace has no Git checkout, so the full formatter was run |
| Laravel feature/unit suite, SQLite in-memory | 26 passed, 109 assertions |
| Native MySQL migration and role seeding | All 7 migrations applied; 3 roles and 15 permissions seeded |
| Local development accounts | Admin, Manager, Staff created with unique random passwords in private JSON; repeat command safely refused to overwrite them |
| Laravel process startup | Passed; port 8000 |
| Laravel config/route cache generation | Passed; caches cleared after verification for continued development |
| npm ci from final lockfile | Passed |
| Frontend Vitest / Testing Library | 13 passed across 3 test files |
| Frontend lint | Passed, no lint errors or warnings |
| Strict TypeScript check | Passed |
| Next.js production build | Passed; login, dashboard, settings, and root routes generated with no user data in the static shell |
| Next.js production startup | Passed; restarted on port 3000 after the final build |
| Production frontend dependency audit | 0 vulnerabilities reported |
| Live HTTP session smoke check | Passed for all 3 roles against MySQL |

## What the tests verify

Backend: successful/failed login, normalized email input, validation, login throttling, logout, safe current-user serialization, inactive-account session invalidation, anonymous API rejection, CSRF enforcement, exact-origin CORS behavior, role/policy matrices, permission middleware, idempotent roles, production refusal of local account setup, private password generation, and preservation of existing credentials/users.

Frontend: required-input validation, backend validation errors, successful sign-in/cache replacement, unavailable API feedback, credentials and CSRF headers, logout requests, withholding protected content while loading, anonymous redirect, rendering API-derived user/permissions, successful logout cache cleanup, and logout network failure behavior.

The live `node scripts/smoke-auth.mjs` check calls the running Laravel API and Next.js server. It obtains CSRF cookies, rejects a missing-CSRF mutation, signs in each real local account, reads `/me`, checks Manager/Staff/Admin boundaries, signs out, and confirms the old session can no longer read `/me`. Credentials are read privately and never printed. SQLite unit/feature tests do not replace this MySQL check.

## Resolved environment issues

- Missing host PHP/Composer/MySQL: workspace-local native binaries and a workspace-local shared library provide the services without root installation or containers.
- The first generated npm lockfile omitted transitive AJV entries: regenerated lock metadata and verified a clean `npm ci`.
- Next.js's TypeScript CLI-output parser failed in this runtime: enabled its compiler API path instead; standalone and build type checks remain enabled.
- Early tests exposed factory objects without their database default account status. The model and factory now explicitly default to active. CORS expectations were aligned with the library's safe fixed-origin response (an untrusted origin is never reflected).

## Remaining limitations

Frontend interaction tests run in jsdom; no real-browser end-to-end run or visual/accessibility audit has been performed. The real HTTP smoke test verifies the service boundary, not browser rendering. At the Phase 1 checkpoint, CRM and later features were deferred. See the Phase 2 checkpoint below for current CRM coverage. Production deployment has not been performed.

ESLint 9.39.5 emits an install-time deprecation warning. ESLint 10 was checked but is outside the peer ranges declared by Next.js's bundled React/import/accessibility lint plugins, so the compatible version is pinned. The transitive test dependency `whatwg-encoding` also emits a deprecation warning. These are documented tooling maintenance items; they do not fail installation, lint, tests, or builds.

The session-local native tools/data live outside `smartbiz` and are not portable application artifacts. A fresh environment needs native runtimes and a MySQL database as described in the README. Google and mail-provider credentials are not needed for Phase 1 and were not configured.

## Phase 2 checkpoint — 2026-10-26

- Full Laravel suite: **54 passed, 304 assertions** on default SQLite in-memory tests.
- The same full Laravel suite: **54 passed, 304 assertions** against a separate native MySQL 8.4 test database (`smartbiz_phase2_test`). The development database was not used as the test database.
- CRM-specific backend coverage: 28 tests, including customer CRUD/notes/history; assignment-derived visibility; record revocation; staff field restrictions; policy matrices; conversion, duplicate conversion and atomic failure; stale versions; won/lost stage updates; decimal validation; pagination/filter totals; sort injection rejection; and private assignee serialization.
- Frontend: **27 tests passed** (13 existing + 14 CRM tests). CRM interactions include validation, safe role-specific payloads, conversion, stale-edit handling, confirmation-gated deletion, stage selection, drag/drop endpoint integration, count metadata, and escaping hostile lead titles.
- TypeScript, lint, and Next.js production build passed. The CRM routes are included in the production build.
- Native MySQL CRM migration and idempotent role/stage seeding completed successfully without adding demo business records. Laravel route caching remained valid.

The permission test that changes actors within one Laravel test instance now resets cached auth guards before switching users. This avoids carrying the previous simulated actor into the next request; production PHP requests and separate live sessions do not share that test guard.

Interaction tests still run in jsdom rather than a real browser. Stale edits are tested with competing versions; simultaneous multi-browser races and visual/accessibility auditing have not been performed. At the Phase 2 checkpoint, analytics and later modules had not begun; see the Phase 3 checkpoint below. The existing Next.js/ESLint tooling limitations described above remain unchanged. No Docker or dependency additions were needed in Phase 2.

Final live HTTP smoke check after rebuilding/restarting Next.js: Admin, Manager, and Staff all passed login, current-user, CRM list reads, pipeline-stage metadata, assignee-directory authorization, role boundaries, CSRF, and logout/session invalidation. Customer/lead create pages and the pipeline route returned successfully from the running production frontend. The live check added no business records; MySQL write behavior was covered by the dedicated-database Laravel suite above.

## Phase 3 checkpoint — 2026-10-26

- Full backend suite: **88 passed, 474 assertions** on default SQLite and on the separate native MySQL test database (`smartbiz_phase2_test`). Development data was not used for RefreshDatabase tests.
- New backend cases cover date presets, inclusive local dates/exclusive UTC ends, 23/25-hour DST days and Havana midnight DST, browser timezone aliases, empty buckets, visibility across all five source modules, exact decimal/currency-separated sums, cohort semantics, bounded query counts, sale permissions, validation, lead/customer linkage, idempotency, and audited version-protected voiding.
- Frontend: **40 tests passed**, including all earlier auth/CRM tests. New coverage exercises API-derived metrics, currency/date changes, incomplete custom-range gating, errors and missing permissions, rendered Recharts charts plus accessible tables, exact large financial text, sale validation/retry IDs, confirmation-gated voiding, and staff read-only controls.
- Clean `npm ci`, strict TypeScript, lint, and Next.js production build passed. Recharts 3.10.1 and React-is 19.3.1 satisfy the selected React version's peer requirements.
- Source-table migration, role seeding, and additive lead indexes are applied to native MySQL. Backend formatter and route-cache generation pass.

An overlapping early build/test run timed out two unchanged CRM tests; sequential reruns passed without increasing timeouts. A test-fixture type assertion and the date-control watch hook were corrected; the final type/lint checks pass. Browser-reported legacy timezone aliases now have explicit regression coverage. No successful result is inferred from the earlier failed runs.

Frontend tests use jsdom and fixed chart dimensions for the chart-rendering test. A full real-browser layout/accessibility audit and simultaneous multi-process race test have not been performed. SQL row locks, current-version conflicts and idempotent retries are exercised, including on MySQL, but no production deployment or load test is claimed. Appointment/task operational UIs and their mutation safeguards remain in Phases 4/5. Existing ESLint deprecation and Next.js compiler-API-mode limitations remain documented above. No business/demo records are automatically seeded.

Final Phase 3 live smoke check passed for Admin, Manager, and Staff after restarting the built frontend: existing auth/CRM checks, both dashboard endpoints, browser timezone alias metadata, scoped sales reads, and recording permission boundaries (validation-only requests; no business inserts). Logout still invalidates each session. The production frontend dependency audit reports **0 vulnerabilities**. Generated local-account credentials remain mode 0600 and unchanged.

## Phase 4 checkpoint — 2026-10-26

- Native MySQL full suite: **118 passed, 609 assertions**, including a real two-process booking contention regression.
- Default SQLite suite: **117 passed, one MySQL-only concurrency test skipped, 603 assertions**. SQLite is not presented as proof of row-lock behavior.
- Frontend: **53 passed**, including existing auth/CRM/analytics tests plus appointment forms, UTC conversion/version submission, conflict feedback, working-window validation, destructive confirmation, staff read-only controls, calendar callback persistence/revert and DST-instant preservation.
- Clean npm ci, strict TypeScript, frontend lint and Next.js production build pass with FullCalendar 6.1.22's matched plugin family. The first attempted 7.x plugin install failed because those plugin versions did not exist; the corrected compatible family installed successfully.
- The native appointment migration is applied without resetting existing records. Laravel route cache and scheduler registration pass. The reminder scan completed with no eligible development appointments, and an empty native queue-worker run exited successfully.

The first MySQL contention test exposed repeatable-read snapshot behavior: a user-row mutex followed by a non-locking overlap query allowed both requests to insert. The final implementation uses current locking reads for conflicts, schedule data and existing-booking checks. The regression opens an outer transaction before waiting, then verifies one 201, one 409, and one stored appointment. This test uses a dedicated database ending in _test and cleans it afterwards.

DST checks cover staff-zone window validation and a fall-back interval that would leave a narrow working window between its endpoints. Frontend datetime-local edits preserve their unchanged original UTC instant, avoiding a silent one-hour shift in a repeated hour. The earlier overlapping heavy checks also timed out two unchanged sales UI tests; sequential reruns passed without increasing test timeouts.

Reminder tests use fake notifications and rendered Laravel mail templates. They verify eligible dispatch, known-sent suppression, cancellation/archive/version/permission/inactive skips, sanitized retryable provider failures, and escaped titles. No real SMTP provider or inbox delivery was verified. Local log transport is development-only; external mail needs configured environment credentials. A crash after provider acceptance but before DB commit can still result in uncertain/duplicate delivery.

Calendar callback tests mock the FullCalendar wrapper rather than claim a real-browser drag/layout audit. Production build compatibility is verified; a real-browser end-to-end/accessibility audit and load testing remain outstanding. Optional Google OAuth/calendar synchronization stays in Phase 6 per the development plan and has no inert connection UI in Phase 4. Phase 5 task management has not begun. Existing documented ESLint deprecation and Next.js compiler API-mode constraints remain.


Final Phase 4 checks after the reminder retry correction: the SQLite suite passes 117 tests (one MySQL-only test skipped), the MySQL suite passes all 118, and live smoke checks pass for all three roles including appointment/calendar/availability reads and management permission boundaries. A new regression verifies that an operator retry can recover a terminally failed reminder; another verifies that the scheduler does not redispatch in-progress retries or terminal failures. The production frontend audit reports 0 vulnerabilities. Composer strict validation passes. The rebuilt frontend is running, local mail.default is confirmed as log, and native queue/scheduler processes are started for local processing. No external inbox delivery is claimed.

## Phase 5 checkpoint — 2026-10-26

- Native MySQL full suite: **141 passed, 798 assertions**, against dedicated `smartbiz_phase2_test`. This includes the retained two-process appointment contention regression and 23 new task/comment/notification tests.
- Default SQLite full suite: **140 passed, one MySQL-only test skipped, 792 assertions**. Production/default database configuration remains MySQL.
- Frontend: **64 tests passed** across 12 files. New cases cover required inputs, real-user creation defaults, prohibited staff payload fields, unchanged-link omission, UTC/DST preservation, conflict feedback, cancellation confirmation, overdue filtering, comment author controls, lazy notification loading, read/read-all requests, Escape/focus behavior, unsafe/inaccessible link suppression, errors/retry, and one notification center in the shell.
- Clean `npm ci`, strict TypeScript, ESLint, and Next.js production build pass. All task list/create/detail/edit routes are in the build. Production npm dependency audit: **0 vulnerabilities**. No new dependencies were introduced.
- Native MySQL task/comment/version/index and database notification migrations applied without resetting existing development records. Laravel route caching and Composer strict validation pass. Pint dirty mode cannot operate without a Git checkout in this environment; full Pint formatting was used successfully.
- Live cookie-session smoke checks passed after rebuilding/restarting Next.js: Admin, Manager, and Staff login/current user, earlier CRM/analytics/scheduling flows, task lists, task creation authorization (validation-only requests), eligible staff directory boundaries, notification list/unread count, CSRF, and logout/session revocation. New frontend task routes respond successfully. These smoke requests create no business records and do not mark existing notices read.

Backend tests verify full task CRUD/archiving, record scoping, policy matrices, prohibited owner/link/server fields, eligible/inactive owners, scoped lead/customer consistency, assignment-derived customer access and its revocation, overdue/search/sort/pagination, atomic audit/notification writes, no-op/stale update effects, completion timestamps/reopening, and dashboard metrics/series after a task mutation. Comment tests cover parent scoping, authorship, management moderation, versions, spoofed fields and size/required validation. Database notifications are real persisted records in the tests, covering recipient deduplication/actor suppression, generic history after reassignment/archive, ownership of read operations, unread filters/read-all isolation, safe unknown types and rollback with task changes. Tests that switch users reset Laravel's cached auth guards between simulated actors.

The first targeted test run exposed cached Sanctum actors in tests that changed users within one application instance; guard resets fixed the test setup. Initial frontend type checking caught an unsupported button variant and ref prop; both were corrected using existing component capabilities. The final suites above pass. Prettier's first parenthesized route glob did not match; formatting the task route directory directly succeeded.

Frontend interaction coverage uses jsdom. A real-browser end-to-end/accessibility audit, load test, and production deployment remain outstanding. New task contention is covered by transactional version checks and stale-write tests on both databases; the real two-process regression is for appointments, not a claimed task load test. In-app task notices use only Laravel's synchronous database channel: external email delivery and Google integrations are not part of Phase 5. Existing log-mail, ESLint deprecation and Next.js compiler-API-mode limitations remain documented. No Docker commands or new credentials were needed. Phase 6 automation/integration work has not begun.

## Phase 6 checkpoint — 2026-10-27

- Full native MySQL suite: **195 passed, 991 assertions**, using the disposable `smartbiz_phase2_test` database, including the retained two-process booking contention regression. The final full run took approximately 273 seconds in this environment and completed successfully without increasing test timeouts.
- Full SQLite suite: **194 passed, one MySQL-only test skipped, 985 assertions**. Main development/production configuration remains MySQL.
- The 54 new backend tests cover workflow CRUD/versioning/authorization/active toggles, strict allowlists and placeholders, captured eligible workflow IDs, transactional outbox rollback, stage conditions, all source/scheduled trigger families, generated-task loop prevention, ordered step resumption, partial local rollback, recipient notices, safe retry limits/controls, actor/source revocation, leases/interruption uncertainty, encrypted credentials, OAuth PKCE/state replay/expiry/session binding, scope rejection, token refresh, Gmail MIME, Sheets RAW values, deterministic Calendar updates/deletes, webhook signatures/body and ID tampering/expiry/replay, bounded forwarding, public-host/address guards, encrypted secrets, and outbound delivery deduplication.
- Full frontend suite: **79 passed across 13 files**. The 15 new tests cover workflow/action validation, safe versioned payloads, step ordering, active toggles, archive confirmation, uncertain versus retryable outcomes, Staff restrictions, missing OAuth readiness, redirect-origin validation, webhook secret preservation/required input, HTTPS validation and API-error feedback.
- Clean npm ci, standalone strict TypeScript, ESLint and Next.js production build pass. New `/automation` and `/settings/integrations` routes are in the build. Production dependency audit reports **0 vulnerabilities**. Existing ESLint/whatwg-encoding deprecation warnings remain tooling debt; no dependencies were added or upgraded.
- Composer strict validation, full Pint formatting, API route caching, event listener registration and scheduler registration pass. Dirty-mode Pint cannot operate without a Git checkout here; the final full formatter reports passed.
- All three additive migrations are applied to native MySQL, preserving existing records: automation/integration tables, LONGTEXT encrypted action settings, and task origin FK. No workflows, business examples or external credentials were seeded.
- Native automation scan completed, and an empty database queue-worker run exited successfully. Continuous queue/scheduler processes are running; the scheduler executed both appointment-reminder and automation scans successfully. No actual external mail/Google/webhook side effect was needed to run these local checks.
- After starting the rebuilt frontend and Laravel API, real cookie-session smoke checks passed for Admin, Manager and Staff: earlier CRM/analytics/scheduling/tasks/notifications, new automation/catalog/integration/webhook authorization boundaries, validation-only management writes, safe integration serialization, CSRF and logout/session invalidation. New frontend routes respond successfully. No business records were inserted by smoke checks, and no live provider consent or connect operation was initiated.

Environment/test corrections: the native MySQL process had stopped after environment resumption and needed restart; two migration attempts occurred before readiness and failed without applying changes. Once ready, migrations succeeded. OAuth tests initially lacked SPA session cookies; the connect boundary now explicitly requires a first-party session and the tests preserve the real cookie for callback checks. A misplaced JSX mail-mode notice was caught by formatting and corrected before final type/lint/build runs. A scheduler-list command initially lacked sandbox network access to the MySQL cache-lock socket; rerunning with native network permission succeeded. Docker was never used and no denied container permissions were requested.

External behavior is verified with fake HTTP/Mail providers; Google tests prevent stray network calls. Google Cloud client/consent configuration, a real account's permissions, actual Gmail/SMTP inbox arrival, a real Sheets append, Calendar synchronization against a real account, and delivery to a real webhook receiver remain unverified until configured. Forward proxy egress rules must enforce the approved-host/public-address boundary. Browser tests use jsdom; no real-browser OAuth/E2E/accessibility audit, load test, or production deployment is claimed.

Local mail is log-only and Google readiness is disabled until server-side credentials are supplied. Calendar sync is explicit, one-way and workflow-driven; configure paired created/updated workflows for lifecycle coverage. Provider ambiguity is deliberately held as uncertain rather than advertised as exactly-once delivery. Read automation.md for setup, deduplication, forwarding/loop limits and retention requirements. Phase 7 reporting/exports has not begun.
