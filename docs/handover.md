# Handover

Everything the client's team needs to own, run, and extend this software.

## 1. What you are receiving

| Item | Where |
|---|---|
| Source code, full history (one commit per phase) | the repository |
| Production release archive + checksum | build with `scripts/build-release.sh` (`dist/job-tracker-<version>.tar.gz`; the current version is in the `VERSION` file) |
| Deployment, operations, administrator, user guides | `docs/deployment.md`, `docs/operations.md`, `docs/admin-guide.md`, `docs/user-guide.md` |
| Acceptance pack | `docs/uat-checklist.md`, `docs/requirements-traceability.md`, `docs/test-plan.md` |
| Security | `docs/security-review.md`, `docs/permission-matrix.md` |
| Business rules we assumed | `docs/assumptions.md` (please read and confirm every row) |
| Developer notes | `README.md`, `docs/coding-standards.md`, `docs/ui-guide.md`, `docs/wireframes.md`, `CHANGELOG.md` |
| Tools | `tests/all.sh` (all automated checks), `cli/*` (see below) |

## 2. How it is built (one page)

* **Core PHP 8.1+**, MySQL/MariaDB, Bootstrap 5.3. No framework, no runtime Composer packages, nothing to `npm install`.
* `public/index.php` is the only entry point. `app/Core` (router, request/response, session, CSRF, validator, database) -> `app/Controllers` (thin) -> `app/Services` (business rules) -> `app/Repositories` (SQL, always prepared statements) -> `views/` (escaped output).
* **Sections are computed, not stored:** a job is in Ready / More Than 30 Days from its status and 1st available date versus today plus the day limit (`app/Services/JobBucket.php`). That is why jobs move by themselves and why changing the limit re-sorts instantly.
* **Permissions** are keys in `app/Config/permissions.php`. Code checks keys, never role names. Every route declares who may reach it, or it is refused (`app/Config/routes.php`).
* **Workflow** (statuses and allowed steps) is data in `app/Config/statuses.php`.
* **Migrations** are numbered SQL files in `database/migrations`, applied by `php cli/migrate.php`.

### Command-line tools (`php cli/<name>.php`)
| Tool | Purpose |
|---|---|
| `migrate`, `seed` | Create/upgrade tables; add permissions, default roles, default settings (safe to repeat) |
| `create-admin`, `create-user`, `reset-password` | First administrator; more users (all by `--username`); break-glass password recovery |
| `backup`, `cleanup` | Database backup; housekeeping (dry run unless `--apply`) |
| `doctor`, `route-audit` | Environment self-check; access-rule check of every route |
| `permission-report` | Regenerates `docs/permission-matrix.md` from the live system |
| `verify` | Read-only check of a deployment: runs every job/trip/foreman query on the real database, reports version, commit, settings, data and inconsistencies. Run it after every upgrade and paste it when something looks wrong |
| `sync-roles` | Show (`--apply`: grant) default permissions that Manager/Staff are missing after an upgrade; `doctor` warns about it |
| `storage-fees` | Re-assess the automatic storage fee of open jobs (the app also does this once a day by itself) |
| `seed-demo`, `fetch-assets` | Demo data (never in production); bundle Bootstrap locally |

## 3. Evidence of quality

About 2,830 automated unit/integration checks, a 44-check proof that the release archive runs on its own, about 340 HTTP checks in 6 suites, a 47-step browser walkthrough, a scale test (20,000 and 100,000 jobs, with about 2,000 trips), WCAG-AA contrast and layout audits, and about 20 deliberate-breakage checks proving the tests can fail. Run all with `tests/all.sh --perf --e2e`. Details and what is *not* covered: `docs/test-plan.md`.

## 4. Known limits and things not verified

Be clear-eyed about these before relying on the system:

| | |
|---|---|
| **Real MySQL/MariaDB** | The automated tests run on SQLite (no MySQL server was available where the software was built). The demo server runs MariaDB and the client's changes have been deployed there version by version, but **every new migration (008 - 024) and every new query should be confirmed on the real server after each upgrade**: run `php cli/migrate.php` then **`php cli/verify.php`**, which runs every list, statement, invoice, export and master-data query and checks every table's columns against the real database, and keep a backup before migrating (migrations only add; there are no rollback files). A static MySQL-compatibility lint of the SQL files runs in the test suite (`tests/phase9.php`). |
| **Real Apache/Nginx, HTTPS** | Configuration templates were reviewed and consistency-tested, not applied to a live server |
| **Microsoft Excel** | Generated `.xlsx` files are valid and read back correctly but were not opened in Excel itself. `.xls` (old format) is not supported |
| **Browsers/devices** | Tested in Chromium with phone/tablet emulation only; not Safari, Firefox, or real devices; not with a screen reader |
| **Load** | Verified one request at a time. Many simultaneous users are untested; file-based sessions suit one server |
| **Limits by design** | 5,000 rows / 5 MB per import; 50,000 (CSV) / 20,000 (Excel) rows per export |
| **No e-mail/SMS** | Passwords are handed over in person. There is no "forgot password" e-mail and no notifications |
| **No MFA** | Single-factor sign-in with lockout and throttling |
| **Penetration test** | A self-review only (`docs/security-review.md`). For a system holding customer data, an independent test is recommended before opening it to the internet |
| **Audit log** | Kept forever by default and contains personal data (names, addresses, IPs); decide a retention period |

