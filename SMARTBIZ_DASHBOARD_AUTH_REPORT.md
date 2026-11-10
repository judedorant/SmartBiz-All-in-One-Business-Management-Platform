# Dashboard authorization incident report

Date: 2026-10-28 (America/New_York).

## Root cause

The original application's **Spatie permission cache contained an empty permission collection**, while the SQL permission tables and role assignments were correct.

Before repair, the same existing Local Admin account had:

- Identity: Local Admin, `admin@smartbiz.test`, active, Admin role, `web` guard.
- Sixteen effective permission names returned by `/api/v1/me`, including `dashboard.view`.
- Sixteen actual permission records and correct role grants in MySQL.
- Zero permissions returned by `PermissionRegistrar::getPermissions()`.
- `$user->can('dashboard.view') === false`.

This mismatch explains why the frontend displayed an authenticated Admin and permitted navigation while backend authorization denied the requests. The frontend profile lists the user's role permissions; Spatie's Gate check resolves permission names through its cached registry. An empty registry fails closed.

Fresh cookie-session logins reproduced the error before repair, so a stale browser session was not required to trigger it. Missing role grants, a wrong guard, and an authorization-policy defect were ruled out. The source of the original empty cache entry could not be established from the current evidence; no claim is made that a particular earlier command created it.

## Frontend, backend, and database routing

| Frontend | Actual API | Actual database | Administrator |
| --- | --- | --- | --- |
| http://localhost:3000 | http://localhost:8000 | smartbiz | Local Admin / admin@smartbiz.test |
| http://localhost:3001 | http://localhost:8001 | smartbiz_demo | Demo Wilbert Reyes / admin1@demo.smartbiz.test |

`frontend/.env.local` specifies `NEXT_PUBLIC_API_URL=http://localhost:8000`. The frontend API wrapper sends cookie credentials and uses that URL. The running port-8000 Laravel service uses the original local MySQL database `smartbiz`, not `smartbiz_demo`.

The separate production demo frontend was built for port 8001 using its process environment and isolated `.next-qa` output. Demo identities and populated responses match records present only in `smartbiz_demo`. Real-browser network inspection verified dashboard calls to both API origins from their corresponding frontend.

The top-right “Local Admin” name therefore identifies the original environment. It is not the documented demo administrator. The environment distinction explains the absence of demo data on port 3000, but **does not explain or justify its 403 errors**: the original Admin, Manager, and Staff all legitimately have dashboard access.

## Affected API requests

A fresh login as each original local role reproduced HTTP 403 with:

```text
GET http://localhost:8000/api/v1/dashboard/summary?preset=last_30_days&currency=PHP
GET http://localhost:8000/api/v1/dashboard/analytics?preset=last_30_days&currency=PHP
GET http://localhost:8000/api/v1/dashboard/access
```

The visible dashboard uses the summary and analytics endpoints; the access endpoint was also checked diagnostically. The response message was: “You do not have permission to perform this action.”

The stale registry affected other permission-protected endpoints too: Local Admin was also denied Users and Reports despite correct grants. The demo stack returned the expected statuses before repair.

## Authentication and authorization inspection

- Sanctum authenticates first-party sessions using the `web` guard.
- Original stateful origin: `localhost:3000`; exact credentialed CORS origin: `http://localhost:3000`.
- The demo service accepts its separate `localhost:3001` origin and uses the documented demo session cookie.
- Dashboard routes remain inside `auth:sanctum` and `active` middleware.
- `DashboardRequest::authorize()` still requires `dashboard.view`.
- Spatie permission/role rows use the matching `web` guard.
- Users policies and report permissions remain enforced.
- The frontend API wrapper already uses `credentials: "include"`; `/me` succeeded before repair, confirming that the failing requests were authenticated rather than missing cookies.

No middleware, policy, request authorization method, guard, role grant, or frontend API target was bypassed or loosened.

## Changes implemented

The runtime repair was the existing targeted package command, run against the original local configuration:

```powershell
cd backend
php artisan permission:cache-reset --no-interaction
```

