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
| monthly_ai_budget_minor | bigint | minor units of `default_currency` |
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

`assigned_agent_id` from the master plan is deferred until the `agents` table exists (Phase 3), so it can be a real foreign key.

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
