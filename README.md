# Company OS

An AI-assisted operating system for a small digital products company (websites, WordPress work, maintenance, digital products). The founder stays in control: AI agents will work through registered tools, persisted tasks and enforceable approvals, never through unrestricted access.

**Status: Phase 4 (governance: approvals and tool gateway).** What exists today is listed below. Nothing else should be assumed to work yet.

| Area | State |
| --- | --- |
| Laravel 12 skeleton, PHP 8.2-compatible lock file | Done |
| PostgreSQL configuration (SQLite in-memory for fast tests) | Done |
| Session login/logout, rate limiting, deactivated-user lockout, no public registration | Done |
| Roles: founder, operator, viewer | Done |
| Company settings (currency, timezone, AI budget limit, approval policy, emergency pause flag) | Done; the pause is enforced on agent runs and tool calls, and the budget is checked before paid runs (none exist yet) |
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
| Deterministic mock agent runtime | Done (no real AI provider yet) |
| Tool gateway: prohibited actions, permissions, input validation, idempotency, per-run call caps, audit of every call | Done |
| Tools: create lead, update lead status, create subtask, request approval | Done; other catalog tools are refused until built |
| Approval engine: payload snapshot and hash, expiry, founder-only immutable decisions, atomic execution claim, reconciliation flag | Done |
| Approval center; tool calls on task pages; pending approvals on the dashboard | Done |
| AI provider, proposals, projects, maintenance | Not started (Phases 5 to 8) |

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

`composer dev` runs the web server, queue worker, log tail and Vite together. Agent tasks run on the queue, so a worker must be running (`php artisan queue:work`), and the scheduler (`php artisan schedule:work` locally, or a cron entry for `schedule:run` in production) recovers runs lost by crashed workers and expires old approval requests.

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