This invalidated only the permission cache, not all application caches, sessions, business records, or role assignments. Subsequent requests rebuilt it from the existing SQL grants.

After repair, the original Admin's cached permission count was 16 and `can('dashboard.view')` returned true. No re-seeding, password regeneration, database reset, permission grants, server restart, frontend rebuild, or configuration retargeting was needed.

Source/document changes:

| File | Change |
| --- | --- |
| backend/tests/Feature/DashboardTest.php | Added three role-specific stale-cache recovery cases, a roleless-account rejection case, and analytics rejection coverage for an account without a role |
| SMARTBIZ_DASHBOARD_AUTH_REPORT.md | This investigation and verification report |

Inspected but unchanged: `frontend/.env.local`, frontend API/dashboard wrappers, Laravel dashboard request/controller, routes and bootstrap middleware, active-account middleware, User model/policies/resources, Sanctum/auth/CORS/cache/permission configuration, and the canonical role seeder.

## Regression tests performed

Command:

```powershell
php artisan test --compact tests/Feature/DashboardTest.php tests/Feature/PermissionTest.php tests/Feature/AuthenticationTest.php
php vendor/bin/pint --dirty --format agent
```

Result: **46 tests passed, 228 assertions; PHP formatting passed.**

The new cases deliberately install an empty permission registry in the isolated test cache. They prove that:

1. Admin, Manager and Staff have the SQL permission but initially receive 403 from both summary and analytics.
2. The targeted permission-cache reset restores both dashboard endpoints to HTTP 200.
3. The SQL role grants and user roles are unchanged.
4. Users remains Admin-only.
5. Reports remains unavailable to Staff.
6. Resetting the cache does not give an account without a role dashboard access.

Existing dashboard tests continue to verify real aggregates, exact currency-separated revenue, Staff record scoping, empty datasets, date validation, timezone/DST boundaries, and bounded query counts. Existing authentication tests retain unauthenticated and inactive-account protections.

## Live HTTP verification

Fresh authenticated cookie sessions checked the existing Admin, Manager and Staff in **both** environments.

| Role | Dashboard summary | Dashboard analytics | Users | Reports |
| --- | --- | --- | --- | --- |
| Admin | 200 | 200 | 200 | 200 |
| Manager | 200 | 200 | 403 | 200 |
| Staff | 200, scoped | 200, scoped | 403 | 403 |

All three dashboard access checks returned 200 after repair. Original local roles had returned 403 before the cache reset. The demo environment retained its intended access throughout.

Only normal login/logout verification writes occurred, including session/audit/last-login updates. Passwords, role assignments, permissions, and business records were preserved.

Final primary record counts:

| Database | Users | Customers | Leads | Sales | Appointments | Tasks |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| smartbiz | 4 | 0 | 0 | 0 | 0 | 0 |
| smartbiz_demo | 14 | 500 | 300 | 200 | 250 | 300 |

## Browser verification and final result

Headless Microsoft Edge used a separate browser profile and the existing account credentials. Each role signed in at ports 3000 and 3001.

- Original dashboard: all three roles load metrics and legitimate empty-chart states without authorization alerts. Zero business figures are expected because `smartbiz` has no business records.
- Demo dashboard: all three roles load the permitted PHP metrics and rendered sales charts. Staff sees its scoped data.
- Real network responses confirm that each frontend calls the corresponding API.
- Users/Reports role restrictions remain correct.
- No uncaught browser JavaScript exceptions were observed.

Local evidence, without passwords or cookie values:

```text
.runtime/dashboard-auth-before.json
.runtime/dashboard-auth-after.json
.runtime/qa-artifacts/dashboard-auth-browser-results.json
.runtime/qa-artifacts/local-dashboard-fixed.png
.runtime/qa-artifacts/demo-dashboard-fixed.png
```

Refresh **http://localhost:3000/dashboard** to use the repaired original environment. For populated demo data, open **http://localhost:3001** and use the existing demo credentials in `backend/storage/app/private/demo-accounts-smartbiz_demo.json`. The original `local-accounts.json` credentials belong to port 8000's original database.
