# Test report

**Date:** 2026-10-27 · **Branch:** `phase-3-agents`
**Environment:** PHP 8.3.6 (CLI), PostgreSQL 16.15, Node 22.22. CI runs PHP 8.2 + PostgreSQL 16.

## Results

| Suite | Result |
| --- | --- |
| `php artisan test` (SQLite in-memory) | 147 passed, 1 skipped (the PostgreSQL-only trigger test) |
| `php artisan test` (PostgreSQL 16) | 148 passed |
| `vendor/bin/pint` | passed |
| `npm run build` | passed |
| End-to-end with the database queue: log in, enable Sales Agent, create a task, `queue:work --once`, task reaches awaiting review with run history, accept result | passed |

Tests use the `sync` queue driver and the deterministic mock runtime; no test calls a real AI provider.

## Coverage by area

Phases 1–2 coverage is unchanged (authentication, settings, audit, money, CRM). Added in Phase 3:

- **Task state machine:** normal lifecycle; arbitrary jumps refused; terminal states.
- **Output validation:** non-objects, lists, empty output, missing summary, a "completed action" kind, unknown fields, malformed sources and oversized text rejected; research needs sources or assumptions.
- **Execution:** successful run records output, attempt, instructions version, correlation id, input hash and usage (actual cost left empty); review-free agents complete directly; invalid output rejected and not stored; loops stop at the step limit; token cap enforced; timeouts retried exactly `max_retries` times then failed; transient errors recover on retry; non-retryable errors fail at once; results after the deadline treated as timeouts; usage recorded for failed runs.
- **Controls:** disabled agent cannot be queued; an agent disabled after queueing is blocked by the runner; duplicate deliveries do nothing and the database refuses a second run per attempt; emergency pause holds queued work and lifting it resumes; mock runtime unavailable when disabled by config; paid runtimes blocked by company budget (including budget 0), by projected spend, and by agent budget, with an audit record.
- **Dependencies:** dependent stays blocked until its dependency is accepted, then runs automatically.
- **Recovery:** a run left "running" past its deadline is marked `worker_lost` and its task retried; runs within their deadline are left alone; a late result for a task that moved on is discarded as `superseded`; recovery is on the schedule.
- **Agent management:** seeder creates seven disabled agents and does not overwrite founder changes; ungranted and unknown tools denied; enable requires confirmation and is audited; permissions limited to the catalog and audited; operators and viewers get 403 on every agent change.
- **Task UI:** create-and-queue from the form; validation; status/result/attempts cannot be injected; accept, reject (reason required), double accept refused; retry and cancel (confirmation required), retry after cancel refused; viewers get 403 on every task action; list filters; dashboard counts; guests redirected.

## Known limitations

- No real AI provider yet; only the mock runtime exists.
- Tools are permissioned but not executable until the tool gateway (Phase 4).
- Concurrency is tested through sequential duplicate deliveries and the unique `(task_id, attempt)` constraint, not with parallel workers.
- No MFA yet. MySQL untested.
