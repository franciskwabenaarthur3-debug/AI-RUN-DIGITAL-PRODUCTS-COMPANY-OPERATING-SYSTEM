# Agent policies

What agents are, what they may do, and how that is enforced. Enforcement is in application code; nothing here depends on an agent following its instructions.

## Agents

Seven logical agents are registered by `AgentSeeder` (run by `php artisan db:seed`). They have separate identities, limits, permissions, runs and usage records. All start **disabled**.

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
- Every call goes through `App\Agents\Tools\ToolGateway`; a runtime receives a `ToolInvoker` bound to its run and has no other way to act.
- The gateway refuses, in order: **prohibited** (Level 3) actions such as `execute_shell`, `run_sql`, `read_secrets`, `grant_permission`, `approve_request`, `transfer_funds`, `issue_refund`, `delete_records`, `deploy_production`, `send_external_message` (refused even if a permission row exists, and audited as `tool.prohibited`); unknown or unimplemented tools; disabled agents; the emergency pause; tools the agent is not granted; calls past `max_tool_calls_per_run`; input that fails the tool's validation rules or has unknown fields.
- A repeated call with the same input in the same run returns the first execution instead of acting again.
- Level 2 tools (`create_project`, `prepare_release`) never run directly: the call creates an approval request and waits. It runs only after an active founder approves the exact payload, and then the gateway re-checks the agent's status and permission first. An approval never overrides those gates.
- Every call, including refusals, is stored in `tool_executions` and audited with the agent as actor.

### Implemented tools

| Tool | What it does | Limits |
| --- | --- | --- |
| `create_lead` | Creates a lead with source "research" | `source_reference` required; duplicates (email or domain) fail |
| `update_lead_status` | Moves a lead through the workflow | Only researched, qualified, outreach drafted, disqualified, lost. Contacted, replied, converted and unsubscribed stay with people |
| `create_project_task` | Creates a subtask of the current task | Created, not queued; a person queues it |
| `draft_outreach` | Stores an outreach draft (channel, subject, body) on a lead's activity history | Refused for leads that are opted out or closed. Nothing is sent: a person sends it, after an approval if the agent asks for one |
| `request_approval` | Asks the founder to decide on an action Company OS cannot perform (send outreach, send proposal, publish release, other) | Approving records the decision only; a person carries out the action |

`search_prospects` has no internal implementation. On the `llm` runtime it controls the provider's web search: search is offered to the model only when the agent is granted `search_prospects` and the model supports it, at most `AI_WEB_SEARCH_MAX_USES` searches per call. Each search is recorded as a `search_prospects` tool execution with the query and `executed_by: anthropic`.

The other catalog tools can be granted but are refused as unimplemented until they are built.

## Limits enforced on every run

| Limit | Where set | Enforcement |
| --- | --- | --- |
| Max steps per run | `agents.max_steps` | Runtime must stop at the limit; the runner also rejects results reporting more steps (`step_limit_exceeded`) |
| Max tokens per run | `agents.max_tokens_per_run` | Runner rejects results above the limit (`usage_limit_exceeded`) |
| Timeout | `agents.timeout_seconds` | Deadline passed to the runtime; late results are rejected (`timeout`); runs left "running" past the deadline are recovered as `worker_lost` |
| Automatic retries | `agents.max_retries` | Only retryable errors (timeouts, transient provider errors, lost workers) are retried, at most this many times, with exponential backoff |
| Agent monthly budget | `agents.monthly_budget_minor` | For paid runtimes: blocked before the run if spent + maximum run cost would exceed it |
| Company monthly AI budget | Settings → Monthly AI budget (USD) | Same check, company-wide. A budget of 0 means no paid runs. When spend crosses the alert percentage (default 80%) or the budget, the dashboard says so and the audit log records it once per month |
| Emergency pause | Settings → Operating status | Queued tasks are not started while paused; lifting the pause re-dispatches them |
| Max tool calls per run | `agents.max_tool_calls_per_run` | Gateway refuses further calls in the run (`tool_call_limit`) |
| Disabled agent | Agent status | Cannot be queued; a task queued before the agent was disabled is blocked when it reaches the runner |

## Output

Every run's output is validated against `App\Agents\OutputSchema` before it is stored:

- Must be an object with `kind` and `summary`; unknown fields are rejected.
- `kind` is one of `draft`, `analysis`, `recommendation`, `report`. There is no "completed action" kind: output never counts as proof that anything happened outside the system.
- Research output must include sources (reference + retrieval date) or explicitly listed unverified assumptions.
- Invalid output fails the run (`invalid_output`) and is not saved as the task result.

By default agent results go to **awaiting review**, and a person accepts or rejects them.

## Runtimes

Only a founder chooses an agent's runtime, model and limits (Agents → agent → Runtime, model and limits). Every change is audited with old and new values.

- `mock` is deterministic, performs no real work and labels its output as mock. Its `use_tool` scenario makes the tool calls listed in the task input (`mock_tool_calls`) through the gateway, so the whole path can be exercised without a model. It is available only when `AGENTS_ALLOW_MOCK_RUNTIME` is true (default: local and testing only). Elsewhere, tasks for mock agents are blocked with an explanation.
- `llm` calls a model through the AI gateway (`App\Ai`). It is registered only when `ANTHROPIC_API_KEY` is set; otherwise tasks for `llm` agents are blocked as "runtime not available".

### The `llm` runtime

- **Models** come from the catalog in `config/company_os.php` (`ai.models`): provider, model id, configuration version, prices, output cap, effort and web search support. Agents store a catalog key, so a model can be replaced in config without touching workflows. The model key and configuration version are stored on each successful run (`agent_runs.usage_metadata`).
- **Instructions** are a fixed policy (`App\Agents\AgentInstructions::POLICY`) plus the role file `resources/agents/{role}/{instructions_version}.md`. Only Research and Sales have instruction files; other agents on `llm` fail with `instructions_missing`.
- **Loop:** each step is one model call. The model may call only the tools the agent is granted (refused calls come back to it as errors, and are still recorded) and must finish by calling `submit_result`, whose input is validated against the output schema. A reply without it is nudged once, then fails. Steps, tokens, tool calls and the deadline are checked in code before every call.
- **Untrusted content:** the task description and input are wrapped in tags and the policy tells the model they are data, not instructions. Tool results and search results are treated the same way. None of this is relied on for safety: permissions, limits and approvals are enforced by the gateway and runner whatever the model says.
- **Lead context:** for `lead_ids` in the task input (up to 20), the model sees company, contact name, website, status, opt-out flag, score and notes. Email addresses and phone numbers are never sent to the provider.
- **Nothing leaves the system:** there is no sending tool. The Sales Agent drafts with `draft_outreach` and may ask for approval to send; a person sends.
- **Cost:** estimated from the token counts and web searches the provider reports, at the catalog price of the model that actually served the response (an unknown serving model is priced at the highest catalog rate). The actual cost column stays empty until provider billing is imported. Before a run, the budget check uses the most a run could cost (its output token cap at the output rate, plus its search allowance).
