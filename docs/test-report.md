# Test report

**Date:** 2026-10-27 · **Branch:** `phase-2-crm`
**Environment:** PHP 8.3.6 (CLI), PostgreSQL 16.15, Node 22.22. CI runs PHP 8.2 + PostgreSQL 16.

## Results

| Suite | Result |
| --- | --- |
| `php artisan test` (SQLite in-memory) | 100 passed, 1 skipped (the PostgreSQL-only trigger test) |
| `php artisan test` (PostgreSQL 16) | 101 passed |
| `vendor/bin/pint` | passed |
| `npm run build` | passed |
| Playwright smoke test: create package, create lead, duplicate website blocked, change status, add note, convert to customer, dashboard, mobile lead list | passed |

Running on PostgreSQL caught one bug SQLite did not: a backslash LIKE escape that PDO's pgsql driver misparses. Search now uses `!` as the escape character.

## Coverage by area

Phase 1 coverage (authentication, settings, audit, money, console user creation) is unchanged. Added in Phase 2:

- **Leads:** create with normalized email/domain; invalid input rejected (including `javascript:` URLs); duplicate email (case-insensitive) and duplicate domain (after normalization) rejected with the existing lead named; database unique constraints as backstop; edit does not flag itself; status/opt-out/customer fields cannot be mass-assigned via the edit form; search, filters, pagination; wildcard search terms matched literally; invalid filters rejected; follow-up due filter and dashboard count; follow-up schedule and clear; notes escaped on output.
- **Lead workflow:** valid transition recorded in activity and audit; skipped steps refused; `converted`/`unsubscribed` not settable directly; opt-out requires confirmation and sets do-not-contact; unsubscribed leads cannot enter outreach; do-not-contact blocks outreach even if status would allow it; opted-out contact cannot be re-added; conversion creates exactly one customer across repeated HTTP posts, service calls and stale model instances; conversion requires confirmation and an eligible status; unsubscribed leads cannot be converted; activities immutable.
- **Customers:** billing encrypted at rest; founder sees billing, operator neither sees nor can overwrite it; billing never in audit logs or serialization; viewer read-only; validation; search; 404 for missing records.
- **Service packages:** founder creates with minor-unit price and cleaned scope lists; price changes audited with old and new values; deactivation; operators and viewers blocked on direct requests; validation (duplicate slug, empty scope, negative or 3-decimal price, unknown currency, bounds).
- **Access:** guests redirected from all CRM routes; viewers get 403 on every CRM write endpoint.

## Known limitations

- No MFA yet.
- No duplicate check between leads and existing customers.
- Follow-ups are tracked but no reminders are sent.
- Concurrency of conversion is tested sequentially (repeated and stale calls); true parallel requests rely on the row lock and the unique `customer_id` constraint.
- MySQL has not been tested.
