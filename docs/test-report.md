# Test report

**Date:** 2026-10-28 · **Branch:** `phase-5-ai-gateway`
**Environment:** PHP 8.3.6 (CLI), PostgreSQL 16.15, Node 22.22. CI runs PHP 8.2 + PostgreSQL 16.

## Results

| Suite | Result |
| --- | --- |
| `php artisan test` (SQLite in-memory) | 217 passed, 1 skipped (the PostgreSQL-only trigger test) |
| `php artisan test` (PostgreSQL 16) | 218 passed |
| `vendor/bin/pint` | passed |
| `npm run build` | passed |
| Parallel claim on PostgreSQL: four separate PHP processes executed the same approved request at once, each effect sleeping 2 s | passed: one executed, three refused with `in_progress`, one side effect |
| Browser (Playwright) with the database queue: founder queues a mock task that calls `create_lead`, `request_approval` and `execute_shell`; `queue:work --once`; task page lists the three tool calls (the last denied as prohibited); dashboard shows one pending approval; approval page shows the exact payload; approve with confirmation | passed |
| End-to-end with the database queue: log in, enable Sales Agent, create a task, `queue:work --once`, task reaches awaiting review with run history, accept result | passed |

The browser and parallel-claim rows above were run for Phase 4 and not repeated for Phase 5.

Tests use the `sync` queue driver. No test calls a real AI provider: the `llm` runtime is tested with the real Anthropic SDK over a fake HTTP transport (`tests/Feature/Ai/FakeAnthropic.php`) that returns responses in the documented Messages API format and records each request, so request serialization, response parsing and error handling are exercised end to end inside the SDK. That proves the code matches the documented format, not that a live call succeeds.

## Coverage by area

Phases 1–2 coverage is unchanged (authentication, settings, audit, money, CRM). Phase 3 (agents and tasks) is summarised first, then Phase 4.

- **Task state machine:** normal lifecycle; arbitrary jumps refused; terminal states.
- **Output validation:** non-objects, lists, empty output, missing summary, a "completed action" kind, unknown fields, malformed sources and oversized text rejected; research needs sources or assumptions.
- **Execution:** successful run records output, attempt, instructions version, correlation id, input hash and usage (actual cost left empty); review-free agents complete directly; invalid output rejected and not stored; loops stop at the step limit; token cap enforced; timeouts retried exactly `max_retries` times then failed; transient errors recover on retry; non-retryable errors fail at once; results after the deadline treated as timeouts; usage recorded for failed runs.
- **Controls:** disabled agent cannot be queued; an agent disabled after queueing is blocked by the runner; duplicate deliveries do nothing and the database refuses a second run per attempt; emergency pause holds queued work and lifting it resumes; mock runtime unavailable when disabled by config; paid runtimes blocked by company budget (including budget 0), by projected spend, and by agent budget, with an audit record.
- **Dependencies:** dependent stays blocked until its dependency is accepted, then runs automatically.
- **Recovery:** a run left "running" past its deadline is marked `worker_lost` and its task retried; runs within their deadline are left alone; a late result for a task that moved on is discarded as `superseded`; recovery is on the schedule.
- **Agent management:** seeder creates seven disabled agents and does not overwrite founder changes; ungranted and unknown tools denied; enable requires confirmation and is audited; permissions limited to the catalog and audited; operators and viewers get 403 on every agent change.
- **Task UI:** create-and-queue from the form; validation; status/result/attempts cannot be injected; accept, reject (reason required), double accept refused; retry and cancel (confirmation required), retry after cancel refused; viewers get 403 on every task action; list filters; dashboard counts; guests redirected.

Added in Phase 4:

- **Approval acceptance cases:** a Level 2 call creates a pending request with snapshot, canonical hash, risk, rollback plan and expiry, and does not run; execution is refused when the approval is missing or pending (a stray job changes nothing); operators, viewers and inactive founders are refused as approvers, and each refusal is audited; rejection (reason required) blocks execution and closes the tool call; an expired request cannot be approved, and an approval that expires before execution blocks it; a modified payload invalidates the approval and cannot reuse it; changed input creates a new request; a snapshot edited directly in the database is detected before approval and before execution; snapshot and decisions are immutable and the database refuses a second decision; replays and duplicate jobs execute once; a second claim during execution is refused (`in_progress`); a failing side effect is recorded as failed and not retried; an approval does not override a disabled agent or revoked permission; the emergency pause holds approved actions until resume; every step is audited; the sweep expires open requests and flags stale executions for reconciliation; request idempotency.
- **Tool gateway:** prohibited tools refused even with a permission row, and cannot be registered; unknown and unimplemented tools refused; ungranted tools refused; disabled agent and pause refuse calls; invalid input and unknown fields rejected; agent recorded as actor on leads, lead activity and audit; duplicate leads fail cleanly; a repeated call in the same run returns the first execution; per-run call cap; agents limited to their own lead statuses; subtasks created unqueued under the current task; decision-only approval requests; a full agent run calling tools through the runtime; secrets redacted from stored input.
- **Approval center:** founder sees payload, hash and rollback plan; approval needs confirmation and a second approval is refused; rejection needs a reason; operators and viewers can view but get 403 on decisions; dashboard count; status filter validation.

Added in Phase 5:

- **Provider adapter:** request wire format (model, max tokens, system, effort, no thinking override, fallbacks, beta header, tool schemas, web search tool and limit); tool results in snake case; parsing of text, tool calls, web searches, served model and usage including cached input; stop reason mapping; errors normalized (429, 529, 500, 401, 403, 400) with retryability, and the API key never echoed.
- **LLM runtime:** a Research run that searches, creates a lead and submits a sourced result, with costs per call, served model, request id, web search count and model version recorded and actual cost left empty; task text and input wrapped as untrusted data and only granted tools offered; no web search without `search_prospects`; prohibited calls refused and returned to the model as errors; Level 2 calls wait for approval; invalid output fails; a reply without `submit_result` is nudged once then fails; refusal and truncation fail without retry; transient provider errors retried by the task runner; overload normalized and auth errors not retried; token and step limits; budgets block before any call; budget alert audited once per month and shown on the dashboard; unknown serving models priced at the highest rate; the Sales Agent drafts outreach without sending and sees lead context without email or phone; missing instructions or model fail clearly; the runtime exists only with an API key.
- **Configuration:** founder sets runtime, model and limits, audited with old and new values, unchanged saves not audited; `llm` needs a catalog model and an API key; operators get 403; the alert threshold is validated and kept when omitted.

## Known limitations

- The Anthropic integration has not been run against the live API from this environment (no key is configured here). Run one small Research task after setting the key before relying on it.
- Haiku is marked as not supporting web search until confirmed.
- Only five tools are implemented, plus provider web search; the other catalog tools are refused as unimplemented.
- The automated suite tests concurrency through nested and repeated claims and unique constraints; the parallel-process race above was run by hand, not in CI.
- Approval decisions are immutable through the model and the unique constraint, but have no PostgreSQL trigger yet (audit logs do).
- No MFA yet. MySQL untested.
