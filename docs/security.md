# Security

This describes controls that are implemented now. Planned controls are listed separately so nothing is mistaken for done.

## Implemented

- **Accounts:** no public registration. Users are created with `php artisan company-os:create-user`, which requires a 12+ character password and records an audit event. `role` and `is_active` are not mass assignable.
- **Login:** Laravel session guard, bcrypt hashing, session regeneration on login, session invalidation and CSRF token rotation on logout. Logout is POST only.
- **Brute force:** 5 failed attempts per email + IP locks that pair out for 60 seconds (`LOGIN_MAX_ATTEMPTS`), plus a route throttle of 20 requests/minute per IP.
- **Deactivation:** inactive users cannot log in; an already logged-in user who is deactivated is logged out on the next request.
- **Authorization:** policies checked server-side in Form Requests and controllers, so direct requests cannot bypass hidden UI. Only founders can change settings or view the audit log.
- **CRM access:** founders and operators create and change leads and customers; viewers are read-only. Only founders manage service packages and see or change customer billing details. Every rule is checked in policies, and tests send direct requests to prove hidden controls cannot be bypassed.
- **Customer billing details:** encrypted at rest with `APP_KEY`, excluded from serialization and from audit metadata (audit records note only that billing changed). Rotating `APP_KEY` requires re-encrypting this column.
- **Mass assignment:** lead status, opt-out and conversion fields, and customer billing details are not fillable; they change only through services.
- **Opt-out:** an opted-out lead is flagged `do_not_contact`, cannot enter outreach states, and its email/domain cannot be re-entered as a new lead because of the unique constraints.
- **Search:** user search terms are bound parameters with LIKE wildcards escaped.
- **Agents:** see [agent policies](agent-policies.md). Agents start disabled; only founders enable them or change tool permissions; tools come from a fixed catalog; step, token, timeout, retry and budget limits are enforced by the runner, not by instructions; output is schema-validated and never treated as proof of an external action; the mock runtime is off outside local/testing.
- **Tool gateway:** agents act only through registered tools checked for prohibition, permission, agent status, pause, per-run call cap and input schema. Level 3 actions are refused outright. Tool input is never run as shell or SQL. Input and result summaries are redacted before storage.
- **Approvals:** only an active founder can decide (checked in `ApprovalService`, not just the policy). The approved payload is hashed; execution refuses missing, pending, rejected, expired, invalidated, already executed or in-progress approvals, and invalidates the approval if the payload about to run differs from the approved one or the stored snapshot was edited. A claim moves the request to `executing` under a row lock before the side effect runs, outside any transaction, so two workers cannot both execute it. A crash mid-execution is flagged `needs_reconciliation`, never silently retried. Agents have no route or tool to approve anything.
- **Audit:** append-only `audit_logs` (model guard + PostgreSQL trigger). Failed logins store a SHA-256 hash of the attempted email, not the email or password. Keys matching `config('company_os.redacted_keys')` are replaced with `[redacted]` at any nesting depth.
- **Tracing:** every response carries an `X-Request-Id` (a valid incoming UUID is reused, anything else replaced). It is stored on audit records.
- **Sessions:** stored in the database and encrypted (`SESSION_ENCRYPT=true`). Set `SESSION_SECURE_COOKIE=true` on HTTPS environments.
- **Files:** the local disk's signed-URL serving route is disabled (`serve => false`).
- **Secrets:** none in source; `.env` is git-ignored and `.env.example` holds keys only.

## Planned (not implemented yet)

- MFA for founder accounts (before the first pilot).
- AI provider adapter (Phase 5).
- Database-level immutability trigger for approval decisions (today: model guard plus the unique constraint).
- SSRF protection for website-reading tools (with the first such tool).
- Security headers / CSP middleware, upload validation, retention policies.
