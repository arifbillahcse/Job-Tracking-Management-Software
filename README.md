# Job Tracker

Custom job tracking software built with **Core PHP 8.1+, MySQL/MariaDB, Bootstrap 5.3, HTML, CSS and JavaScript** - no PHP framework.

Status: **Phase 8 complete** - tests, scale check and user acceptance pack (`docs/test-plan.md`, `docs/uat-checklist.md`, `docs/requirements-traceability.md`) on top of audit log, settings and hardening (`docs/security-review.md`), UI polish (`docs/ui-guide.md`), CSV / Excel import and export, user and role management, user management, role management and a permission matrix on top of the dashboard, the five job sections and status workflow (Phase 3), job CRUD (Phase 2) and the security core (Phase 1). Next: deployment and handover (Phase 9). See `docs/assumptions.md` for the demo business rules and `docs/permission-matrix.md` for what each role can do.

## Quick start (local)

```bash
cp .env.example .env              # then set DB_* and APP_URL (APP_ENV=local for development)
php cli/migrate.php               # create tables
php cli/seed.php                  # permissions, Admin/Manager/Staff roles, default settings
php cli/create-admin.php --email=you@example.com --name="Your Name"
php cli/create-user.php --email=staff@example.com --name="A Staff Member" --role=Staff   # or Manager
php cli/seed-demo.php             # optional: 60 realistic demo jobs (with status history) spread across every section
php cli/fetch-assets.php          # vendor Bootstrap 5.3.3 locally (needs internet once)
php cli/doctor.php                # environment self-check (exit 1 on failure)
php cli/route-audit.php           # every route has one access rule, nothing changes data over GET
php cli/cleanup.php [--apply]     # housekeeping; dry run unless --apply (run --apply daily from cron)
php -S localhost:8000 -t public public/index.php
```

`create-admin.php` prints a generated password once; add `--force-change` to require a new password at first sign-in.
Without `fetch-assets.php`, non-production environments fall back to Bootstrap from a CDN; **production requires the local copy** (the Content-Security-Policy only allows the CDN outside production).

## Web server
Point the document root at **`public/`** only. `public/.htaccess` handles Apache rewrites; for Nginx send everything that is not a real file to `index.php`. Nothing outside `public/` should ever be reachable.

## Tests
```bash
tests/all.sh [--perf] [--e2e]               # EVERYTHING below on a throw-away database (recommended)
php tests/run.php                                   # 1312 unit/integration checks (SQLite in memory)
tests/smoke.sh http://localhost:8000 EMAIL PASSWORD # Phase 1 end-to-end over HTTP (changes then restores that account's password)
tests/smoke-jobs.sh http://localhost:8000 ADMIN_EMAIL ADMIN_PASS STAFF_EMAIL STAFF_PASS       # job CRUD as Admin and Staff (leaves soft-deleted SMOKE-* jobs)
tests/smoke-workflow.sh http://localhost:8000 ADMIN_EMAIL ADMIN_PASS STAFF_EMAIL STAFF_PASS   # sections, dashboard and workflow (leaves soft-deleted WF-* jobs; needs GNU date)
tests/smoke-import.sh http://localhost:8000 ADMIN_EMAIL ADMIN_PASS STAFF_EMAIL STAFF_PASS MANAGER_EMAIL MANAGER_PASS   # import/export over real multipart HTTP (needs python3; cleans up after itself)
tests/smoke-audit.sh http://localhost:8000 ADMIN_EMAIL ADMIN_PASS STAFF_EMAIL STAFF_PASS MANAGER_EMAIL MANAGER_PASS   # settings, audit log, private-file probes (changes settings and restores them)
tests/perf.sh [jobs]                                # response times with 20,000 (or more) jobs
node tests/e2e/uat.js                               # browser walkthrough of the client's workflow (needs Playwright; see the file header)
tests/smoke-admin.sh http://localhost:8000 ADMIN_EMAIL ADMIN_PASS STAFF_EMAIL STAFF_PASS MANAGER_EMAIL MANAGER_PASS   # users, roles, matrix, lockout, forced password change (makes ~6 failed sign-ins on purpose)
```
Tests use SQLite as a stand-in because they need no server; **MySQL/MariaDB is the production database**.

## Layout
```
public/        only web-accessible folder (index.php, assets)
app/Core/      router, request/response, session, CSRF, validator, DB, auth, view
app/Config/    app, routes, permissions, statuses, navigation, settings defaults
app/Controllers, Services, Repositories, Middleware, Database (migrator, seeder), Helpers
views/         layouts, partials and page templates
database/      numbered SQL migrations
storage/       logs, sessions, uploads (never web-accessible)
cli/           migrate, seed, seed-demo, create-user, create-admin, permission-report, fetch-assets, doctor, route-audit, cleanup
docs/          assumptions, coding standards, wireframes, permission-matrix
```

## Security summary
PDO prepared statements (native, not emulated) - Argon2id hashing with automatic rehash - CSRF token on every POST plus Origin check -
session cookies HttpOnly/SameSite, id regenerated on login, idle and absolute timeouts, password change signs out other sessions -
login throttling per account and per IP with identical error messages (no account enumeration) - deny-by-default routing
(every route must declare public / authOnly / permission) - strict CSP and security headers - errors logged, never displayed in production -
audit log for sign-ins, lockouts, password changes, denied access and every job change -
balance / storage fee removed server-side for users without financial permission - optimistic locking on job edits - soft-deleted jobs keep their job # reserved -
sort columns and filters whitelisted, LIKE input escaped - every status change checked against the transition rules and the permission of that specific change, saved atomically with its history -
least-privilege user and role administration (you can only grant what you hold), the last administrator can never be removed, one-time temporary passwords stored only as hashes, permission and account changes effective on the next request.
