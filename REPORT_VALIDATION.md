# Report accuracy validation

Reference: **2026-10-28 12:00:00 Asia/Manila**. Main period: **2026-01-01–2026-12-31**, PHP. Validation passed **1,571 independent SQL/relationship checks** with zero calculation failures.

The CLI freezes only its own process for reproducibility. Browser/server wall-clock time is not altered. Time-sensitive upcoming/overdue figures can change as the day advances; their rules and generated timestamp must be compared consistently.

## Dashboard values

| Measure | Independent database expectation | Application value | Result |
| --- | ---: | ---: | --- |
| Customers created in the annual cohort | 436 | 436 | PASS |
| Open leads in cohort | 189 | 189 | PASS |
| Qualified leads | 40 | 40 | PASS |
| Won leads | 56 | 56 | PASS |
| Lost leads | 35 | 35 | PASS |
| Total cohort leads | 280 | 280 | PASS |
| Completed PHP revenue | 30,755,096.10 | 30,755,096.10 | PASS |
| Pending + In Progress tasks in cohort | 195 | 195 | PASS |
| Overdue unfinished cohort tasks at reference | 117 | 117 | PASS |
| Upcoming active appointments in period at reference | 61 | 61 | PASS |
| Won/all cohort leads | 20.00% | 20.00% | PASS |

“Total customers” on the current dashboard is explicitly a creation-date cohort metric. It does not mean all 520 non-archived customers. Growth includes the opening balance: 84 pre-2026 customers plus 436 new ones = 520. The archived browser fixture is excluded.

## Daily, weekly, monthly and annual filters

| Filter | Local dates | SQL revenue | Application revenue | Result |
| --- | --- | ---: | ---: | --- |
| Today | Oct 28 | 91,523.97 | 91,523.97 | PASS |
| Last 7 days | Oct 22–28 | 1,541,479.61 | 1,541,479.61 | PASS |
| Last 30 days | Sep 29–Oct 28 | 3,292,144.32 | 3,292,144.32 | PASS |
| This month | Oct 1–28 | 3,292,144.32 | 3,292,144.32 | PASS |
| Custom annual period | Jan 1–Dec 31 | 30,755,096.10 | 30,755,096.10 | PASS |

Expected values use explicit UTC half-open bounds converted from Asia/Manila dates, `SUM(amount)`, completed status, PHP currency and the application's archive rules. The daily/weekly/monthly endpoint tests use literal local-date bounds independently of the selected preset.

## Monthly sales report

| Month | SQL expected / actual application PHP | Status |
| --- | ---: | --- |
| Jan | 2,111,350.58 | PASS |
| Feb | 2,156,203.84 | PASS |
| Mar | 2,950,336.73 | PASS |
| Apr | 2,982,068.94 | PASS |
| May | 3,134,232.03 | PASS |
| Jun | 2,492,639.53 | PASS |
| Jul | 2,928,089.46 | PASS |
| Aug | 4,542,103.76 | PASS |
| Sep | 4,165,926.91 | PASS |
| Oct | 3,292,144.32 | PASS |
| Nov | 0.00 | PASS — planned work is not revenue |
| Dec | 0.00 | PASS — planned work is not revenue |

The sum matches annual revenue. Voided entries are excluded. Forecast values and Won lead estimates never automatically become completed revenue.

## Every existing report

| Report | Calculation/check | Actual result |
| --- | --- | --- |
| Monthly sales | Each bucket independently sums completed PHP sales by close date | All 12 rows match |
| Customer growth | Daily registrations plus independent running count/opening balance, excluding archives | All 365 daily rows match; ending total 520 |
| Task completion | Daily creation counts and completion-date counts for currently completed tasks | All 365 rows match; 322 creations and 64 completions during the year |
| Lead pipeline | Creation cohort grouped by current stage; independent count and DECIMAL SUM per stage | All seven rows and estimated-value cells match |

Task created/completed series have distinct date rules; dividing their sums is not generally a defined cohort completion rate. The dashboard's lead outcome rate is Won/total, not `converted_at / total`. Neither metric was silently relabeled to satisfy a nonexistent report.

Annual pipeline values:

| Stage | Count | SQL expected / application estimated value |
| --- | ---: | ---: |
| New | 36 | 4,556,173.68 |
| Contacted | 35 | 4,255,067.46 |
| Qualified | 40 | 5,517,757.51 |
| Proposal | 39 | 4,587,594.41 |
| Negotiation | 39 | 5,093,216.64 |
| Won | 56 | 6,489,032.11 |
| Lost | 35 | 4,593,000.08 |

