# Operations runbook

Covers what exists today. Deployment and backup procedures will be added with those phases.

## Processes that must run

| Process | Command | Purpose |
| --- | --- | --- |
| Web | `php artisan serve` locally; PHP-FPM behind a web server in production | The app |
| Queue worker | `php artisan queue:work --tries=1 --timeout=900` | Runs agent tasks (`RunAgentTask`) and approved tool calls (`ExecuteApprovedAction`). Use a process manager (e.g. Supervisor) to restart it |
| Scheduler | cron: `* * * * * php /path/to/artisan schedule:run` (locally `php artisan schedule:work`) | Runs `company-os:recover-stale-runs` and `company-os:expire-approvals` every five minutes, `company-os:expire-proposals` and `company-os:raise-alerts` hourly, `company-os:generate-reports` daily at 00:30 and `company-os:schedule-maintenance` daily at 01:00 |

`--tries=1` matters: the task runner decides retries itself, so the queue must not retry jobs on its own.

## Routine checks

- **Dashboard → Tasks by status / failed runs:** a growing failed count means an agent or runtime problem. Open the task to see each run's error code and message.
- **Agents page:** runs and failed runs per agent.
- **Approvals:** the dashboard shows how many requests wait for a decision and how many need reconciling.
- **Task page → Tool calls:** every call an agent made in its runs, with status, error and approval link.
- **Audit log:** every enable/disable, permission change, task action and budget block.

## Error codes on agent runs

| Code | Meaning | Retried automatically |
| --- | --- | --- |
| `timeout` | Runtime exceeded or reported past its deadline | Yes |
| `provider_rate_limited`, `provider_overloaded`, `provider_connection`, `provider_provider_error` | Transient provider problem (429, 529, network, 5xx). The SDK already retried `AI_MAX_RETRIES` times | Yes |
| `provider_auth` | The provider rejected the API key or its permissions. Check `ANTHROPIC_API_KEY` | No |
| `provider_rejected` | The provider refused the request (400, 404, 422); the message has a short reason | No |
| `provider_error` | Mock runtime error | If the runtime marks it transient |
| `refused` | The model declined the task | No |
| `output_truncated` | The model hit its output token cap before finishing | No |
| `model_not_configured`, `provider_unavailable`, `instructions_missing` | The agent has no valid model, the provider has no key, or its role has no instruction file | No |
| `worker_lost` | The worker died mid-run (found by the recovery command) | Yes |
| `invalid_output` | Output failed schema validation | No |
| `step_limit_exceeded` | Agent hit its step limit | No |
| `usage_limit_exceeded` | Agent used more tokens than allowed | No |
| `runtime_exception` | Unexpected exception in the runtime (also sent to the error log) | No |
| `superseded` | A late result arrived after the task had moved on; discarded | No |

Blocked tasks carry the reason in their failure message (disabled agent, unavailable runtime, budget, waiting for dependencies).

## Tool call outcomes

| Code | Meaning |
| --- | --- |
| `prohibited` | A Level 3 action was attempted. Investigate the agent's instructions |
| `unknown_tool` | Not in the catalog, or not implemented yet |
| `agent_disabled`, `paused`, `not_permitted` | A control refused the call |
| `tool_call_limit` | The run used `max_tool_calls_per_run` calls |
| `invalid_input` | Validation failed or unknown fields were sent |
| `tool_error` | The tool ran and failed; the message says why (unexpected errors go to the error log) |
| `approval_rejected`, `approval_expired`, `payload_mismatch` | The Level 2 call's approval did not allow execution |

## Proposals

- **Turn on email sending:** set `MAIL_MAILER` and its settings to a real mail service, set `PROPOSALS_EMAIL_ENABLED=true` (optionally `PROPOSALS_MAILER` to use a different mailer), and restart PHP and the workers. Send one proposal to yourself first. With `MAIL_MAILER=log` the proposal page will say `Not delivered local mailer`.
- **A send failed:** the proposal page shows `Failed` under Delivery and the error in History. Nothing counted as sent. Fix the mail settings, then use "Request approval again", approve, and send.
- **A send needs reconciliation:** the worker or request died after sending started. Check the mail service or ask the recipient whether it arrived. If it did, request approval again and record a manual send that says so; if not, request approval again and send.
- **Discount limit:** `PROPOSALS_MAX_DISCOUNT_PERCENT` (default 20).

