# Operations guide

For whoever looks after the server. Commands assume the application is in `/var/www/job-tracker` and run as the web user (`sudo -u www-data ...`).

## Routine

| When | What | How |
|---|---|---|
| Daily (cron) | Database backup, keep 14 | `php cli/backup.php --keep=14` |
| Daily (cron) | Housekeeping | `php cli/cleanup.php --apply` |
| Weekly (cron) | Self-check | `php cli/doctor.php` |
| Weekly | Copy backups off the server | see Backups |
| Monthly | Look at the Audit Log for failed sign-ins and access-denied entries | Administration -> Audit Log, filter by action |
| Monthly | Apply operating-system and PHP security updates | `sudo apt update && sudo apt upgrade`, then `php cli/doctor.php` |
| Quarterly | **Rehearse a restore** on another machine | see Restore |
| Yearly | Review users and roles; remove people who left | Users page |

## Backups

`php cli/backup.php` writes `storage/backups/job-tracker-YYYYMMDD-HHMMSS.sql.gz` (UTC time in the name, mode 0600) using `mysqldump --single-transaction`, so the site keeps working during the backup. A dump that fails or is cut short is deleted and the command exits with an error, so cron mail means something is wrong.

**A backup on the same server is not a backup.** Copy them elsewhere, for example:
```bash
# from another machine, nightly:
rsync -az --include='job-tracker-*.sql.gz' --exclude='*' backupuser@server:/var/www/job-tracker/storage/backups/ /srv/backups/job-tracker/
```
Backups contain personal data (customer names, phone numbers, password hashes). Keep the copies on an encrypted disk or encrypt them (`gpg --symmetric file.sql.gz`) and restrict who can read them.

Imported files are deleted after import, so the database dump is the only thing to back up. Your `.env` is not in the dump: keep a copy in your password manager.

## Restore

1. Put the site in maintenance (stop the web server or block it): `sudo systemctl stop nginx` (or `apache2`).
2. Make an extra backup of what is there now (`php cli/backup.php`), in case the restore is the mistake.
3. Recreate an empty database and restore:
```bash
mysql -u root -e "DROP DATABASE job_tracker; CREATE DATABASE job_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c job-tracker-20261023-021500.sql.gz | mysql -u root job_tracker
```
4. Start the web server and run `php cli/doctor.php`. If the dump is from an older version, run `php cli/migrate.php`.
5. Sign in, open a recent job and the Audit Log to confirm the data is current.

Only the database changes on restore; whatever happened after the backup was taken is lost, so tell the team which hours to re-enter.

## Upgrading to a new version

1. Read `CHANGELOG.md` for the new version.
2. **Back up first:** `php cli/backup.php`.
3. Unpack the new release next to the old one and keep the old one for rollback:
```bash
sudo mkdir /var/www/job-tracker-1.1.0 && sudo tar -xzf job-tracker-1.1.0.tar.gz -C /var/www/job-tracker-1.1.0 --strip-components=1
sudo cp /var/www/job-tracker/.env /var/www/job-tracker-1.1.0/.env
sudo chown -R www-data:www-data /var/www/job-tracker-1.1.0/storage
cd /var/www/job-tracker-1.1.0 && sudo -u www-data php cli/migrate.php && sudo -u www-data php cli/seed.php && sudo -u www-data php cli/doctor.php
```
`seed.php` is safe to repeat: it adds new permissions and settings without touching your roles, users or values.
4. Point the web server at the new folder (a symlink `current -> job-tracker-1.1.0` makes this one command) and reload it.
5. Sign in and click through Dashboard, All Jobs, one job, Import, Audit Log.

**Rollback:** point the web server back at the old folder. Migrations only add things, so the old code normally still works with the new tables; if a release says otherwise, restore the database backup from step 2.

## Building a release (on a development machine)

```bash
php cli/fetch-assets.php           # only if public/assets/vendor is missing
echo "1.1.0" > VERSION             # and add a CHANGELOG entry
tests/all.sh --perf                # everything must pass
tests/release-check.sh             # builds the archive and proves it runs on its own
# result: dist/job-tracker-1.1.0.tar.gz and .sha256
```

## Logs

| Where | What |
|---|---|
| `storage/logs/app-YYYY-MM-DD.log` | Application errors (stack traces are only here, never on screen). Files older than 60 days are removed by `cleanup` |
| Web server error log | `/var/log/nginx/job-tracker-error.log` or `/var/log/apache2/job-tracker-error.log` |
| Administration -> Audit Log | Who did what: sign-ins, changes, imports, exports, settings, backups |
| `storage/logs/cron.log` | Output of the scheduled jobs |

A one-line check for trouble: `grep -c ERROR storage/logs/app-$(date -u +%F).log`.

## When something goes wrong

| Situation | Do this |
|---|---|
| **The only administrator forgot their password** | `php cli/reset-password.php --email=owner@yourcompany.com`. It prints a temporary password once, unlocks the account and forces a new password. The reset is in the Audit Log |
| A user is locked out (5 wrong passwords) | Administrator: Users -> the person -> **Unlock**. It also clears itself after 15 minutes |
| A user forgot their password | Administrator: Users -> the person -> **Reset password**; give them the temporary password in person or by phone |
| Someone should lose access now | Users -> **Deactivate**. Their open sessions stop on their next click |
| Suspected misuse | Audit Log: filter by user and date. Deactivate the account, reset the passwords of people who may have shared it, keep the Audit Log (do not run cleanup with `--audit-days`) |
| Imports fail | Read the message on the preview page; check PHP limits (`doctor` shows them) |
| Disk filling up | `du -sh storage/*`; run `php cli/cleanup.php --apply`; move old backups off the server |
| Database password changed | Change `DB_PASSWORD` in `.env` (no restart needed for PHP-FPM; cron picks it up on its next run) |
| Server was hacked or you lost the server | Build a new server from `docs/deployment.md`, restore the newest backup, **change every password** (database, server, all application users), review the Audit Log |

## What the system keeps and for how long

| Data | Kept | Removed by |
|---|---|---|
| Jobs, history, users, roles | Until deleted in the application (deleted jobs stay in the Trash and the database) | You |
| Audit log | **Forever** by default | Only `cleanup --apply --audit-days=N` (minimum 365) |
| Sign-in attempts | 30 days | `cleanup` |
| Import previews | 24 hours | `cleanup` / expiry |
| Backups | The newest 14 on the server | `backup --keep=N` |
| Log files | 60 days | `cleanup` |
