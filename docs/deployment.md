# Deployment

How to run Company OS on a server for staging and for production. Nothing in this repository deploys itself; these are the steps a person follows. This has not yet been run on a production server.

## What you need

A small Linux server is enough for the pilot. Use one server for staging and another for production, or at least separate databases, `.env` files, `APP_KEY`s and credentials.

- PHP 8.2 or newer with `pdo_pgsql`, `mbstring`, `intl`, `openssl`
- Composer 2, Node 20+ (only to build the CSS and JS)
- PostgreSQL 14+ and its client tools (`pg_dump`, `pg_restore`) at the same or a newer major version than the server
- A web server (nginx or Apache) with PHP-FPM, serving `public/` only
- HTTPS (for example a free Let's Encrypt certificate)
- A process manager for the queue worker (Supervisor or systemd) and cron for the scheduler

## First install

```bash
git clone <your private repository> /var/www/company-os && cd /var/www/company-os
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate
```

Edit `.env`. For production:

| Key | Value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://your-domain` |
| `DB_*` | the production database and a user that owns only it |
| `SESSION_SECURE_COOKIE` | `true` |
| `QUEUE_CONNECTION` | `database` |
| `AGENTS_ALLOW_MOCK_RUNTIME` | `false` |
| `RELEASE_HEALTH_CHECK_ALLOW_PRIVATE` | `false` |
| `SECURITY_REQUIRE_FOUNDER_TWO_FACTOR` | `true` |
| `BACKUP_VERIFY_DATABASE` | an empty scratch database for restore tests, e.g. `company_os_restore` (`createdb -O company_os company_os_restore`) |
| `ANTHROPIC_API_KEY` | only when agents should use a real model |
| `PROPOSALS_EMAIL_ENABLED`, `MAIL_*` | only with a real mail service |

Keep a copy of the finished `.env`, and especially `APP_KEY`, somewhere safe away from the server. Without `APP_KEY`, encrypted columns in a backup cannot be read.

```bash
php artisan migrate --force --seed       # company settings row and the seven disabled agents
php artisan company-os:create-user you@example.com --name="Your Name" --role=founder
php artisan config:cache && php artisan route:cache && php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache
```

**Queue worker** (Supervisor example):

```ini
[program:company-os-worker]
command=php /var/www/company-os/artisan queue:work --tries=1 --timeout=900
user=www-data
autostart=true
autorestart=true
stopwaitsecs=910
```

**Scheduler** (crontab of `www-data`):

```
* * * * * cd /var/www/company-os && php artisan schedule:run >> /dev/null 2>&1
```

Then sign in, set up two-step sign-in when asked, review Settings (currency, timezone, AI budget), and run:

```bash
php artisan company-os:backup
php artisan company-os:verify-backup
php artisan company-os:readiness
```

Fix every FAILED item before real clients are involved. Point an external uptime monitor at `https://your-domain/up`.

## Updating

```bash
cd /var/www/company-os
php artisan company-os:backup                     # a fresh backup before every migration
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan company-os:readiness
```

Try every update on staging first. Read the migrations in the update before running them on production; never run `migrate:fresh`, `migrate:reset` or `db:wipe` on production.

**Rolling back an update:** check out the previous commit and repeat the build and cache steps. If a migration has to be undone, restore the backup made just before (see [backup and recovery](backup-recovery.md)) instead of running `migrate:rollback` against live data.

## Pilot plan

The master plan asks for the full workflow with test clients first, then a limited real-client pilot.

1. **On staging, with test clients:** follow the [user manual](user-manual.md) through one complete job: a lead, a proposal approved and recorded as sent, acceptance, a project through staging and an approved production release, a maintenance plan and one completed visit. The automated version of this is `tests/Feature/Acceptance/PilotWorkflowTest.php`; doing it by hand checks the pages, email and your own routine.
2. **On production, before real clients:** `company-os:readiness` passes, a backup has been restored successfully, the System page is all OK, and you have reviewed every account and every agent's granted tools.
3. **Limited real-client pilot:** one to three clients. Keep agents on draft-only work and review everything they produce. Keep proposal email off until you have sent a few manually. Check the System page and Alerts daily, and read the weekly report.
4. **After the pilot:** note what was slow, wrong or missing, and decide from real use what to change before taking more clients.
