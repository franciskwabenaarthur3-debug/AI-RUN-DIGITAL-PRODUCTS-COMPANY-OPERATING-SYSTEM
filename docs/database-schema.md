# Database schema

Only tables that exist are documented. Laravel's framework tables (`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`) are omitted.

## users

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint PK | |
| name | string | |
| email | string, unique | login identifier |
| email_verified_at | timestamp, null | |
| password | string | bcrypt hash via `hashed` cast |
| role | string(32), indexed, default `viewer` | `founder`, `operator`, `viewer`; not mass assignable |
| is_active | boolean, default true | inactive users cannot log in and are logged out mid-session; not mass assignable |
| last_login_at | timestamp, null | |
| remember_token, timestamps | | |

## company_settings

Single row, read through `CompanySetting::current()`.

| Column | Type | Notes |
| --- | --- | --- |
| company_name | string | |
| default_currency | char(3) | from `config('company_os.currencies')` |
| timezone | string(64) | IANA identifier |
| approval_policy | string(32) | `strict` only for now |
| monthly_ai_budget_minor | bigint | US cents (`config('company_os.ai.currency')`) |
| ai_budget_alert_percent | tinyint, default 80 | dashboard warning and audit at this share of the budget |
| operating_status | string(16) | `active` or `paused` (emergency pause) |

## service_packages

Managed by founders only. Packages are deactivated rather than deleted so future proposals keep a valid reference.

| Column | Type | Notes |
| --- | --- | --- |
| name | string | |
| slug | string, unique | |
| description | text, null | |
| scope, exclusions | json | lists of strings |
| base_price_minor | bigint | minor units |
| currency | char(3) | |
| estimated_days | smallint unsigned | |
| revision_limit | smallint unsigned, default 0 | |
| is_active | boolean, indexed | |

## audit_logs

Append-only. The model throws on update/delete; on PostgreSQL a `BEFORE UPDATE OR DELETE` trigger (`audit_logs_no_update_delete`) also rejects changes at the database level.

| Column | Type | Notes |
| --- | --- | --- |
| actor_type | string(32) | `user`, `system`, `console` (later `agent`) |
| actor_id | bigint, null | |
| action | string(100), indexed | dotted name, e.g. `auth.login`, `settings.updated` |
| resource_type, resource_id | string, bigint, null | composite index |
| correlation_id | uuid, null, indexed | from `X-Request-Id` |
| metadata | json, null | sensitive keys redacted before write |
| ip_address | string(45), null | |
| created_at | timestamp, indexed | no `updated_at` |

Indexes: `(resource_type, resource_id)`, `(actor_type, actor_id)`.

## customers

| Column | Type | Notes |
| --- | --- | --- |
| company_name | string | |
| primary_contact | string, null | |
| email | string, null, indexed | stored lower-case |
| phone | string(50), null | |
| status | string(16), indexed | `active`, `inactive` |
| billing_details | text, null | encrypted with `APP_KEY` (`encrypted` cast); hidden from serialization; founder-only to view or change |

## leads

| Column | Type | Notes |
| --- | --- | --- |
| company_name | string | |
| contact_name | string, null | |
| email | string, null, **unique** | stored lower-case; duplicate detection |
| phone | string(50), null | |
| website | string(2048), null | as entered |
| website_domain | string, null, **unique** | normalized host (lower-case, no scheme/port/path/`www.`); duplicate detection |
| source | string(32) | `manual`, `referral`, `inbound`, `research`, `event` |
| source_reference | text, null | where the information came from |
| research_status | string(16) | `pending`, `in_progress`, `complete` |
| qualification_score | tinyint unsigned, null | 0–100 |
| status | string(32), indexed | see [workflows](workflows.md) |
| next_follow_up_at | timestamp, null, indexed | |
| notes | text, null | |
| do_not_contact | boolean, indexed | set by opt-out; blocks outreach |
| opted_out_at, opt_out_reason | timestamp, string(500), null | |
| customer_id | FK customers, null, **unique**, restrict on delete | set once by conversion |
| converted_at | timestamp, null | |
| created_by | FK users, null, null on delete | |

| assigned_agent_id | FK agents, null, null on delete | added in Phase 3 |

## lead_activities

Append-only history (model refuses update/delete). Index `(lead_id, id)`.

