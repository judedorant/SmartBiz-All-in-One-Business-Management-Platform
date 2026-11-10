# Bug report — QA 2026-10-28

No Critical application defect or business-report calculation failure was confirmed in this pass. The following findings distinguish application defects, QA safety defects and environment/test-harness issues.

## QA-SAFE-001 — Real database test override was not guarded

- **Module:** Backend test infrastructure
- **Severity:** High — potential data-loss risk, not observed data loss
- **Description/root cause:** The standard suite uses RefreshDatabase and previously relied solely on PHPUnit environment defaults. An external MySQL override could select a real database before refresh traits ran.
- **Reproduction:** Review prior Tests/TestCase and RefreshDatabase usage. No destructive reproduction against valuable data was attempted. The safe negative check selected smartbiz_demo for an ordinary test and verified rejection.
- **Expected:** Ordinary tests cannot refresh a populated MySQL target.
- **Actual before fix:** No base-class connection safeguard.
- **Fix:** Tests/TestCase now permits normal tests only on SQLite :memory:. Only the exact opt-in QaDemoLiveTest class may use local smartbiz_demo; it uses DatabaseTransactions and never runs migrations.
- **Validation:** Negative test passed before any refresh; standard suite and twelve live MySQL cases passed.
- **Status:** Fixed and verified.

## QA-UI-002 — Sidebar displayed an obsolete phase

- **Module:** Navigation
- **Severity:** Low
- **Steps:** Sign in as demo Admin on desktop and read the sidebar footer.
- **Expected:** Phase 7, consistent with implemented reports.
- **Actual:** Phase 6.
- **Root cause:** Hardcoded version label was not updated when Phase 7 shipped.
- **Fix:** One text change in frontend/src/components/layout/dashboard-shell.tsx.
- **Validation:** Existing five navigation tests passed; optimized build passed; final real browser asserted the Phase 7 text and captured screenshots.
- **Status:** Fixed and verified.

## QA-AUDIT-003 — Preservation monitor classified logout token rotation as a data defect

- **Module:** New QA preservation tooling
- **Severity:** Medium — false-positive audit result
- **Steps:** Snapshot users, then run successful login/logout checks for the fourteen original demo accounts and compare row digests.
- **Expected:** Business/authorization changes fail; normal authentication metadata is separately explained.
- **Actual:** Fourteen user-row hashes changed because the original monitor still included remember_token.
- **Root cause:** Laravel SessionGuard rotates nonempty remember tokens on logout. The first snapshot omitted last_login_at/updated_at but included remember_token.
- **Fix:** Preserve the raw legacy user-row deltas; separately verify existing passwords, roles and active status. New snapshots carry stable user digests excluding expected authentication metadata. Business rows, permissions and grant tables retain strict comparison.
- **Validation:** A transactional test proved logout changed only expected auth fields, kept the password identical and retained roles. All fourteen existing credentials passed verification; original-business and existing-demo-business/grant snapshots match.
- **Limit:** The first aggregate snapshot has no per-column history, so byte-for-byte preservation of those fourteen full user rows is not claimed or retroactively fabricated. The raw baseline was not replaced.
- **Status:** Classification corrected and verified; legacy snapshot limit documented.

## QA-ENV-004 — Demo services were stopped

- **Module:** Local QA environment
- **Severity:** Low
- **Steps:** Open localhost:3001 at the start of browser execution.
- **Expected:** Running demo frontend/API.
- **Actual:** ERR_CONNECTION_REFUSED; only the original 3000/8000 services were running.
- **Root cause:** Prior QA server processes were no longer active.
- **Fix:** Start the native demo API on 8001, build the isolated frontend, and start it on 3001. No container or original service was changed.
- **Validation:** Fourteen-account HTTP smoke and real desktop/mobile checks passed.
- **Status:** Resolved; commands in QA_SETUP_GUIDE.md.

## Harness corrections, not application defects

- The first synthetic native logout test omitted a stateful Origin and logged a missing-session exception. Supplying the configured first-party Origin corrected the test. The final twelve-case native run passed.
- Repeated browser CSV downloads overwrite an existing filename in Edge; new-filename-only detection was corrected to check modification times too. Four actual downloads passed.
- Mobile captures now wait for dynamic charts/calendar to render and select PHP. Loading placeholders and valid USD zero states were not labeled calculation defects.
- The browser's simulated reporting 500 is a deliberate fault injection. It demonstrated meaningful UI errors and successful retry; it is not a discovered backend failure.

## Unresolved coverage and feature limits

| Item | Classification | Status |
| --- | --- | --- |
| Legacy two-process MySQL concurrency fixture requires destructive resets | Coverage limitation | BLOCKED in this pass; sequential native conflicts passed |
| Real Google/SMTP/Sheets/Calendar/webhook delivery | External setup needed | Not executed; provider simulations passed |
| Raw entity CSVs, employee reports, separate opportunity table and unsupported statuses | Unimplemented scope | Not fabricated or marked tested |
| Exhaustive accessibility/load/security audit and full session-TTL wait | Additional QA scope | Not executed |

Existing authorization remains intact. No middleware bypass, broad permission grant, password reset, original-database reset or schema redesign was introduced.
