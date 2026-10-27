# Operations runbook

Covers what exists today. Deployment, backup and alerting procedures will be added with those phases.

## Processes that must run

| Process | Command | Purpose |
| --- | --- | --- |
| Web | `php artisan serve` locally; PHP-FPM behind a web server in production | The app |
| Queue worker | `php artisan queue:work --tries=1 --timeout=900` | Runs agent tasks (`RunAgentTask` jobs). Use a process manager (e.g. Supervisor) to restart it |
| Scheduler | cron: `* * * * * php /path/to/artisan schedule:run` (locally `php artisan schedule:work`) | Runs `company-os:recover-stale-runs` every five minutes |

`--tries=1` matters: the task runner decides retries itself, so the queue must not retry jobs on its own.

## Routine checks

- **Dashboard → Tasks by status / failed runs:** a growing failed count means an agent or runtime problem. Open the task to see each run's error code and message.
- **Agents page:** runs and failed runs per agent.
- **Audit log:** every enable/disable, permission change, task action and budget block.

## Error codes on agent runs

| Code | Meaning | Retried automatically |
| --- | --- | --- |
| `timeout` | Runtime exceeded or reported past its deadline | Yes |
| `provider_error` | Runtime/provider reported an error | If the runtime marks it transient |
| `worker_lost` | The worker died mid-run (found by the recovery command) | Yes |
| `invalid_output` | Output failed schema validation | No |
| `step_limit_exceeded` | Agent hit its step limit | No |
| `usage_limit_exceeded` | Agent used more tokens than allowed | No |
| `runtime_exception` | Unexpected exception in the runtime (also sent to the error log) | No |
| `superseded` | A late result arrived after the task had moved on; discarded | No |

Blocked tasks carry the reason in their failure message (disabled agent, unavailable runtime, budget, waiting for dependencies).

## Incidents

- **Stop all agent work now:** Settings → Operating status → Paused. New runs will not start; runs already executing finish. Set it back to Active to resume queued tasks.
- **Stop one agent:** Agents → agent → Disable.
- **Stuck "running" task:** wait for the recovery command (deadline + `AGENTS_STALE_RUN_GRACE_SECONDS`), or run `php artisan company-os:recover-stale-runs` manually.
- **Budget exhausted:** tasks are blocked with a budget message. Raise the budget in Settings (company) or on the agent, then use Retry on the task.
