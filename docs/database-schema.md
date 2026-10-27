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
