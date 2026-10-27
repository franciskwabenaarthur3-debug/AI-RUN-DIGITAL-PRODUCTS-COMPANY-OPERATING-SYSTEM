# Test report

**Date:** 2026-10-27 · **Branch:** `phase-1-foundation`
**Environment:** PHP 8.3.6 (CLI), PostgreSQL 16.15, Node 22.22. CI is configured for PHP 8.2 + PostgreSQL 16; the first CI run will be on this PR.

## Results

| Suite | Result |
| --- | --- |
| `php artisan test` (SQLite in-memory) | 51 passed, 1 skipped (the PostgreSQL-only trigger test) |
| `php artisan test` (PostgreSQL 16) | 52 passed, 0 skipped |
| `vendor/bin/pint` | passed |
| `npm run build` | passed |
| Browser smoke test (Playwright): log in, save settings, view dashboard and audit log, mobile settings page | passed |

## Coverage by area

- **Authentication:** guests redirected from every protected route; no `/register`; valid login; invalid credentials rejected and audited without the email or password; inactive users rejected; rate limiting holds even for the correct password; logout invalidates the session; logout is POST only; mid-session deactivation logs out.
- **Authorization:** operators and viewers get 403 on direct settings updates and audit log access; audit link hidden from non-founders; viewers receive no audit data on the dashboard.
- **Settings:** budget stored as minor units (`150.50` → `15050`); invalid currency, timezone, policy, status, negative and 3-decimal budgets rejected; changes audited with before/after values and correlation id; no-op saves not audited; pause banner.
- **Audit:** model refuses update/delete; PostgreSQL trigger blocks raw `UPDATE`; nested secret redaction; correlation id reuse and replacement.
- **Money:** exact conversion, no float drift, invalid inputs rejected, display formatting.
- **Console:** user creation hashes the password and audits; weak passwords, unknown roles and duplicate emails rejected; role/is_active not mass assignable.
- **Seeders:** demo seeder refuses to run in production.

## Known limitations

- No MFA yet.
- AI budget and operating status are stored and displayed but nothing enforces them because no agents exist yet.
- MySQL has not been tested.