## Projects and releases

- **A production release cannot be submitted:** the release page lists why: a mandatory check not passed, the version not verified on staging, or the project not yet approved for release. Mandatory checks cannot be skipped; fix the cause and record the check again with new evidence.
- **Health check says private or reserved address:** the URL resolves to an internal address and was not contacted. Use the public URL, or verify by hand if the site is only reachable internally. On a local machine you can set `RELEASE_HEALTH_CHECK_ALLOW_PRIVATE=true`; never in production.
- **Health check redirected:** the check does not follow redirects. Use the final URL (often `https://` or `www.`) in a new release.
- **A production release went wrong:** follow its rollback plan with your own tools, then record the rollback on the release page. Fix, create a new version, and go through staging and approval again.
- **Record a deployment that needs reconciliation:** the approval shows needs reconciliation if the request died while recording. Check the site; if it was deployed, request approval again and record it with a note saying so.

## Maintenance

- **Visits:** the scheduler creates each plan's next visit `MAINTENANCE_SCHEDULE_AHEAD_DAYS` (default 7) before its service date and moves the plan on by its interval. Running it again, or twice at once, creates nothing new (row lock plus a unique plan and date). A plan whose term has ended is marked ended instead. Extra visits can be scheduled from the plan page or by an agent with `schedule_maintenance_task`.
- **Doing a visit:** start it, record a result for every checklist item (an issue needs a note), run the health check if the site has a production URL, and record findings. Checks must not change the site. Anything that changes the live site or costs the client extra is a risky finding: request approval with the exact change and rollback plan, wait for the founder, make the change with your own tools, then record it. Complete the visit with evidence; it cannot change afterwards. The service report is on the visit page.
- **An approved change was refused when recording it:** the change plan, rollback plan or finding changed after approval, or the approval expired. Request approval again.
- **The visit cannot be done:** mark it failed with the reason; this raises a critical alert. Schedule a new visit when possible.
- **Renewals:** active plans whose term ends within `MAINTENANCE_RENEWAL_REMINDER_DAYS` (default 30) raise a renewal alert and are counted on the dashboard. Agree the renewal with the client, then the founder records it with the evidence. Invoicing is outside Company OS.

## Alerts and reports

- **Alerts** are raised by the hourly sweep and by maintenance actions, one per condition, and shown on the Alerts page and in the menu badge. Acknowledging hides an alert; the same condition does not raise it again. Nothing is emailed, so check the page daily.
- **Reports** are computed from Company OS records only. The Reports page shows the current period live; the scheduled job stores yesterday's report every day, last week's on Mondays and last month's on the 1st, once. Paid invoices, expenses other than AI usage, hosting costs and support issues are not recorded, so they are listed as not reported rather than estimated. Recommended priorities come from fixed rules and are advisory.

## Approvals needing reconciliation

A request in **needs reconciliation** started executing but never recorded an outcome (worker crash), and stayed that way for `APPROVALS_RECONCILE_AFTER_MINUTES`. It is never retried automatically. Check whether the action actually happened (the task page shows the tool call), then fix the records by hand. To try again, the agent must make a new call, which creates a new request.

## Incidents

- **Stop all agent work now:** Settings → Operating status → Paused. New runs will not start, tool calls are refused and approved actions are held; runs already executing finish. Set it back to Active to resume queued tasks and approved actions.
- **Stop one agent:** Agents → agent → Disable.
- **Stuck "running" task:** wait for the recovery command (deadline + `AGENTS_STALE_RUN_GRACE_SECONDS`), or run `php artisan company-os:recover-stale-runs` manually.
- **Budget alert:** the dashboard shows when AI spend passes the alert percentage set in Settings, and when the budget is used up. Each is audited once per month (`ai.budget_threshold_reached`, `ai.budget_exhausted`).
- **Provider key:** set `ANTHROPIC_API_KEY` in the environment and restart the queue workers. To stop all model calls without pausing everything, remove the key and restart the workers: `llm` tasks are then blocked.
- **Budget exhausted:** tasks are blocked with a budget message. Raise the budget in Settings (company) or on the agent, then use Retry on the task.