| Column | Type | Notes |
| --- | --- | --- |
| lead_id | FK leads, restrict on delete | |
| activity_type | string(50) | `created`, `updated`, `status_changed`, `note`, `follow_up_scheduled`, `opted_out`, `converted` |
| description | text | |
| actor_type, actor_id | string(32), bigint null | |
| metadata | json, null | e.g. `{from, to}` for status changes; changed field names for edits |
| created_at | timestamp | |

## agents

| Column | Type | Notes |
| --- | --- | --- |
| name, slug (unique), role (unique) | string | role is one of the seven logical agents |
| description | text | |
| instructions_version | string(32) | recorded on every run |
| runtime | string(32) | which runtime executes it (`mock` today) |
| model_key | string, null | |
| configuration | json, null | reserved for runtime settings |
| status | string(16), indexed | `enabled` / `disabled`; default disabled; founder-only |
| max_steps, max_retries, timeout_seconds, max_tokens_per_run | integers | enforced limits |
| max_tool_calls_per_run | smallint, default 20 | tool calls allowed per run, enforced by the gateway |
| monthly_budget_minor | bigint | per-agent budget for paid runtimes |
| review_required | boolean | results go to awaiting_review |

## agent_permissions

`(agent_id, tool_name, resource_scope)` unique. `allowed` boolean, `limits` json. Tool names must come from `ToolCatalog`. Cascade-deleted with the agent.

## tasks

