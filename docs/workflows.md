# Workflows

Only implemented workflows are described here.

## Lead lifecycle

Defined in `App\Enums\LeadStatus` and enforced by `App\Services\LeadService`. Any transition not listed is refused, both in the UI (only valid targets are offered) and on direct requests.

| From | Allowed next states |
| --- | --- |
| new | researched, qualified, disqualified, unsubscribed\*, lost |
| researched | qualified, disqualified, unsubscribed\*, lost |
| qualified | outreach_drafted, converted\*\*, disqualified, unsubscribed\*, lost |
| outreach_drafted | contacted, qualified, unsubscribed\*, lost |
| contacted | replied, converted\*\*, unsubscribed\*, lost |
| replied | converted\*\*, unsubscribed\*, lost |
| disqualified | new (re-open) |
| lost | qualified (re-open) |
| converted | none (terminal) |
| unsubscribed | none (terminal) |

\* Only through the **opt-out** action, which also sets `do_not_contact`, records the reason and clears the follow-up.
\*\* Only through **conversion**, which creates the customer.

Rules:

- A lead with `do_not_contact` set can never move into `outreach_drafted` or `contacted`, regardless of its status.
- Every change writes a `lead_activities` row; status changes, opt-outs and conversions also write `audit_logs`.
- Status changes, opt-out and conversion lock the lead row (`SELECT … FOR UPDATE`) so concurrent requests are serialized.

## Duplicate detection

On create and edit, a lead is rejected if another lead has the same email (case-insensitive) or the same normalized website domain (`https://www.Example.com/x` and `example.com` match). The error names the existing lead. Unique indexes on `email` and `website_domain` back this up at the database level.

Not yet checked: duplicates between leads and existing customers, and fuzzy company-name matches.

## Lead → customer conversion

1. Allowed from `qualified`, `contacted` or `replied`, and requires an explicit confirmation checkbox.
2. Inside a transaction the lead is locked. If it already has a `customer_id`, that customer is returned and nothing is created (safe against double clicks and retries).
3. Otherwise a customer is created from the lead's contact details, the lead is set to `converted` with `customer_id` and `converted_at`, and activity and audit records are written.
4. `leads.customer_id` is unique, so one customer can never be linked to two leads.

## Follow-ups

`next_follow_up_at` can be set on the lead form or from the lead page. The dashboard and the "Follow-up due" filter count leads whose follow-up time has passed, excluding converted, unsubscribed, lost and disqualified leads. No reminders are sent yet.

## Task lifecycle

Defined in `App\Enums\TaskStatus`; changed only by `TaskService` (people) and `TaskRunner` (execution), each under a row lock.

| From | Allowed next states |
| --- | --- |
| created | queued, blocked, cancelled |
| queued | running, blocked, cancelled |
| running | completed, awaiting_review, awaiting_approval, failed, blocked, queued (automatic retry) |
| awaiting_review | completed (accept), failed (reject with reason), cancelled |
| awaiting_approval | completed, failed, cancelled (reserved; approvals currently wait on their own, without holding the task) |
| blocked | queued, cancelled |
| failed | queued (manual retry), cancelled |
| completed, cancelled | none |

A running task cannot be cancelled; cancel it after the run ends, or disable the agent.

### Queueing

- Queueing requires an assigned, enabled agent.
- If any dependency is not completed, the task becomes `blocked` ("Waiting for dependencies"). When a task completes, its blocked dependents whose dependencies are all complete are queued automatically.
- Queueing dispatches a `RunAgentTask` job after the database transaction commits.

### Execution (`TaskRunner`)

1. **Claim** (one transaction, task row locked): skip if the task is not `queued` (duplicate delivery) or the company is paused. Block if the agent is missing or disabled, a dependency is open, the runtime is unavailable, or (paid runtimes) the company or agent budget would be exceeded. Otherwise create an `agent_runs` row for attempt *n* and set the task to `running`.
2. **Run** the runtime outside any transaction, with step, token and deadline limits.
3. **Finish** (one transaction, task and run locked): record usage (always, even on failure); if this run no longer owns the task, mark it `superseded` and change nothing else. Reject late, over-step, over-token or schema-invalid results. On success store the output and move to `awaiting_review` (or `completed` if the agent does not require review). On a retryable error with retries left, go back to `queued` and dispatch a new job with exponential backoff; otherwise `failed`.

### Recovery

`company-os:recover-stale-runs` (every five minutes) marks runs still `running` more than `AGENTS_STALE_RUN_GRACE_SECONDS` past their deadline as `worker_lost`, then retries or fails their tasks within the agent's retry limit.

### Manual actions

Retry (failed/blocked → queued, resets automatic retries), cancel (with confirmation), accept or reject a reviewed result. All are audited and limited to founders and operators.

## Tool calls

1. A runtime calls a tool through the `ToolInvoker` it was given for the run.
2. `ToolGateway` checks, in order: prohibited action, known and implemented tool, agent enabled, company not paused, permission granted, input valid with no unknown fields. Any refusal is stored as a `denied` tool execution with a code and audited.
3. The idempotency key is SHA-256 of run id, tool and canonical input. If an execution with that key exists, it is returned and nothing runs again.
4. Under a lock on the run row, the per-run call cap is checked and the execution row is inserted.
5. **Level 1:** the tool runs (outside the gateway's transaction) and the execution becomes `succeeded` or `failed`.
6. **Level 2:** in the same transaction, an approval request is created with the exact input as its payload; the execution waits in `awaiting_approval`. The run continues; its output reports the pending call.

## Approvals

| From | Next |
| --- | --- |
| pending | approved, rejected (founder, reason required), expired (past `expires_at`), invalidated (snapshot no longer matches its hash) |
| approved | executing (claimed), expired, invalidated (payload mismatch), failed (agent disabled or permission revoked before execution) |
| executing | executed, failed, needs_reconciliation (no outcome after `APPROVALS_RECONCILE_AFTER_MINUTES`) |
| rejected, expired, invalidated, executed, failed, needs_reconciliation | none: a new attempt needs a new request |

- **Request:** `ApprovalService::request()` stores the payload snapshot, its hash, reason, risk, rollback plan, evidence and expiry. The same idempotency key returns the same request; reusing a key with a different payload is an error.
- **Decide:** approval center (`/approvals`). Only an active founder sees the forms, and `ApprovalService::decide()` refuses anyone else (`wrong_approver`, audited). Approving needs an explicit confirmation; rejecting needs a reason. The decision row is immutable and unique per request. Rejection or expiry closes the waiting tool execution as `denied`.
- **Execute:** after an approval commits, an `ExecuteApprovedAction` job runs. The gateway re-checks the pause (holds the action until resume), agent status and permission (abandons the approval). `ApprovalService::execute()` then locks the request and refuses anything but a valid, unexpired approval whose decision and stored hash both match the payload about to run; on a mismatch it invalidates the approval. It sets `executing`, commits, runs the side effect outside the transaction, and records `executed` or `failed`. Duplicate jobs find the request already executing or executed and do nothing.
- **Sweep:** `company-os:expire-approvals` (every five minutes) expires open requests past their deadline and flags stale `executing` requests as `needs_reconciliation`.
- **Decision-only requests** (`agent.*` action types, from the `request_approval` tool) have no executor. Approving them records the decision; a person performs the action.
