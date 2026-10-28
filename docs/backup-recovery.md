# Backup and recovery

## What needs protecting

| What | Where | How it is protected |
| --- | --- | --- |
| The database (every record, the audit log, approvals, reports) | PostgreSQL | `company-os:backup`, nightly |
| `APP_KEY` and the rest of `.env` | the server | Kept by you, away from the server and away from the backups. Customer billing details, two-step sign-in secrets and sessions are encrypted with `APP_KEY`; a backup without it restores those columns as unreadable. |
| Uploaded files | none | Company OS stores no uploaded files yet. The only files it writes are backups and logs. |
| The code | your Git repository | |

## Backups

`php artisan company-os:backup` runs `pg_dump --format=custom` with fixed arguments and writes `company-os-YYYYMMDD-HHMMSS-xxxxxx.dump` to `storage/app/private/backups` (or `BACKUP_PATH`), readable only by the app's user (`0600`). It records the file's size, SHA-256 checksum and the row counts of key tables in the `backups` table. The scheduler runs it every night at 02:00 while `BACKUP_ENABLED=true`. A failure is recorded and raises a critical alert. The System page lists recent backups.

A SQLite file database (local use only) is copied with `VACUUM INTO`.

**Copy backups off the server.** A backup on the same disk does not survive losing the server. Company OS has no storage credentials and does not upload anything; set up a nightly copy yourself after 02:30, for example with `rclone copy storage/app/private/backups remote:company-os-backups` to an object storage bucket only you can read, or a cron `scp` to another machine. Keep `.env` in a password manager, not in the same bucket.

**Retention.** Company OS does not delete old backups. Keep at least 14 daily backups on the server and longer off-site (for example 30 dailies and 12 monthlies), and delete older files by hand or with your storage's lifecycle rules. The `backups` table keeps the history either way.

**How much can be lost.** With nightly backups, up to a day of changes if the database is lost. Make an extra backup before risky work (`company-os:backup` takes seconds at pilot size).

## Restore tests

`php artisan company-os:verify-backup` takes the latest successful backup (or `--id=`), checks its checksum, restores it with `pg_restore --clean` into the scratch database named in `BACKUP_VERIFY_DATABASE`, and compares the row counts with those recorded when it was made. It refuses to use the live database as the target. The scheduler runs it every Sunday at 03:00 when `BACKUP_VERIFY_DATABASE` is set. The result shows on the System page; a failure raises a critical alert. The System page warns when no restore test has passed for 8 days.

Create the scratch database once: `createdb -O company_os company_os_restore`. It is overwritten by every test; never point it at anything you need.

Tested in development (PostgreSQL 16): a backup restored into a scratch database with identical counts in all twelve checked tables, and the application ran against the restored copy.

## Full recovery (server or database lost)

1. Provision a server as in [deployment](deployment.md), with the **same `APP_KEY`** in `.env`.
2. Create an empty database and user: `createdb -O company_os company_os`.
3. Copy the newest good backup to the server and check it: `sha256sum file.dump` against the checksum on the System page or in your off-site copy's log.
4. Restore: `pg_restore --no-owner --no-privileges --exit-on-error --dbname=company_os file.dump`.
5. `php artisan migrate --force` (applies only migrations newer than the backup), then the cache commands, then start the worker and cron.
6. Sign in, check the dashboard, Approvals and Alerts. Approvals that were executing when the backup was taken show as needing reconciliation after the sweep: check each one by hand before doing anything else.
7. Anything done after the backup (new leads, sent proposals, recorded deployments) is missing: re-enter it from emails and notes.
8. Run `php artisan company-os:readiness`.

## Restoring a single record

Restore the backup into the scratch database (`company-os:verify-backup --id=N`), look up what you need there with `psql`, and re-enter it through the app so the audit log records it. Do not copy rows back into the live database by hand.