| Column | Type | Notes |
| --- | --- | --- |
| agent_id | FK agents, null, null on delete | |
| parent_task_id | FK tasks, null | subtasks |
| title, description | string, text | |
| input | json, null | passed to the runtime |
| status | string(32), indexed | see [workflows](workflows.md#task-lifecycle) |
| priority | string(16), indexed | low, normal, high, urgent |
| due_at | timestamp, null, indexed | |
| result | json, null | validated output of the successful run |
| failure_reason | text, null | |
| attempts | smallint | total runs started |
| auto_retries | tinyint | automatic retries used in this queue cycle |
| created_by_type, created_by_id | string, bigint null | `user`, later `agent` |
| queued_at, completed_at | timestamp, null | |

`project_id` is added with the projects module (Phase 7). Index `(agent_id, status)`.

## task_dependencies

Composite primary key `(task_id, depends_on_task_id)`, both FK tasks, cascade.

## agent_runs

| Column | Type | Notes |
| --- | --- | --- |
| task_id, agent_id | FK, restrict on delete | |
| attempt | smallint | **unique with task_id**: one run per attempt |
| status | string(16), indexed | running, succeeded, failed |
| runtime, model_key, instructions_version | string | what actually ran |
| started_at, finished_at, deadline_at | timestamps | |
| input_hash | char(64) | SHA-256 of task title, description, input and instructions version |
| output | json, null | validated output |
| error_code, error_message | string indexed, text | see the runbook |
| usage_metadata | json, null | steps, total tokens |
| correlation_id | uuid, indexed | |

## model_usage

| Column | Type | Notes |
| --- | --- | --- |
| agent_run_id, agent_id | FK, restrict | |
| provider, model | string | |
| input_tokens, output_tokens | unsigned int | |
| estimated_cost_minor | bigint | from reported tokens and searches at catalog prices |
| provider_request_id | string(128), null | the provider's message id, for tracing |
| web_search_requests | unsigned int, default 0 | provider web searches billed in the call |
| actual_cost_minor | bigint, null | from provider billing only; never estimated |
| currency | char(3) | |
| created_at | timestamp, indexed | index `(agent_id, created_at)` for monthly sums |

Budget checks use `actual_cost_minor` when present, otherwise the estimate.

## approval_requests

Written only by `ApprovalService`. The action and payload never change after creation (model guard); a database edit to the snapshot is detected because it no longer matches the hash.

| Column | Type | Notes |
| --- | --- | --- |
| requested_by_agent_id, requested_by_user_id | FK, null | who asked |
| task_id | FK, null | task the request came from |
| action_type | string(64), indexed | tool name, or `agent.*` for decision-only requests |
| resource_type, resource_id | string, bigint, null | target record, e.g. `lead`, 12 |
| payload_snapshot | json | the exact payload shown to the approver |
| payload_hash | char(64) | SHA-256 of canonical JSON of action, resource and payload |
| reason, rollback_plan | text | |
| risk_level | string(16) | low, medium, high |
| evidence | json, null | list of strings |
| status | string(24), indexed | pending, approved, rejected, expired, invalidated, executing, executed, failed, needs_reconciliation |
| expires_at | timestamp, indexed | `APPROVALS_TTL_HOURS` after creation; applies to both the decision and the execution |
| decided_at, execution_started_at, executed_at | timestamps, null | |
| execution_result | json, null | redacted result or error |
| idempotency_key | string(128), **unique** | the same request is never created twice |

## approval_decisions

Immutable (model guard). `approval_request_id` is **unique**, so the database refuses a second decision. Stores `approver_user_id`, `decision` (approved/rejected), optional `reason`, and the `payload_hash` that was approved.

## tool_executions

One row per tool call, including refused ones.

| Column | Type | Notes |
| --- | --- | --- |
| agent_run_id | FK, null | the run that made the call |
| agent_id | FK | |
| tool_name | string(64), indexed | |
| idempotency_key | string(128) | **unique with tool_name**: SHA-256 of run, tool and canonical input. Refused calls get a random `denied:` key |
| status | string(24), indexed | running, succeeded, failed, denied, awaiting_approval |
| input_hash | char(64) | |
| input_summary, result_summary | json, null | redacted and size-limited |
| external_reference | string, null | id of anything created outside the system |
| approval_request_id | FK, null | for Level 2 calls |
| error_code, error_message | string, text, null | see the runbook |
| started_at, finished_at | timestamps | |

## proposals

One row per **version**. Versions of the same proposal share `reference` (`P-00001`); `(reference, version)` is unique. Written only by `ProposalService`. Once a version leaves `draft`, its content columns cannot change (model guard); changes need a new version.

| Column | Type | Notes |
| --- | --- | --- |
| reference | string(48), indexed | `P-` + id of the first version |
| version | unsigned int | 1, 2, … |
| previous_version_id | FK proposals, null, **unique** | a version can be superseded only once |
| lead_id, customer_id | FK, null | exactly one is set |
| service_package_id | FK, restrict | |
| title, introduction | string(200), text | |
| recipient_name, recipient_email | string, null | defaults from the lead or customer; checked before submit and send |
| scope_snapshot | json | package name, scope, exclusions, estimated days, revisions at drafting time |
| subtotal_minor, discount_minor, amount_minor | bigint | computed by `ProposalPricing`, never entered |
| discount_reason | string(500), null | required when there is a discount |
| currency | char(3) | the package's currency |
| payment_terms | text | |
| status | string(24), indexed | draft, pending_approval, approved, sent, accepted, rejected, changes_requested, expired, declined, withdrawn |
| expires_at | date | validity date shown to the recipient |
| approval_request_id | FK, null | the current `proposal.send` approval |
| content_hash | char(64), null | SHA-256 of the approved payload |
| submitted_at, approved_at, sent_at, responded_at | timestamps, null | |
| delivery_method | string(16), null | `email` or `manual` |
| delivery_status | string(32), null | `accepted_by_mail_server`, `not_delivered_local_mailer`, `recorded_manually`, `failed` |
| delivery_reference | string, null | message id returned by the mailer |
| response_note | text, null | the recipient's answer as recorded by a person |
| created_by_user_id, created_by_agent_id | FK, null | |

Phase 7 adds `project_id` (FK projects, null): set on a change request, the project whose client it goes to. It is part of the approved payload when set.

## proposal_items

Priced lines of a version, ordered by `position`; position 0 is the package at its price. `line_total_minor` = `quantity` × `unit_price_minor`. Lines cannot be created, changed or deleted once the version leaves draft (model guard).

## proposal_events

Append-only history of a version (model guard): `event_type` (created, edited, submitted, approval_requested_again, approved, rejected, sent, send_refused, send_failed, accepted, declined, changes_requested, expired, withdrawn, superseded, version_created), `actor_type` (user, agent, system), `actor_id`, redacted `metadata`, `created_at`.

## service_packages (Phase 7)

`delivery_template` json, null: list of `{name, tasks: [title, …]}` milestones a new project starts with.

## projects

Written only by `ProjectService` and `ReleaseService`.

| Column | Type | Notes |
| --- | --- | --- |
| customer_id | FK, null, restrict | |
| proposal_id | FK, null, **unique**, restrict | the accepted proposal; one project per proposal |
| name, description | string(200), text | |
| status | string(32), indexed | see workflows |
| start_date, due_date | date, null | |
| budget_minor, currency | bigint, char(3) | from the proposal, plus accepted change requests |
| repository_url, staging_url, production_url | string, null | http(s) links without credentials |
| handover_checklist | json | `[{item, done, by, at}]` from `company_os.projects.handover_checklist` |
| created_by_user_id | FK, null | |
| delivered_at, closed_at | timestamps, null | |

## project_milestones

`project_id` (cascade), `position`, `name`, `description`, `status` (pending, in_progress, completed), `due_at` date, `completed_at`.

## project_deliverables

`project_id`, `milestone_id` (null), `name`, `type` (website, design, document, theme, plugin, source_code, other), `url` (a link; files are not stored), `version` (raised when the link changes), `status` (draft, in_review, approved, delivered), `metadata`.

## tasks (Phase 7)

Adds `project_id`, `milestone_id` and `assignee_user_id` (a person doing the task instead of an agent), all nullable.

## project_releases

| Column | Type | Notes |
| --- | --- | --- |
| project_id | FK, restrict | |
| environment | string(16) | staging, production |
| version | string(64) | unique with project and environment |
| commit_reference | string(100), null | |
| target_url | string | |
| release_notes, rollback_plan | text | |
| status | string(24), indexed | see workflows |
| approval_request_id | FK, null | the current `release.deploy_production` approval |
| content_hash | char(64), null | SHA-256 of the approved payload |
| submitted_at, deployed_at | timestamps, null | |
| deployment_note | text, null | how the person deployed it |
| health_status, health_checked_at, health_detail | string(16), timestamp, json | healthy, unhealthy or manual; HTTP status, error, duration, address |
| rolled_back_at, rollback_note | timestamp, text | |

The project, environment, version, commit, URL, notes and rollback plan cannot change once the release leaves draft (model guard).

## release_checks

`project_release_id` (cascade), `check_key` (unique per release), `label`, `mandatory`, `status` (pending, passed, failed, skipped), `evidence`, `recorded_by_user_id`, `recorded_at`. On a production release they cannot change once it leaves draft; on any release once it is cancelled or rolled back (model guard).

## project_events

Append-only history of a project and its releases (model guard): `event_type`, `actor_type`, `actor_id`, `note`, redacted `metadata` (release events carry `release_id`), `created_at`.

## maintenance_plans

| Column | Type | Notes |
| --- | --- | --- |
| customer_id | FK, restrict | an active customer |
| project_id | FK, null | the site; must belong to the customer |
| plan_name | string | |
| billing_frequency, service_interval | string(16) | monthly, quarterly, yearly |
| amount_minor, currency | bigint, char(3) | the agreed price; not money received |
| checklist | json | list of check descriptions copied onto each visit |
| next_service_at | date, null | moved on by the scheduler |
| term_ends_at | date | renewal reminders count from here |
| status | string(16), indexed | active, paused, cancelled, ended |
| notes | text, null | |
| created_by_user_id | FK | the founder |

## maintenance_tasks

One visit. `maintenance_plan_id`, `project_id`, `title`, `status` (scheduled, in_progress, completed, failed, cancelled), `scheduled_at` (date, unique with the plan, so a visit is never created twice), `checklist` (json: item, result, note), `health_detail` (json), `notes`, `evidence`, `source` (scheduler, user, agent) and `source_id`, `started_at`, `completed_at`, `completed_by_user_id`. Once completed, failed or cancelled it cannot change or be deleted (model guard).

## maintenance_findings

`maintenance_task_id` (cascade), `severity` (low, medium, high, critical), `description`, `recommendation`, `risky`, `status` (open, approval_requested, approved, rejected, resolved, wont_fix), `change_plan`, `rollback_plan`, `approval_request_id` (the current `maintenance.risky_change` approval), `resolution_note`, `resolved_at`, `recorded_by_user_id`. Frozen once its visit closes (model guard).

## alerts

`type`, `severity` (info, warning, critical), `title`, `detail`, `subject_type` and `subject_id` (what it links to), `dedupe_key` (unique: one alert per condition), `acknowledged_at`, `acknowledged_by_user_id`.

## operating_reports

`period` (daily, weekly, monthly), `period_start` (unique with the period), `period_end`, `data` (json, the report as built), `generated_at`. Cannot change or be deleted (model guard).
