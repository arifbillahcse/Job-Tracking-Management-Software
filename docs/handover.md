# Handover

Everything the client's team needs to own, run, and extend this software.

## 1. What you are receiving

| Item | Where |
|---|---|
| Source code, full history (one commit per phase) | the repository |
| Production release archive + checksum | build with `scripts/build-release.sh` (`dist/job-tracker-1.0.0.tar.gz`) |
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
| `create-admin`, `create-user`, `reset-password` | First administrator; more users; break-glass password recovery |
| `backup`, `cleanup` | Database backup; housekeeping (dry run unless `--apply`) |
| `doctor`, `route-audit` | Environment self-check; access-rule check of every route |
| `permission-report` | Regenerates `docs/permission-matrix.md` from the live system |
| `seed-demo`, `fetch-assets` | Demo data (never in production); bundle Bootstrap locally |

## 3. Evidence of quality

1,363 automated unit/integration checks, a 44-check proof that the release archive runs on its own, 336 HTTP checks in 6 suites, a 19-step browser walkthrough, a scale test (20,000 and 100,000 jobs), WCAG-AA contrast and layout audits, and about 20 deliberate-breakage checks proving the tests can fail. Run all with `tests/all.sh --perf --e2e`. Details and what is *not* covered: `docs/test-plan.md`.

## 4. Known limits and things not verified

Be clear-eyed about these before relying on the system:

| | |
|---|---|
| **Real MySQL/MariaDB** | Never run against one while building (none was available). The code uses standard SQL and has a static compatibility lint, but follow `docs/deployment.md` step 9 on staging before real data |
| **Real Apache/Nginx, HTTPS** | Configuration templates were reviewed and consistency-tested, not applied to a live server |
| **Microsoft Excel** | Generated `.xlsx` files are valid and read back correctly but were not opened in Excel itself. `.xls` (old format) is not supported |
| **Browsers/devices** | Tested in Chromium with phone/tablet emulation only; not Safari, Firefox, or real devices; not with a screen reader |
| **Load** | Verified one request at a time. Many simultaneous users are untested; file-based sessions suit one server |
| **Limits by design** | 5,000 rows / 5 MB per import; 50,000 (CSV) / 20,000 (Excel) rows per export |
| **No e-mail/SMS** | Passwords are handed over in person. There is no "forgot password" e-mail and no notifications |
| **No MFA** | Single-factor sign-in with lockout and throttling |
| **Penetration test** | A self-review only (`docs/security-review.md`). For a system holding customer data, an independent test is recommended before opening it to the internet |
| **Audit log** | Kept forever by default and contains personal data (names, addresses, IPs); decide a retention period |

## 5. Not built (candidates for a next phase)

Customer contact details (phone/e-mail/address), invoices and payments, documents or photos per job, e-mail/SMS notifications, a calendar or map view, reports, a customer portal, multiple companies or branches, an API, automatic storage-fee calculation, a "forgot password" e-mail flow, multi-factor sign-in. Each needs a decision from the client first (questions are at the end of `docs/uat-checklist.md`).

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
