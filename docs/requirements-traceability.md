# Requirements traceability

Each requirement from the client's brief, where it is built, and the checks that prove it. "U" = unit/integration (`tests/*.php`), "H" = HTTP smoke script, "B" = browser walkthrough (`tests/e2e/uat.js`), "UAT" = scenario in `docs/uat-checklist.md`.

| # | Requirement | Where | Evidence |
|---|---|---|---|
| R1 | Keep all jobs in one place | `jobs` table, All Jobs list | U run.php (jobs), H smoke-jobs |
| R2 | A job whose available delivery date is within 30 days appears under **Jobs available for delivery** automatically | `JobBucket`, Ready for Delivery | U phase3 (section conditions), phase8 (exact day 30/31 boundary, the next day a job moves by itself); H smoke-workflow; B "day 30 is Ready"; UAT B2, B4, B5 |
| R3 | A job more than 30 days away appears under **Jobs more than 30 days** automatically | `JobBucket`, More Than 30 Days | same as R2; UAT B3, B4 |
| R4 | The 30 days is configurable | Settings page, `settings.ready_threshold_days` | U phase7 (validation, effect on sections), H smoke-audit; UAT F7 |
| R5 | In Transit and Delivered sections | `JobWorkflowService`, section lists | U phase3, phase8; H smoke-workflow; B dispatch/deliver; UAT C1-C4 |
| R6 | Dashboard | `DashboardService` | U phase3 (counts equal list totals); H smoke-workflow; UAT D1-D3 |
| R7 | Create and edit jobs with the client's fields (job #, customer, pickup, 1st available, from, to, CF, balance, job location, carrier, driver, phone, storage fee, notes) | `JobController`, `JobRules` | U run.php, phase8 (lengths, unicode, money, dates); H smoke-jobs; B; UAT B1, B6-B9, C7 |
| R8 | Storage fee entered manually | `jobs.storage_fee`, form | U phase2; UAT C9 |
| R9 | Change status with a history | `job_status_history`, timeline | U phase3, phase8 (double click, illegal steps); UAT C4-C6 |
| R10 | Search, filter, sort | `JobCriteria` whitelists | U phase2/3, phase8 (odd search strings, bad sort/page); H smoke-jobs; B; UAT B10, B11 |
| R11 | CSV / Excel **import** with validation and preview | `ImportService` | U phase5 (1,000+ checks, 10 mutations); H smoke-import; B; UAT E1-E5, E7 |
| R12 | CSV / Excel **export** of the current list | `ExportService` | U phase5; H smoke-import; B; UAT E6 |
| R13 | Secure login | `AuthService`, sessions | U run.php; H smoke.sh; UAT A1-A5 |
| R14 | Roles, permissions and a permission-management screen | `RoleService`, permission matrix | U phase4 (role x route sheet, last-admin rule); H smoke-admin; UAT F1-F5 |
| R15 | Audit trail of important actions | `AuditService`, `/audit` | U phase7; H smoke-audit; UAT F6 |
| R16 | PDO prepared statements | `Database` (native prepares, no emulation) | U (injection strings in phases 2-8); code scan of every interpolated SQL fragment: all come from fixed lists or validated identifiers (`docs/security-review.md`) |
| R17 | Password hashing | Argon2id via `PasswordPolicy` | U run.php |
| R18 | Server-side validation | `Validator`, `JobRules`, `ImportService` | U every phase; phase8 edge cases |
| R19 | CSRF protection | `CsrfMiddleware` | U phase7 (every POST route without a token); H smoke.sh |
| R20 | Secure sessions | `Session` | U run.php, phase8 (idle 30 min, absolute 8 h, browser fingerprint); H smoke.sh (HttpOnly, SameSite, id rotated on login, old session ended on password change) |
| R21 | Clean modular structure | `app/` layers, `docs/coding-standards.md` | review; route audit |
| R22 | Responsive, modern UI (phone, tablet, desktop) | Bootstrap 5.3 + `app.css` | Phase 6 audit (0 overflow at 360/768/1280, 0 contrast failures); B phone scenario; UAT G1-G3 |
| R23 | Maintainable | tests, docs, CLI tools | `tests/all.sh`, `docs/*` |
| R24 | Core PHP and MySQL, no framework | whole project | `composer.json` has no runtime packages. **MySQL itself is untested here** (see test plan). |

## Requirements implied but not built (needs the client's decision)
Customer contact details beyond name, invoicing and payments, documents or photos per job, e-mail/SMS notifications, a calendar view, multi-company use, API access, and automatic storage-fee calculation. Listed again as questions at the end of `docs/uat-checklist.md`.
