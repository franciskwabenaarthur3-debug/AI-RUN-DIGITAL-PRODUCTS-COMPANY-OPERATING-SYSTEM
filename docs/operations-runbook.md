# Operations runbook

Covers what exists today. Deployment, backup and alerting procedures will be added with those phases.

## Processes that must run

| Process | Command | Purpose |
| --- | --- | --- |
| Web | `php artisan serve` locally; PHP-FPM behind a web server in production | The app |
| Queue worker | `php artisan queue:work --tries=1 --timeout=900` | Runs agent tasks (`RunAgentTask`) and approved tool calls (`ExecuteApprovedAction`). Use a process manager (e.g. Supervisor) to restart it |
| Scheduler | cron: `* * * * * php /path/to/artisan schedule:run` (locally `php artisan schedule:work`) | Runs `company-os:recover-stale-runs` and `company-os:expire-approvals` every five minutes |

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

## Approvals needing reconciliation

A request in **needs reconciliation** started executing but never recorded an outcome (worker crash), and stayed that way for `APPROVALS_RECONCILE_AFTER_MINUTES`. It is never retried automatically. Check whether the action actually happened (the task page shows the tool call), then fix the records by hand. To try again, the agent must make a new call, which creates a new request.

## Incidents

- **Stop all agent work now:** Settings → Operating status → Paused. New runs will not start, tool calls are refused and approved actions are held; runs already executing finish. Set it back to Active to resume queued tasks and approved actions.
- **Stop one agent:** Agents → agent → Disable.
- **Stuck "running" task:** wait for the recovery command (deadline + `AGENTS_STALE_RUN_GRACE_SECONDS`), or run `php artisan company-os:recover-stale-runs` manually.
- **Budget alert:** the dashboard shows when AI spend passes the alert percentage set in Settings, and when the budget is used up. Each is audited once per month (`ai.budget_threshold_reached`, `ai.budget_exhausted`).
- **Provider key:** set `ANTHROPIC_API_KEY` in the environment and restart the queue workers. To stop all model calls without pausing everything, remove the key and restart the workers: `llm` tasks are then blocked.
- **Budget exhausted:** tasks are blocked with a budget message. Raise the budget in Settings (company) or on the agent, then use Retry on the task.