## 4b. What changed in the client's change request (versions 1.1 - 1.3)

One-click In Transit / Delivered with loaded and delivery dates; sections counted from the 1st available date (pickup date selectable); automatic storage fee (A-CF x rate x months from the pickup date, rate in dollars or cents, manual fees never overwritten); customer phone; the sheet's money and detail fields on a job; foremen and foreman trips with per-job expenses, report, CSV export, job and dashboard integration. Every reading of the client's sheet is an assumption in `docs/assumptions.md` (sections J-V); the open questions are at the end of `docs/uat-checklist.md`.

## 4c. What was added after that (versions 1.5 - 1.22)

Pickup details and the pickup calendar; **Stage** (booked, stage, in transit, delivered) with a carrier list and the driver; the orange Driver / Job Tracking buttons; the **Carrier Statement** (CF Pay = each job's own carrier rate x A-CF, Labor / Pads / Paid, who owes whom, printable with a company header); **saved invoices** DI-n and CI-n (snapshots, one open invoice per job per kind, paid / void, generate company invoices); **Companies** and **Drivers** lists (matched to the job's broker / driver name); the **import of the old application's data**; the dashboard's invoice box and the **invoice export**; and safety nets in the tests (every POST route refuses a forged token). The details, version by version, are in `CHANGELOG.md`; each business rule we had to assume is a row in `docs/assumptions.md` (AA - AS).

**Decisions the client should confirm** (they are also questions 9 - 15 at the end of `docs/uat-checklist.md`): a company is the job's broker; a positive Total Due means the carrier owes the company; "Paid / adjustment" is added (money already paid is a minus); company invoices made from a driver invoice start with Labor, Pads and Paid at 0; the 10% carrier fee of the old application's print is not used (CF x rate is); invoices are voided, never deleted; the old application may be switched off after the import.

**Operational rhythm for every release:** backup, `git pull`, `php cli/migrate.php`, `php cli/seed.php`, `php cli/sync-roles.php --apply`, `php cli/verify.php`, `php cli/doctor.php`, Ctrl+F5 and sign in again (see `docs/admin-guide.md`, "After pulling a new version").

**The old application** is kept for reference in the repository folder `Old_Application_source_code/`. It is **never served** (the root `.htaccess` refuses it and the document root should be `public/`), it must not be run next to this software, and **if its `config/db.php` ever held real database credentials, change those credentials** (the folder is in the repository's history).

## 5. Not built (candidates for a next phase)

Customer e-mail and address, payments taken through the system (an invoice can only be marked paid), **rules for the sheet's Grand Total / BH Total / Paid / Balance columns and for Addio, Job Beg, "Total to BH" and the 15% (not defined by the client, see assumptions M2, T1-T4)**, documents or photos per job, e-mail/SMS notifications, a calendar or map view, reports beyond the foreman report, a customer portal, multiple companies or branches, an API, a "forgot password" e-mail flow, multi-factor sign-in. Each needs a decision from the client first (questions are at the end of `docs/uat-checklist.md`).

## 6. Ownership checklist (fill in at handover)

| Item | Owner / where it is kept |
|---|---|
| Domain name and DNS | |
| Server / hosting account | |
| Database `root` and application credentials | |
| `.env` file (copy) | |
| HTTPS certificate renewal | (automatic with certbot: confirm) |
| First administrator account | |
| Where off-server backups go, and who checks them | |
| Who restores a backup (and has rehearsed it) | |
| Who applies server security updates | |
| Who can change code, and how it is deployed | |
| Source repository and who has access | |

## 7. First weeks

1. **Day 0:** deploy to staging, follow `docs/deployment.md` including step 9, run `tests/release-check.sh` on the build machine.
2. **Week 1:** UAT with real users on a test copy (`docs/uat-checklist.md`); collect every confusion, not only defects. Answer the assumption questions.
3. **Go-live:** production deployment, go-live checklist, import the real jobs, create real users, **delete or never create** demo accounts.
4. **Week 2-4:** watch the Audit Log and `storage/logs`; confirm backups arrive off-server; rehearse a restore.
5. **Month 2:** review feedback, decide the next phase from section 5.

## 8. Acceptance

| | Name | Date | Signature |
|---|---|---|---|
| Delivered by | | | |
| Received and accepted by | | | |
