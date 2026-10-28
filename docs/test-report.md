# Test report

**Date:** 2026-10-28 · **Branch:** `phase-6-proposals`
**Environment:** PHP 8.3.6 (CLI), PostgreSQL 16.15, Node 22.22. CI runs PHP 8.2 + PostgreSQL 16.

## Results

| Suite | Result |
| --- | --- |
| `php artisan test` (SQLite in-memory) | 254 passed, 1 skipped (the PostgreSQL-only trigger test) |
| `php artisan test` (PostgreSQL 16) | 255 passed |
| Browser (Playwright, Phase 6): an approved proposal's page, the new-proposal form at desktop width, and the preview at 390 px with no horizontal scroll | checked by screenshot |
| `vendor/bin/pint` | passed |
| `npm run build` | passed |
| Parallel claim on PostgreSQL: four separate PHP processes executed the same approved request at once, each effect sleeping 2 s | passed: one executed, three refused with `in_progress`, one side effect |
| Browser (Playwright) with the database queue: founder queues a mock task that calls `create_lead`, `request_approval` and `execute_shell`; `queue:work --once`; task page lists the three tool calls (the last denied as prohibited); dashboard shows one pending approval; approval page shows the exact payload; approve with confirmation | passed |
| End-to-end with the database queue: log in, enable Sales Agent, create a task, `queue:work --once`, task reaches awaiting review with run history, accept result | passed |

The parallel-claim and the first two browser rows were run for Phase 4 and not repeated since.

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

Added in Phase 6:

- **Pricing:** line totals, subtotal and total in minor units; percentage discounts rounded half up; fixed discounts; the full allowed discount accepted; discounts over the limit, zero, negative, without a reason or of an unknown type rejected; empty, zero-total, zero-quantity, negative-price, blank and too many lines rejected.
- **Workflow:** drafts take the package price, currency, scope and the lead's contact; drafts are refused for opted-out leads, inactive packages or both or neither recipient; draft edits recalculate prices; submitting freezes content and lines and creates an approval of the exact payload; failing checks block submission; founder rejection; manual and email sends; a failed send is recorded and needs a new approval; an expired approval can be requested again; opt-out after approval stops the send without using the approval; acceptance converts the lead once and repeats change nothing; responses only on sent proposals; withdrawal invalidates the approval; sent proposals expire; events are append-only.
- **Mandatory acceptance cases 1 to 3:** a proposal cannot be sent without a valid approval (pending approval, a forced status, an operator and agents all refused); a proposal changed in the database after approval is refused at send time and the approval invalidated, and a new version withdraws the old one, invalidates its approval and needs its own; a repeated send after a successful one is refused and only one email exists.
- **Email:** with the array mailer, exactly one message to the recipient with the reference and total; the message id stored on the proposal and the approval; recorded as not delivered because the mailer is local.
- **Pages:** form create with server-side prices (submitted prices and status ignored); form validation; viewers read-only and 403 on every change; submitted versions cannot be edited; only the founder sends, with confirmation and a note for manual sends; responses need evidence, a past date and confirmation; show, preview, edit, index (latest versions only), approval page link and preview, lead page list, dashboard counts.
- **Agent tools:** `draft_proposal` uses package prices and recorded contacts and records the agent; price, discount, recipient, item and status fields are refused; opted-out leads refused; no sending tool exists and `request_approval` no longer accepts `send_proposal`; `validate_proposal` reports problems.

## Known limitations

- The Anthropic integration has not been run against the live API from this environment (no key is configured here). Run one small Research task after setting the key before relying on it.
- Haiku is marked as not supporting web search until confirmed.
- Proposal email delivery is recorded as the mail service's acceptance only; there are no bounce or delivery webhooks yet. Email sending has not been tried against a real mail service from this environment.
- Mandatory acceptance cases 4 (customer record isolation) and 5 (failed tests block deployment) belong to later phases (customer portal, Phase 7 delivery).
- Only seven tools are implemented, plus provider web search; the other catalog tools are refused as unimplemented.
- The automated suite tests concurrency through nested and repeated claims and unique constraints; the parallel-process race above was run by hand, not in CI.
- Approval decisions are immutable through the model and the unique constraint, but have no PostgreSQL trigger yet (audit logs do).
- No MFA yet. MySQL untested.
