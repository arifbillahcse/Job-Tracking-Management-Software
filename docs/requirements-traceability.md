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
| R8 | ~~Storage fee entered manually~~ superseded by R26 (a typed fee remains available as an override) | `jobs.storage_fee_override` | U phase10 |
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

## Client change request (version 1.1.0)

| # | Requirement | Where | Evidence |
|---|---|---|---|
| R25 | A button next to a job moves it to **In Transit** and saves **today as the Loaded date**; it appears on the In Transit page | `_quick_move.php`, `JobWorkflowService` | U phase3, phase10 (one click, date is today, forged dates ignored, double click, permissions, CSRF); H smoke-workflow; B browser walkthrough; UAT C1, C6 |
| R26 | On the In Transit page a **Mark as Delivered** button saves **today as the Delivery date** and moves the job to Delivered | same | U phase3, phase10; H smoke-workflow; B; UAT C3 |
| R27 | **Automatic storage fee** after 30 days: fee = CF × rate, default 0.6, rate changeable (Settings) | `StorageFeeService`, Settings page | U phase10 (day 30/31 boundary, CF 100 → 60, rounding, override, frozen at delivery, rate/day/basis changes, daily refresh; 10 deliberate-breakage checks); B; UAT C9, C10. **Assumption to confirm: 30 days counted from the 1st available date (J2)** |
| R28 | **Customer phone number** on Add/Edit Job, shown on Job Details and the lists, tap-to-call on phones | `jobs.customer_phone`, `tel_link()` | U phase10 (validation, saving, display, search, export, import); B; UAT B12 |
| R38 | The trip module is safe and fast enough: escaping, SQL injection, CSRF, limits, permissions, scale (assumption: see security review) | `TripService`, views, `tests/perf.sh` | U phase12 ("Trips: security"), perf (trips at 20,000 jobs) |
| R37 | Trips integrated with jobs and the dashboard: trips on Job Details, add a job to a trip, new trip from a job, dashboard card (assumptions V1-V3) | `TripService::addJob`, `JobController::show`, `DashboardController` | U phase12 (panel, add, duplicate, unknown/deleted job and trip, conflict marker, audit, prefill, deleted job on a trip, dashboard figures, permissions), B uat.js |
| R36 | Foreman-wise report, trip history by foreman and period, CSV export of trips and of the report (assumptions U1-U3) | `TripService::foremanReport`, `TripExportService`, `trips.export` | U phase12 (sums without losing a cent, filters, deleted trips, export columns/filters/injection/audit, permissions without money or export), B uat.js |
| R35 | Foreman trips and expenses: foremen, trips with several jobs, expense columns, totals, search/filters, permissions, audit (assumptions T1-T7) | migration 010, `TripService`, `TripController`, `ForemanController`, `TripTotals` | U phase12 (migration, validation of every field, create/edit/delete, conflict, search and filters, hidden expenses survive a save, permissions, menu), B uat.js (foreman, trip, totals, search, bad job, delete) |
| R34 | CF and Storage Fee columns in every list and export; the storage fee rate can be changed by authorized users (assumptions P1, P2) | `views/jobs/list.php`, `ExportService`, `settings.storage_fee`, `SettingsController` | U phase11 (columns per list, Staff cannot see, permission, Manager changes the rate, other settings ignored, audit, re-calculation), H smoke-admin |\n| R33 | Storage fee = A-CF x rate x months, counted from the pickup date, configurable rate/days/mode, manual fees never overwritten (assumptions N1-N3) | `StorageFeeService`, `SettingsService` | U phase11 (day 30/31/60/61/90/91, part month, no pickup date, once mode, delivered freeze, override untouched, settings), phase10 |
| R32 | Job fields from the client sheet: E-CF, A-CF, E-Total, Deposit, Grand total, BH total, Paid, Foreman, Job lot, Color (assumptions M1-M5) | migration 009, `JobService`, `JobRules`, `JobFields` | U phase11 (migration keeps data, create/edit/audit, validation, Staff cannot see or write money, search, import with the client's headings, export) |
| R31 | The 30-day section rule counts from the **Pickup Date** (fallback: 1st available date), selectable in Settings (assumption L1) | `JobBucket`, `SettingsService` | U phase3 (boundaries 30/31, fallback, switching back and forth, PHP/SQL twins), H smoke-workflow, B uat.js |
| R30 | Booked jobs more than 30 days past their available date show in More Than 30 Days; storage rate accepts cents (assumptions K1, K2) | `JobBucket`, `SettingsService` | U phase3 (day -30 Ready, -31 More Than 30 Days, SQL/PHP agree), phase10 (`60c`, `60 cents`, invalid) |
| R29 | Database: `customer_phone`, `loaded_date`, `delivery_date`, storage fee and rate stored; calculation logic; dashboard counts and sections follow | migration 008 | U phase10 (migration on a database with jobs: back-fill, nothing lost, idempotent; dashboard counts after each click; every job in exactly one section) |

## Requirements implied but not built (needs the client's decision)
Customer contact details beyond name, invoicing and payments, documents or photos per job, e-mail/SMS notifications, a calendar view, multi-company use, API access, and automatic storage-fee calculation. Listed again as questions at the end of `docs/uat-checklist.md`.
