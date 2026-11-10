# Sample data report

Database: **smartbiz_demo**. Reference: **2026-10-28 12:00:00 Asia/Manila**. Original `smartbiz` was not seeded or migrated.

## Existing and new counts

| Supported entity/table | Existing | New scenario | Browser QA | Final physical rows |
| --- | ---: | ---: | ---: | ---: |
| users | 14 | 1 | 0 | 15 |
| customers | 500 | 20 | 1 archived | 521 |
| leads | 300 | 30 | 0 | 330 |
| sales | 200 | 100 | 0 | 300 |
| appointments | 250 | 22 | 0 | 272 |
| tasks | 300 | 72 | 0 | 372 |
| automation_logs | 0 | 50 | 0 | 50 |

There are **520 non-archived customers**. The extra physical customer was created, edited and soft-archived through the actual browser. It was not an existing record. Its identifier is recorded at `.runtime/qa2026-browser/browser-customer.json`. Rerunning browser checks reuses that evidence instead of generating another customer.

The six primary business/user tables contain 1,810 physical rows, including that archived browser fixture. Automation logs and supporting rows are additional. Suggested targets were not used to delete excess existing data.

There is **no standalone Opportunities table**. Opportunities are existing staged leads. The preserved baseline contains 200 leads labeled demo opportunities, and all 330 leads use the actual seven pipeline stages. No extra opportunity entity was invented.

## Supporting rows and relationships

Final counts: 520 customer assignment pivots; 123 customer activities; 11 staff schedules and 55 working windows; 20 preserved notes; 30 preserved task comments; 60 notifications; two disabled workflows; 50 automation events, 50 execution logs and 50 steps. Role/permission definitions remain three roles, sixteen permissions and thirty-two role-permission mappings.

The new account is an ordinary canonical Admin; existing users keep their roles. New customers use reserved `example.test` email addresses, clearly fictional +63 000 numbers, Philippine Faker addresses and identifiable `QA2026-` codes or names.

New sales reference eligible existing-schema customers and Won leads. Distinct values are used. The 100 added transactions include 95 completed entries and five audited void examples. Across all preserved plus new rows there are 275 completed and 25 voided entries. No future forecast was stored as a completed sale.

Thirty leads were added: twenty Won supply agreements and ten open November/December planned contracts. Planned contracts use supported stages and `expected_close_date`, with no artificial future sale.

Twenty-two appointments cover the reference day plus planned November/December work. They belong to the new QA Admin's separate 09:00–17:00 Asia/Manila weekday schedule, avoiding edits or conflicts with old schedules. No unsupported Pending/No Show state was inserted.

Twenty-two manual tasks cover current completion, overdue work and planned procurement; fifty more tasks were produced by actual automation execution. The automation records are controlled QA event occurrences, not claimed organic production history. Every log actually ran a local create_task action and succeeded. The workflow was disabled immediately afterwards; no external provider was contacted.

## Dates and trends

The earlier 2025 data is preserved. Additive completed sales span January–October 2026; one completed transaction of **PHP 91,523.97** occurs at 11:00 on the fixed reference day. November and December contain planned work/open contracts only, with zero recognized sales.

Monthly revenue reflects lower January activity, February/March growth, stable April, higher May, a June decline, July recovery, strong August/September and mixed October. All existing reports use real database aggregates rather than injected display statistics.

The existing schema has no sales Pending/Refunded state or separate forecast ledger. Those records were not fabricated.

## Seeder execution and repeatability

New seeder: `Database\Seeders\Qa2026ScenarioSeeder`. It reuses existing factories and Faker. Command:

```powershell
cd backend
php artisan smartbiz:qa-2026 --seed --no-interaction
```

The command connects explicitly to local `smartbiz_demo`, saves a pre-run preservation snapshot, appends once, and validates. The seeder refuses production, wrong databases, missing canonical roles, duplicate unmarked QA accounts, and missing manifests for an existing marker. A repeated run completed successfully with **no duplicate records or credential changes**.

No reset, truncate, destructive migration or overwrite of an old business row was performed. One newly generated sale was placed on the reference day during fixture preparation; the source seeder now generates that date directly.

## Private credentials and evidence

The new account's email is `qa2026.admin@example.test`. Its randomly generated password is saved only in:

```text
backend/storage/app/private/qa-2026-manifest.json
```

Existing demo credentials remain in `demo-accounts-smartbiz_demo.json`. The local original `local-accounts.json` belongs to the original database and is not the demo account file. All these private paths are ignored by Git; no password is reproduced here.

Preservation snapshot: `qa-2026-baseline.json`. Validation output: `qa-2026-validation.json`, in the same private directory. Original snapshots were retained. Business/grant hashes match; normal auth token/timestamp changes are reported separately, with old credentials verified.

Safe setup, test execution and transient-data reset rules are in [QA_SETUP_GUIDE.md](QA_SETUP_GUIDE.md).
# USD demo extension — October 28, 2026

The active localhost:3000 application now uses localhost:8000 and `smartbiz_demo`. `SmartBizQADemoSeeder` appended 100 customers, 80 staged leads, 200 USD sales (180 completed, 20 voided), 60 tasks, and 40 appointments. It reused existing users and grants. Historical records cover January–October 2026; future appointments respect existing availability and avoid active overlaps. The fixed reference is October 28, 2026 at 12:00 Asia/Manila.

Current physical counts: 15 users, 621 customers, 410 leads, 500 sales, 432 tasks, 312 appointments. Counts include archived rows; dashboard counts apply their date and visibility rules. The original 300 PHP sales remain intact. Opportunities are represented by staged leads; no separate opportunity entity exists. Sales support completed/voided, so pending/cancelled sale statuses were not invented.

The private USD manifest records generated IDs. A repeated seed is a no-op. Hash comparison against `usd-qa-baseline.json` found no changes to the original database or pre-existing demo business/authorization rows. Login timestamps and remember tokens are excluded from stable user comparisons; passwords, roles and account status remain checked.

