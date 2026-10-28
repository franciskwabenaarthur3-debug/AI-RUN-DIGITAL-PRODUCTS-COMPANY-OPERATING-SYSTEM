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

## Proposals

Defined in `App\Enums\ProposalStatus` and changed only by `App\Services\ProposalService`, each change under a row lock and recorded as a proposal event and an audit record.

| From | Next |
| --- | --- |
| draft | pending_approval (submit), withdrawn |
| pending_approval | approved or rejected (founder's decision), pending_approval (approval requested again after the earlier request expired), withdrawn |
| approved | sent, pending_approval (approval requested again after it expired, failed or was invalidated), withdrawn |
| sent | accepted, declined, changes_requested (recorded by a person), expired (after `expires_at`), withdrawn |
| accepted, rejected, changes_requested, expired, declined, withdrawn | none for this version |

1. **Draft.** A founder or operator picks a lead or customer and an active service package, or the Sales Agent uses `draft_proposal`. The package line is always the package's price; extra lines, a discount (percentage or fixed, at most `PROPOSALS_MAX_DISCOUNT_PERCENT` of the subtotal, with a reason) and the totals are computed in integer minor units by `ProposalPricing`. The currency is the package's. The recipient defaults to the contact on record.
2. **Edit.** Only the latest version, and only while it is a draft.
3. **Submit.** `ProposalChecks` must pass: active package in the same currency, a contactable lead or active customer, a valid recipient email, non-empty scope and payment terms, a validity date after today, and stored totals that match a fresh calculation. The version is frozen and a `proposal.send` approval request is created whose payload is the exact recipient, content, lines and totals. Its hash is stored as `content_hash`.
4. **Approve.** The founder decides in the approval center. The decision moves the proposal to approved or rejected in the same transaction (`ApprovalDecided` event).
5. **Send.** Only the founder, with a confirmation, and only the latest approved version. The checks run again (an opt-out after approval stops the send without using the approval). `ApprovalService::execute()` recomputes the payload from the stored version and refuses it unless it matches what was approved; it claims the approval so the send runs once. Then either:
   - **Email** (only when `PROPOSALS_EMAIL_ENABLED=true`): the proposal is mailed through `PROPOSALS_MAILER` (or the default mailer). The message id the mailer returns is stored. A `log` or `array` mailer is recorded as `not_delivered_local_mailer`; any other transport as `accepted_by_mail_server`. Delivery to the inbox is not confirmed (no bounce or delivery webhooks yet).
   - **Manual**: the founder records that they sent it themselves and how; nothing is sent by Company OS.
   A failed send is recorded (`delivery_status = failed`, `send_failed` event), the approval is marked failed, and sending again needs a new approval. A crash mid-send leaves the approval in `needs_reconciliation`; check with the recipient before doing anything else.
   Sending logs a `proposal_sent` activity on the lead and moves it to contacted when its status allows.
6. **Response.** A person records accepted, declined or changes requested, with the date and the evidence (for example the client's email). There is no agent tool for this, and repeating the same answer changes nothing. Accepting converts the lead to a customer once, when its status allows conversion, and creates the project (see below). Accepting a change request adds its work and price to its project instead.
7. **New version.** From pending approval, approved, rejected, changes requested or expired. The current version, if it was waiting for approval or approved, is withdrawn and its approval invalidated. The copy is a draft with the package line re-priced at the package's current price and needs its own approval.
8. **Withdraw.** Any version that is not finished. An open approval is invalidated. Withdrawing a sent proposal does not contact the recipient.
9. **Expiry.** `company-os:expire-proposals` (hourly) expires sent proposals past their validity date.

## Projects

A project is created by `ProjectService::createFromProposal()` when a person records that the client accepted a proposal. Recording the same answer again changes nothing, the proposal row is locked while the project is created, and `projects.proposal_id` is unique, so a proposal has at most one project. Proposals accepted before Phase 7 get a **Create project** button on their page, which uses the same method.

The project takes the proposal's title, price (as the budget), currency and customer. Its milestones and tasks come from the package's **delivery template** (Service packages → edit; one `# Milestone` line followed by its tasks), or from `company_os.projects.default_template` when the package has none. The package's estimated days are spread across the milestones as due dates (weekdays). Template tasks have no agent: a person marks them done on the project page. Tasks added later can go to a person or an agent; agent tasks run and are reviewed on the Tasks page as before. A milestone can be completed only when its tasks are completed or cancelled.

Statuses are defined in `App\Enums\ProjectStatus`:

| From | Next |
| --- | --- |
| created | planning, on_hold, cancelled |
| planning | in_progress, blocked, on_hold, cancelled |
| in_progress | internal_review, blocked, on_hold, cancelled |
| internal_review | client_review, in_progress, blocked, on_hold, cancelled |
| client_review | changes_requested, approved_for_release, blocked, on_hold, cancelled |
| changes_requested | in_progress, blocked, on_hold, cancelled |
| approved_for_release | delivered (only by a verified production release), in_progress, blocked, on_hold, cancelled |
| blocked, on_hold | planning, in_progress, cancelled |
| delivered | closed |
| closed, cancelled | none |

Gates: **client review** needs a verified staging release; **approved for release**, changes requested, blocked, on hold and cancelled need a note (for approval: the client's approval and where it is recorded); **delivered** is set only when a production release is verified; **closed** needs every handover checklist item done. Leaving approved for release cancels production releases that are waiting for, or hold, the founder's approval and invalidates those approvals.

**Client feedback** is recorded as within or outside the agreed scope. Outside scope opens a new proposal marked as a change request for the project, addressed to the project's client. It needs the founder's approval and the client's acceptance like any proposal; when accepted it adds a "Change request" milestone and task and adds its price to the budget (when the currency matches). It does not create a second project.

## Releases

Company OS does not deploy anything or read the repository. People deploy with their own tools and record each release here (`ReleaseService`). Every release has a version (unique per project and environment), an optional commit or tag, a URL, release notes and a rollback plan, and gets the checks from `company_os.projects.release_checks`: four mandatory (automated tests, security scan, content review, backup) and three optional (accessibility, performance, cross-browser). A result of passed, failed or skipped needs evidence; mandatory checks cannot be skipped.

| From | Next |
| --- | --- |
| draft | deployed (staging, recorded by a person), pending_approval (production, submit), cancelled |
| pending_approval | approved (founder), draft (founder rejected), pending_approval (asked again after the approval expired or failed), cancelled |
| approved | deployed (founder records it), pending_approval (asked again), cancelled |
| deployed | verified, failed_verification, rolled_back |
| failed_verification | verified (a later health check), rolled_back |
| verified | rolled_back |

**Staging** needs no approval: record the deployment, then run the health check or verify by hand. Checks on a staging release can be recorded while it is in use.

**Production** can be submitted only when every mandatory check passed, the same version is verified on staging, and the project is approved for release. Submitting freezes the release and its check results and creates a high-risk `release.deploy_production` approval request whose payload is the version, environment, URL, commit, notes, rollback plan, staging release and every check result with its evidence. After the founder approves, only the founder can record the deployment, with a confirmation. At that moment every gate is checked again (no approval overrides a failed mandatory check) and `ApprovalService::execute()` recomputes the payload; any difference invalidates the approval, and the claim makes the record happen once.

**Health check:** one HTTP GET of the release URL. A 2xx answer verifies the release; anything else, a redirect, a timeout or a refused address is recorded as failed verification. A verified production release moves the project to delivered. **Verify by hand** is for sites the check cannot reach and is not available after a failed check. **Rollback** records what a person did, following the rollback plan; it does not change the project status.

## Maintenance (workflow D)

A maintenance plan (`MaintenanceService`) belongs to an active customer and optionally one of its projects (the site; its production URL is what the health check reads). Only the founder creates and edits plans, because they carry the price. A plan has a billing frequency and a service interval (monthly, quarterly or yearly), a checklist (the six non-destructive checks in `company_os.maintenance.default_checklist` unless the founder writes one), a next service date and a term end.

| Plan status | Next |
| --- | --- |
| active | paused, cancelled, ended (also set by the scheduler once the next service date is past the term end) |
| paused | active, cancelled |
| ended | active (a recorded renewal) |

1. **Scheduling.** `company-os:schedule-maintenance` finds active plans whose next service date is within `schedule_ahead_days`, creates one visit per plan per run with the plan's checklist, and moves the plan's next service date on by the interval. A visit for a plan and date that already exists is skipped, so repeated runs create nothing new. People and agents can add visits for other dates.
2. **The visit.** A visit is scheduled → in progress → completed, failed or cancelled. While in progress, people record a result for each checklist item (ok, issue with a note, not applicable), run the health check, and record findings with a severity (low, medium, high, critical). High and critical findings raise an alert; a failed health check records a high finding.
3. **Findings.** A finding is closed as resolved or won't fix with a note. A finding marked risky (fixing it changes the live site or costs the client extra) cannot be resolved that way: a person requests approval with the exact change plan and rollback plan, creating a high-risk `maintenance.risky_change` approval request. The founder's decision moves the finding to approved or rejected. After approval a person makes the change with their own tools and records it; that goes through `ApprovalService::execute()`, so it is recorded once and refused if anything in the payload changed. Asking again is allowed once the earlier approval is unusable.
4. **Closing.** Completing needs a result for every checklist item, every finding closed, and evidence of what was done. A completed visit, its checklist, evidence and findings cannot change. Failing raises a critical alert. Cancelling withdraws any open approval.
5. **Report and next service.** The service report (visit page) shows what was checked, found and done, and the next visit date. Renewal reminders appear as alerts and on the dashboard before the term ends; the founder records a renewal with the client's agreement as evidence.

## Alerts

`AlertService::raise()` creates at most one alert per condition (`dedupe_key`). The hourly `company-os:raise-alerts` sweep raises alerts for overdue visits, renewals within `renewal_reminder_days`, releases that failed their health check, approvals needing reconciliation, agent runs that failed today (one per day) and the AI budget level (once per month and level). Maintenance raises alerts for high and critical findings and failed visits as they happen. Operators and the founder acknowledge alerts; acknowledged alerts stay in the history.

## Operating reports

`OperatingReportService::build()` computes a daily, weekly (Monday to Sunday) or monthly report from records: new and qualified leads, proposals awaiting approval, sent, approval rate, accepted and conversion rate, accepted value (agreed, not received), projects active, started and delivered, on-time rate, average delivery time, client change requests, overdue milestones, failed releases, blocked tasks and tasks awaiting review, agent runs and success rate per agent, maintenance visits due, completed, failed and overdue, open findings, renewals due and contracted plan prices, AI cost (by model and per completed agent task, marked when it includes estimates), failed tool calls and approvals, and alerts. Counts marked with * on the page are taken when the report is generated; the rest are within the period. A rate with nothing to divide by is shown as n/a, never 0% or 100%. Paid invoices, expenses other than AI, hosting, support and satisfaction are listed as not recorded. Priorities come from fixed rules over these numbers and are advisory. `company-os:generate-reports` stores reports for finished periods, once each; stored reports cannot change.

## Signing in

Password (rate limited) → if two-step sign-in is on, a pending sign-in held in the session for 5 minutes → a code from the authenticator app or a recovery code → signed in, session regenerated, `auth.login` audited with the method. Five wrong codes or the time limit drop the pending sign-in. A founder without two-step sign-in is sent to Account security until it is set up (when `SECURITY_REQUIRE_FOUNDER_TWO_FACTOR` is on).
