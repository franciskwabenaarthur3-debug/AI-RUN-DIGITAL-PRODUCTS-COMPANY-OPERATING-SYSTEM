# Security

This describes controls that are implemented now. Planned controls are listed separately so nothing is mistaken for done.

## Implemented

- **Accounts:** no public registration. Users are created with `php artisan company-os:create-user`, which requires a 12+ character password and records an audit event. `role` and `is_active` are not mass assignable.
- **Login:** Laravel session guard, bcrypt hashing, session regeneration on login, session invalidation and CSRF token rotation on logout. Logout is POST only.
- **Brute force:** 5 failed attempts per email + IP locks that pair out for 60 seconds (`LOGIN_MAX_ATTEMPTS`), plus a route throttle of 20 requests/minute per IP.
- **Deactivation:** inactive users cannot log in; an already logged-in user who is deactivated is logged out on the next request.
- **Authorization:** policies checked server-side in Form Requests and controllers, so direct requests cannot bypass hidden UI. Only founders can change settings or view the audit log.
- **Audit:** append-only `audit_logs` (model guard + PostgreSQL trigger). Failed logins store a SHA-256 hash of the attempted email, not the email or password. Keys matching `config('company_os.redacted_keys')` are replaced with `[redacted]` at any nesting depth.
- **Tracing:** every response carries an `X-Request-Id` (a valid incoming UUID is reused, anything else replaced). It is stored on audit records.
- **Sessions:** stored in the database and encrypted (`SESSION_ENCRYPT=true`). Set `SESSION_SECURE_COOKIE=true` on HTTPS environments.
- **Files:** the local disk's signed-URL serving route is disabled (`serve => false`).
- **Secrets:** none in source; `.env` is git-ignored and `.env.example` holds keys only.

## Planned (not implemented yet)

- MFA for founder accounts (before the first pilot).
- Agent identities, tool gateway permissions, approval engine (Phases 3 to 5).
- Enforcement of the AI budget and emergency pause (Phase 3, once agents exist).
- SSRF protection for website-reading tools (with the first such tool).
- Security headers / CSP middleware, upload validation, retention policies.
