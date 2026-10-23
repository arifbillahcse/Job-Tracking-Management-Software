# Job Tracker

Custom job tracking software built with **Core PHP 8.1+, MySQL/MariaDB, Bootstrap 5.3, HTML, CSS and JavaScript** - no PHP framework.

Status: **Phase 2 complete** (job create / edit / view / soft-delete on top of the Phase 1 security core). Phase 3 adds the dashboard, the section lists, search/filter/sort and the status workflow. See `docs/assumptions.md` for the demo business rules.

## Quick start (local)

```bash
cp .env.example .env              # then set DB_* and APP_URL (APP_ENV=local for development)
php cli/migrate.php               # create tables
php cli/seed.php                  # permissions, Admin/Manager/Staff roles, default settings
php cli/create-admin.php --email=you@example.com --name="Your Name"
php cli/create-user.php --email=staff@example.com --name="A Staff Member" --role=Staff   # or Manager
php cli/seed-demo.php             # optional: 60 realistic demo jobs spread across every section
php cli/fetch-assets.php          # vendor Bootstrap 5.3.3 locally (needs internet once)
php -S localhost:8000 -t public public/index.php
```

`create-admin.php` prints a generated password once; add `--force-change` to require a new password at first sign-in.
Without `fetch-assets.php`, non-production environments fall back to Bootstrap from a CDN; **production requires the local copy** (the Content-Security-Policy only allows the CDN outside production).

## Web server
Point the document root at **`public/`** only. `public/.htaccess` handles Apache rewrites; for Nginx send everything that is not a real file to `index.php`. Nothing outside `public/` should ever be reachable.

## Tests
```bash
php tests/run.php                                   # 215 unit/integration checks (SQLite in memory)
tests/smoke.sh http://localhost:8000 EMAIL PASSWORD # Phase 1 end-to-end over HTTP (changes then restores that account's password)
tests/smoke-jobs.sh http://localhost:8000 ADMIN_EMAIL ADMIN_PASS STAFF_EMAIL STAFF_PASS   # job flows as Admin and Staff (leaves soft-deleted SMOKE-* jobs)
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
cli/           migrate, seed, seed-demo, create-user, create-admin, fetch-assets
docs/          assumptions, coding standards, wireframes
```

## Security summary
PDO prepared statements (native, not emulated) - Argon2id hashing with automatic rehash - CSRF token on every POST plus Origin check -
session cookies HttpOnly/SameSite, id regenerated on login, idle and absolute timeouts, password change signs out other sessions -
login throttling per account and per IP with identical error messages (no account enumeration) - deny-by-default routing
(every route must declare public / authOnly / permission) - strict CSP and security headers - errors logged, never displayed in production -
audit log for sign-ins, lockouts, password changes, denied access and every job change -
balance / storage fee removed server-side for users without financial permission - optimistic locking on job edits - soft-deleted jobs keep their job # reserved.
