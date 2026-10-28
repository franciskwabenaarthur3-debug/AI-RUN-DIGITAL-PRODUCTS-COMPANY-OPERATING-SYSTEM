# Company OS

An AI-assisted operating system for a small digital products company (websites, WordPress work, maintenance, digital products). The founder stays in control: AI agents will work through registered tools, persisted tasks and enforceable approvals, never through unrestricted access.

**Status: Phase 8 (maintenance and reporting).** What exists today is listed below. Nothing else should be assumed to work yet.

| Area | State |
| --- | --- |
| Laravel 12 skeleton, PHP 8.2-compatible lock file | Done |
| PostgreSQL configuration (SQLite in-memory for fast tests) | Done |
| Session login/logout, rate limiting, deactivated-user lockout, no public registration | Done |
| Roles: founder, operator, viewer | Done |
| Company settings (currency, timezone, AI budget limit in USD with an alert threshold, approval policy, emergency pause flag) | Done; the pause is enforced on agent runs and tool calls, and the budget is checked before every paid run |
| Service packages: create, edit, deactivate (founder only), prices in minor units | Done |
| Leads: search, filters, pagination, create/edit, research evidence, activity history, follow-ups | Done |
| Lead status workflow with enforced transitions, duplicate detection (email + website domain), opt-out | Done |
| Lead → customer conversion (exactly once) | Done |
| Customers with encrypted, founder-only billing details | Done |
| Append-only audit log with secret redaction and correlation ids | Done |
| Founder dashboard, settings page, audit log page | Done |
| Agent registry (7 logical agents, disabled by default), founder-only enable/disable and tool permissions | Done |
| Tasks: lifecycle state machine, dependencies, review, retry, cancel, execution history and cost | Done |
| Task runner: atomic claim, step/token/deadline/budget limits, bounded retries, structured output validation, stale-run recovery | Done |
| Deterministic mock agent runtime | Done |
| AI gateway: provider interface, versioned model catalog, timeouts, bounded retries, normalized provider errors, token and cost accounting, budget alerts | Done |
| Anthropic Claude provider through the official PHP SDK (`llm` runtime), enabled only when `ANTHROPIC_API_KEY` is set | Done; tested against a fake transport, not yet against the live API |
| Research Agent and Sales Agent instructions; founder UI for runtime, model and limits | Done |
| Tool gateway: prohibited actions, permissions, input validation, idempotency, per-run call caps, audit of every call | Done |
| Tools: create lead, update lead status, draft outreach (stored, never sent), draft proposal, validate proposal, create subtask, request approval; provider web search for agents granted `search_prospects` | Done; other catalog tools are refused until built |
| Approval engine: payload snapshot and hash, expiry, founder-only immutable decisions, atomic execution claim, reconciliation flag | Done |
| Approval center; tool calls on task pages; pending approvals on the dashboard | Done |
| Proposals: drafts from service packages, server-side pricing and discounts, immutable versions, preview, founder approval of the exact version and recipient, send by email (when configured) or record a manual send, delivery result, responses, expiry | Done |
| Projects: created once from an accepted proposal, milestones and tasks from the package's delivery template, person and agent tasks, deliverables with versions, blockers, staging and repository links, handover checklist, change requests as priced proposals | Done |
| Releases: staging and production records, mandatory and optional quality checks with evidence, production gated on passed mandatory checks, a verified staging release, the client's approval and the founder's approval of the exact release; deployment recorded by the founder; HTTP health check; rollback record | Done; Company OS records deployments, it does not deploy |
| Maintenance: founder-priced plans per customer and site, recurring visits created by the scheduler (no duplicates), non-destructive checklists, health check, findings with severity, founder approval of the exact change and rollback plan before any live-site change, completion evidence frozen afterwards, printable service reports, renewal reminders | Done; people do the maintenance work, Company OS records it |
| Alerts (in-app only): overdue and failed visits, high and critical findings, renewals, failed releases, approvals needing reconciliation, failed agent runs, AI budget | Done; nothing is emailed |
| Operating reports (daily, weekly, monthly) from recorded data with rule-based advisory priorities, stored for finished periods; AI cost by month, agent and model; dashboard maintenance and alert counts | Done; revenue, expenses, hosting and support are listed as not recorded |

## Requirements

- PHP 8.2 or newer (dependencies are locked against 8.2 via `config.platform.php`)
- Composer 2
- Node 20+ and npm (Tailwind CSS 4 via Vite)
- PostgreSQL 14+ (MySQL is possible but untested)

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate

# Create the database and user, then set DB_PASSWORD in .env
createuser -P company_os
createdb -O company_os company_os

php artisan migrate --seed          # creates the company settings row
npm install && npm run build

# Create your founder account (prompts for a password, min 12 chars)
php artisan company-os:create-user you@example.com --name="Your Name" --role=founder

php artisan serve
```

For local-only demo data (a founder `founder@example.test` / `local-password-123` and three inactive placeholder packages with zero prices):

```bash
php artisan db:seed --class=LocalDemoSeeder   # refuses to run outside local/testing
```

To let agents use a real model, set `ANTHROPIC_API_KEY` in `.env` (never commit it), then on an agent's page choose the `llm` runtime and a model. Without a key the `llm` runtime is not offered and nothing calls a provider.

Proposals are never emailed unless `PROPOSALS_EMAIL_ENABLED=true` and the `MAIL_*` settings point at a real mail service. Until then the founder sends proposals themselves and records the send in Company OS.

`composer dev` runs the web server, queue worker, log tail and Vite together. Agent tasks run on the queue, so a worker must be running (`php artisan queue:work`), and the scheduler (`php artisan schedule:work` locally, or a cron entry for `schedule:run` in production) recovers runs lost by crashed workers, expires old approval requests, creates due maintenance visits, raises alerts and stores operating reports.

## Tests

```bash
php artisan test                                   # SQLite in-memory
DB_CONNECTION=pgsql DB_DATABASE=company_os_test \
  DB_USERNAME=company_os DB_PASSWORD=secret php artisan test   # PostgreSQL
vendor/bin/pint --test                             # code style
```

CI (`.github/workflows/tests.yml`) runs both suites on PHP 8.2 with PostgreSQL 16. See [docs/test-report.md](docs/test-report.md) for the latest results.

## Documentation

- [Architecture](docs/architecture.md)
- [Database schema](docs/database-schema.md)
- [Security](docs/security.md)
- [Workflows](docs/workflows.md)
- [Agent policies](docs/agent-policies.md)
- [Operations runbook](docs/operations-runbook.md)
- [Test report](docs/test-report.md)

The remaining documents listed in the master plan (deployment, backup and recovery, user manual) will be written as the features they describe are built.
