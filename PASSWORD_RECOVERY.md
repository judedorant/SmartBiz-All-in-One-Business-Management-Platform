# SmartBiz password recovery implementation

Implemented and verified November 4, 2026. The active database remains `smartbiz_demo`; database configuration and existing account passwords were preserved. No migration, database reset, seeder, or permission expansion was performed. Automated password changes use disposable SQLite in-memory test users, never demo accounts.

## Available flows

- Login → **Forgot Password?** → `/forgot-password` → generic recovery response.
- Email button → `/reset-password?token=...&email=...` → new password and confirmation → login.
- **Settings → Change password**, available to Admin, Manager and Staff, requires the current password. All sessions are invalidated, including the current one, then the user signs in again.
- **Users → Send Password Reset**, available to an active Admin with `users.manage`, sends to the selected user's stored email. The action accepts no password, recipient override or tenant ID. Requesting a reset never changes a password.

Laravel's configured password broker stores hashed reset tokens, expires them after 60 minutes, throttles reissue for 60 seconds, and consumes them once. User-row locking serializes concurrent reset consumption. New passwords require at least 12 characters, uppercase/lowercase letters, a digit and a symbol, confirmation, a different value from the current password, and no more than 72 bytes for bcrypt safety. Database sessions, stored personal access tokens, pending reset tokens and remember credentials are revoked when the password changes. A password-changed security notification is sent after commit.

Public recovery returns the same message for unknown, inactive, recently requested and delivery-failed accounts. Separate endpoint/IP/account/admin-target rate limits apply. API requests retain Sanctum CSRF protection. Audit rows record recovery requests, successful changes, and admin actor/target IDs without passwords or tokens. Password-related inputs are excluded from flashed exception data.

## API endpoints and examples

The following requested paths and their `/api/v1` equivalents are available. Examples use placeholders, not working credentials. Obtain `/sanctum/csrf-cookie` first and send the `X-XSRF-TOKEN` header with the SPA session cookies.

```http
POST /api/forgot-password
Content-Type: application/json

{"email":"user@example.test"}
```

```http
POST /api/reset-password
Content-Type: application/json

{"email":"user@example.test","token":"<token-from-email>","password":"<new-strong-password>","password_confirmation":"<same-new-password>"}
```

```http
PUT /api/profile/change-password
Content-Type: application/json

{"current_password":"<current-password>","password":"<new-strong-password>","password_confirmation":"<same-new-password>"}
```

```http
POST /api/v1/users/12/send-password-reset
```

Successful responses use the existing `success`, `message`, `data` envelope. Recovery always responds with: `If an account exists with this email, a password reset link has been sent.` Validation failures return 422, unauthenticated requests 401, forbidden admin actions 403, missing SPA CSRF 419, and rate limits 429.

## Local email testing

Mailpit container `smartbiz-mailpit` is running with SMTP at **127.0.0.1:2525** and inbox **http://127.0.0.1:8025**. Use the explicit IPv4 address: this machine also has an older native Mailpit instance reachable through IPv6 `localhost:8025`, which is a different inbox.

To restart the existing container:

```powershell
docker start smartbiz-mailpit
```

For a fresh installation only, if the container does not exist:

```powershell
docker run --detach --name smartbiz-mailpit --publish 127.0.0.1:2525:1025 --publish 127.0.0.1:8025:8025 axllent/mailpit:v1.33.0
```

Effective local recovery settings (defaults already match this setup):

```dotenv
PASSWORD_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_FROM_ADDRESS=noreply@smartbiz.test
MAIL_FROM_NAME=SmartBiz
FRONTEND_URL=http://localhost:3000
```

`PASSWORD_MAILER` is intentionally independent of `MAIL_MAILER=log`. Recovery and password-change notifications refuse log, failover, roundrobin and unapproved custom transports, so reset URLs cannot fall back into application logs. SMTP delivery errors are caught and reported with a fixed warning that omits the exception and message body. The array transport is allowed only in tests. Local Mailpit captures messages; it does not deliver to real inboxes.

For production, configure `PASSWORD_MAILER=smtp` (or an approved existing transactional provider), authenticated SMTP settings through deployment secrets, a verified sender, a trusted HTTPS `FRONTEND_URL`, and the normal HTTPS Sanctum/session/CORS settings. Use your provider's required port and TLS scheme. Restart services/rebuild configuration after environment changes. Ensure infrastructure access logs and monitoring redact query strings on `/reset-password` and never capture password request bodies. Next's development request logger ignores this route; the page sends `Referrer-Policy: no-referrer` and `Cache-Control: no-store, private`.

No existing users or credential manifests were reset during implementation. Browser verification requested a recovery email and opened its form without submitting the valid token. The existing QA admin still logged in with its original password. Local Mailpit contains a live recovery email; protect access to this inbox as you would any development mailbox.

## Verified single-workspace architecture

