# Agent policies

What agents are, what they may do, and how that is enforced. Enforcement is in application code; nothing here depends on an agent following its instructions.

## Agents

Seven logical agents are registered by `AgentSeeder` (run by `php artisan db:seed`). They share one runtime today but have separate identities, limits, permissions, runs and usage records. All start **disabled**.

| Agent | Role | Default tools |
| --- | --- | --- |
| AI CEO | orchestrator | create_project_task, request_approval, generate_operating_report |
| Research Agent | research | search_prospects, analyze_website, create_lead |
| Sales Agent | sales | update_lead_status, draft_outreach, draft_proposal, validate_proposal, request_approval |
| Product & Engineering Agent | engineering | generate_project_plan, create_project_task, run_quality_checks, request_approval |
| Marketing Agent | marketing | request_approval |
| Customer Success Agent | customer_success | draft_customer_response, schedule_maintenance_task, request_approval |
| Finance & Operations Agent | finance | generate_operating_report |

Re-running the seeder never resets a founder's changes to status or permissions.

## Who controls agents

- Only an active **founder** can enable or disable an agent (with explicit confirmation) or change its tool permissions. Operators and viewers can see agents but get 403 on every change.
- Every status and permission change is written to the audit log.
- There is no tool, route or code path through which an agent can change its own status, permissions or limits.

## Tools

- The set of tools is fixed in `App\Agents\ToolCatalog`. Agents cannot define new tools; granting a tool name that is not in the catalog is rejected.
- `Agent::mayUseTool()` returns true only for an explicit, allowed grant. Unknown or ungranted tools are denied.
- Tools marked L2 (`create_project`, `prepare_release`) will additionally need founder approval for each action.
- **Not yet built:** the tool gateway that executes tools (Phase 4). Until then agents produce output only; no tool performs any action.

## Limits enforced on every run

| Limit | Where set | Enforcement |
| --- | --- | --- |
| Max steps per run | `agents.max_steps` | Runtime must stop at the limit; the runner also rejects results reporting more steps (`step_limit_exceeded`) |
| Max tokens per run | `agents.max_tokens_per_run` | Runner rejects results above the limit (`usage_limit_exceeded`) |
| Timeout | `agents.timeout_seconds` | Deadline passed to the runtime; late results are rejected (`timeout`); runs left "running" past the deadline are recovered as `worker_lost` |
| Automatic retries | `agents.max_retries` | Only retryable errors (timeouts, transient provider errors, lost workers) are retried, at most this many times, with exponential backoff |
| Agent monthly budget | `agents.monthly_budget_minor` | For paid runtimes: blocked before the run if spent + maximum run cost would exceed it |
| Company monthly AI budget | Settings → Monthly AI budget | Same check, company-wide. A budget of 0 means no paid runs |
| Emergency pause | Settings → Operating status | Queued tasks are not started while paused; lifting the pause re-dispatches them |
| Disabled agent | Agent status | Cannot be queued; a task queued before the agent was disabled is blocked when it reaches the runner |

## Output

Every run's output is validated against `App\Agents\OutputSchema` before it is stored:

- Must be an object with `kind` and `summary`; unknown fields are rejected.
- `kind` is one of `draft`, `analysis`, `recommendation`, `report`. There is no "completed action" kind: output never counts as proof that anything happened outside the system.
- Research output must include sources (reference + retrieval date) or explicitly listed unverified assumptions.
- Invalid output fails the run (`invalid_output`) and is not saved as the task result.

By default agent results go to **awaiting review**, and a person accepts or rejects them.

## Runtimes

- `mock` is the only runtime today. It is deterministic, performs no real work and labels its output as mock. It is available only when `AGENTS_ALLOW_MOCK_RUNTIME` is true (default: local and testing only). Elsewhere, tasks for mock agents are blocked with an explanation.
- Real model providers will be added as runtimes behind the AI gateway (Phase 5).