Lead estimates have no per-row currency field in the existing schema; they are not a currency-converted revenue measure.

## Frontend and CSV cross-checks

The real desktop browser selected PHP and the annual dates; displayed report cells matched the independently verified row sets. Four CSVs were actually downloaded and checked for report title, selected period and PHP context. Native MySQL tests parsed each CSV and compared every exported row with the corresponding API report. Existing CSV regression tests check spreadsheet formula escaping and quoting.

Real date controls, search, pagination and error/retry behavior were exercised. Five rendered charts were inspected, including the mobile dashboard after dynamic chart loading completed. No uncaught JavaScript errors were recorded.

The default currency is still USD. The demo is denominated in PHP; a USD zero result is correct, not an authorization or calculation error.

## Gaps and discrepancies

No discrepancy was found in existing report calculations. Unsupported views are not marked as tested: employee/customer revenue breakdowns, acquisition-source reporting, dedicated appointment/performance reports and raw entity exports do not exist. Daily/weekly/yearly *date windows* work through existing filters; this is not a claim that separate grouping modes exist.

The browser uses actual server time. For example, the reference-day 14:00 appointment is upcoming at the fixed 12:00 replay but may already be in the past at browser verification time. The reproducible CLI/native tests use the fixed reference; wall-clock UI values must use their actual timestamp.

Reproduce: `php artisan smartbiz:qa-2026 --no-interaction`. Detailed checks, row sets, raw user digest deltas and credential-verification results are retained in the ignored `backend/storage/app/private/qa-2026-validation.json`.
# Current USD verification — October 28, 2026

Actual browser verification used localhost:3000, Laravel localhost:8000 and MySQL `smartbiz_demo`, with an authenticated demo administrator and America/New_York browser timezone. No mock API data was used. All five chart panels rendered; no browser runtime exceptions or authorization banner occurred.

For September 29–October 28, 2026 (last 30 days, USD), the dashboard and live API showed: 53 customers, 34 active leads, 10 qualified leads, 7 won deals, USD 34,088.40 completed sales, 94 pending/in-progress tasks, 21 overdue tasks, and 12 upcoming appointments. Upcoming values are time-sensitive.

For the fixed annual audit (2026, Asia/Manila, USD), completed revenue was USD 267,061.50. The currency-aware SQL reconciliation completed without failures, including monthly report cells and dashboard metrics. The annual API revenue matched that SQL-validated result. Private evidence: `qa-2026-validation-usd.json`, `usd-qa-preservation.json`, and `.runtime/usd-dashboard-browser/result.json`; screenshot: `.runtime/usd-dashboard-browser/default-dashboard.png`.

Affected implementation: `SmartBizQADemoSeeder.php`, `SmartBizQADemoSeederTest.php`, `Qa2026Audit.php`, `RunQa2026.php`, and private `backend/.env`. The empty USD view resulted from the active app targeting the empty original database, while existing demo sales used PHP. The fix selects the authorized demo database, isolates its session cookie, and appends real USD fixtures; authorization rules and existing passwords remain unchanged.

Independent SQL also reconciled all eight live default-range metrics exactly against the captured API response, with no differences. Supplementary results: 18 completed USD sales, USD 1,893.80 average sale; October revenue USD 34,088.40 versus September USD 39,447.90, a -13.59% monthly change; task creation cohort 15 completed of 121 (12.40%); 15 completed and 3 cancelled appointments in range. Evidence: private `usd-dashboard-live-validation.json`. The application does not expose average-sale or month-over-month revenue-growth fields; those SQL statistics are not claimed as implemented UI features.

Verification: full default backend suite 243 passed, 13 expected skips, 1,497 assertions; 40 targeted seeder/dashboard/report tests passed; 12 opt-in MySQL integration tests passed with 79 assertions; Pint passed; all 14 existing demo role accounts passed live HTTP authentication and permission-boundary checks. The first opt-in invocation omitted the documented MySQL environment overrides and failed against empty in-memory SQLite; rerunning with `DB_CONNECTION=mysql`, `DB_DATABASE=smartbiz_demo`, and `SMARTBIZ_QA_LIVE=1` passed. Repeating the USD seeder confirmed no changes or duplicates. PHP reconciliation retained all 1,571 successful checks. No database reset, migration, password regeneration or permission expansion was performed.