Inspection of Laravel migrations, models, policies, middleware, authentication, database configuration and the live MySQL schema confirms that this application currently uses a **single-workspace database architecture**. There is no tenant model, tenant table, user tenant column, tenant middleware or tenant policy. Spatie Permission teams mode is disabled. Authentication uses the shared `users` provider, `web` guard and Sanctum stateful cookies with database-backed sessions.

Read-only live inspection confirmed MySQL `smartbiz_demo`, matching the configured database, with **15 users: 2 Admins, 3 Managers and 10 Staff**, and 16 permissions. The existing `users`, `password_reset_tokens`, `sessions`, `personal_access_tokens`, `roles` and `permissions` tables support password recovery without schema changes. No tenant/workspace/organization tables or corresponding isolation columns were found. The original `smartbiz` database remains separate; it is not a tenant selected dynamically by the application.

Forgot Password, Reset Password and Change Password therefore operate on all existing active users in the configured workspace, regardless of role. The admin action queries only this database, denies a subject on another connection/database, rejects client-supplied tenant selection, and applies the existing user-administration policy plus the Admin role requirement. Inactive accounts retain the existing login restriction; recovery never reactivates them.

No multi-tenant architecture is needed or introduced for this feature. Connection-boundary and tenant-input tests are defensive checks, not claims of row-level tenant isolation. Multi-tenancy is outside the current application scope and is **not a blocker** for these single-workspace password flows. Production SMTP configuration remains a deployment requirement.

The architecture follow-up used read-only queries and source inspection only. No migrations, schema changes, seeders or password resets were executed. Existing users, roles, permissions and business records were preserved. The automated test totals below were executed during the preceding implementation; the backend suite was not rerun for this documentation-only follow-up because its disposable test setup internally invokes migrations.

The previously existing direct password field in the authorized user-management workflow was retained; the new **Send Password Reset** action never accepts or sets an arbitrary password. Existing tests for that workflow still pass. Password changes through recovery/settings do not update old private demo credential manifests automatically.

Production SMTP delivery and a production deployment were not tested. Local SMTP, API and browser flows were tested.

## Affected files

New backend files:

- `backend/app/Http/Controllers/Api/V1/PasswordController.php`
- `backend/app/Http/Requests/PasswordRecoveryRequest.php`
- `backend/app/Services/PasswordSecurity.php`
- `backend/app/Notifications/PasswordRecovery.php`
- `backend/app/Notifications/PasswordChanged.php`
- `backend/config/passwords.php`
- `backend/tests/Feature/PasswordRecoveryTest.php`

Modified backend files:

- `backend/app/Models/User.php`
- `backend/app/Policies/UserPolicy.php`
- `backend/app/Providers/AppServiceProvider.php`
- `backend/bootstrap/app.php`
- `backend/routes/api.php`
- `backend/.env.example`

New frontend files:

- `frontend/src/features/auth/password-form.tsx`
- `frontend/src/features/users/send-password-reset.tsx`
- `frontend/src/app/(auth)/forgot-password/page.tsx`
- `frontend/src/app/(auth)/reset-password/page.tsx`
- `frontend/tests/password-recovery.test.tsx`

Modified frontend files:

- `frontend/src/features/auth/login-form.tsx`
- `frontend/src/features/users/workspace.tsx`
- `frontend/src/app/(dashboard)/settings/page.tsx`
- `frontend/next.config.ts`

No package dependencies or migrations were added. `.runtime/password-browser.mjs` and its screenshots/results are ignored local QA artifacts. Other pre-existing workspace changes were preserved.

## Verification

- Focused backend recovery suite: **26 passed, 131 assertions**, including all roles, unknown/inactive accounts, valid/invalid/expired/reused/mismatched-user tokens, strength/confirmation/reuse/multibyte limits, notifications, revocation, admin authorization/injection/boundary checks, rate limiting, safe mail rendering and delivery failure handling.
- Full frontend suite: **102 passed**; recovery-specific suite **8 passed** again after formatting. ESLint and TypeScript passed.
- Live browser: **10 checks passed**, no runtime exceptions: login recovery link, generic response with real CSRF, SMTP Mailpit delivery, reset form from email, invalid-token error, unchanged existing admin login, authenticated settings form, admin action, mobile layout, no reset token in Laravel/Next application logs.
- A separate live request without CSRF returned **419** as expected.
- Final full backend suite: **269 passed, 13 expected skips, 1,628 assertions** (282 cases total). Pint passed. The final 26-test recovery suite includes the additional inactive-user, admin authentication, current-password reuse and bcrypt byte-limit cases.

Reproduce from `backend` with `php artisan test --compact tests/Feature/PasswordRecoveryTest.php`; ordinary tests require SQLite `:memory:` and refuse the live databases. From `frontend`, run `npm test -- --run tests/password-recovery.test.tsx`, `npm run lint`, and `npm run typecheck`.

Implementation follows the installed Laravel 13.38 password broker and notification APIs: https://laravel.com/framework/docs/passwords.
