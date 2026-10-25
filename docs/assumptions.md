# Demo assumptions and business rules

This is a demo build. The rules below are reasonable assumptions, made so work could proceed without
asking the client questions. **Each one may need adjusting once the client confirms how their business
actually works.** The "Where to change" column shows how cheap each change is.

| # | Assumption | Where to change |
|---|---|---|
| A1 | **1st Available Date** is the first day the customer can accept delivery. | Documentation only |
| A2 | **Ready for Delivery** = status `booked` and 1st Available Date is **within N days of today**. **More Than 30 Days** = status `booked` and the date is **more than N days away**. N defaults to **30**, and a date exactly 30 days away counts as Ready. | `settings.ready_threshold_days` (editable on the Phase 7 settings page) |
| A3 | A job whose available date is already in the past, and which is not yet delivered, stays in **Ready** and is flagged **Overdue**. | One list rule (Phase 3) |
| A4 | **In Transit** and **Delivered** depend on status only, never on dates. | `app/Config/statuses.php` |
| A5 | Statuses: `booked`, `in_transit`, `delivered`, `on_hold`, `cancelled`. On Hold and Cancelled jobs appear only in **All Jobs**. | `app/Config/statuses.php` |
| A6 | Allowed transitions: booked → in_transit → delivered; booked / in_transit → on_hold; on_hold → booked; any active status → cancelled. Re-opening a delivered or cancelled job needs the `jobs.reopen` permission and a note. | `app/Config/statuses.php` |
| A7 | ~~Storage Fee is a manually edited field.~~ **Superseded in version 1.1.0:** the fee is calculated automatically (CF × rate, see J1-J3). A fee can still be typed by hand as an *override*. | `StorageFeeService` |
| A8 | **Balance** is the amount the customer still owes; a single number, no payment ledger. | Schema comment (Phase 2) |
| A9 | **CF** means cubic feet: an optional decimal number. | Label only |
| A10 | **Job #** is typed in by staff and must be unique. Auto-numbering is deferred. | Validation rule (Phase 2) |
| A11 | ~~Moving a job In Transit requires carrier name, driver name and phone number.~~ **Superseded in version 1.1.0:** moving a job is one click and needs nothing; carrier details are optional (J4). | `statuses.php` |
| A12 | One company, one time zone, one currency symbol, English only. Times are stored in UTC. | `settings` table |
| A13 | Three seeded roles: **Admin** (every permission, cannot be deleted), **Manager** (everything except administration), **Staff** (no financial figures, no deletion, no import). | `app/Config/permissions.php` |
| A14 | No self-service password reset by e-mail and no "remember me". An Admin resets passwords. Accounts can be flagged to force a password change at next sign-in. | Deferred |
| A15 | Excel (.xlsx) import/export is optional; CSV is the baseline. | Phase 5 |

## Job management decisions (Phase 2)

| # | Decision | Where to change |
|---|---|---|
| B1 | **Required** job fields: Job #, customer first name, customer last name, 1st available date, From, To. Optional: pickup date, job location, CF, notes. | `app/Validation/JobRules.php` |
| B2 | Job # may contain letters, digits and `- _ / .` (max 40) and is unique **including deleted jobs**, so a number is never reused by accident. | `JobRules` / `005_create_jobs.sql` |
| B3 | New jobs always start as **Booked**. Status, carrier, driver and phone are not editable on the job form; they change only through the Phase 3 workflow (dispatch / deliver / hold / cancel), so history and rules cannot be bypassed. | `JobService::EDITABLE` |
| B4 | **Balance and Storage Fee are protected.** Without `jobs.view_financials` (or `jobs.edit_financials`) they are removed from the data before it reaches any page, and writes to them are ignored without `jobs.edit_financials`. Both must be 0 or more. | `JobService`, permissions |
| B5 | **Delete is a soft delete** (job moves to Trash, recoverable). Deleting and restoring both need `jobs.delete` (Admin and Manager by default). | `permissions.php` |
| B6 | **Editing is protected against overwriting a colleague's changes.** If a job was saved by someone else after the form was opened, the save is refused, the user's entries are kept on screen and they can review and save again. | `JobService::update` |
| B7 | Creating, editing, deleting and restoring jobs are written to the audit log with old and new values of the fields that changed. | `JobService` |

## Sections, dashboard and workflow decisions (Phase 3)

| # | Decision | Where to change |
|---|---|---|
| C1 | **One definition of the sections.** The dashboard counts and every list use the same condition, so a card's number always equals the list it links to. A job's section is never stored; it is worked out from its status and 1st available date each time, so it moves by itself when either changes. | `app/Services/JobBucket.php` |
| C2 | "Today" is the business's today (time-zone setting), not the server's or UTC's. | `settings.timezone` |
| C3 | A threshold that is blank or not a number falls back to **30** (not 0, which would empty Ready). It is capped between 0 and 3650. The section title ("More Than 30 Days") follows the setting. | `settings.ready_threshold_days` |
| C4 | **Resume returns a job to Booked.** Any carrier details are cleared (kept in the history) and the job has to be dispatched again. Putting a job on hold or cancelling it needs a reason. | `app/Config/statuses.php` |
| C5 | **Reopening** a delivered job (back to In Transit) or a cancelled job (back to Booked) needs the `jobs.reopen` permission and a reason. A reopened delivered job keeps its carrier details and loses its delivery date. | `statuses.php`, permissions |
| C6 | ~~Dispatching needs carrier name, driver name and a valid phone number; delivering needs a delivery date.~~ **Superseded in version 1.1.0:** both are one click and today's date is saved (J4-J5). | `JobRules::dispatchRules`, `JobWorkflowService` |
| C7 | Status and its history row are saved in one transaction. If another user moved the job first, the second change is refused with a message instead of overwriting. | `JobWorkflowService`, `JobRepository::changeStatus` |
| C8 | The carrier details of a job that is **In Transit** can be corrected in the normal Edit form (e.g. a wrong phone number) without changing its status. | `JobService::update` |
| C9 | Search looks in job #, customer name, from, to, job location, carrier, driver and phone as typed; every word must match somewhere. Sorting and filtering by money are only available to users who may see money. Filters on the Delivered section use the delivery date; elsewhere the 1st available date. | `JobCriteria`, `JobRepository` |
| C10 | Dashboard money: **Outstanding balance** = balance of all jobs except cancelled. **Storage fees** = storage fee of jobs that are booked, in transit or on hold. Both are hidden from users without financial permission, and a user without `jobs.view` sees no job figures on the dashboard at all. | `JobRepository::moneyTotals`, `DashboardService` |
| C11 | List rows have one button: **Move to In Transit** (booked) or **Mark as Delivered** (in transit). It acts at once, after a confirmation that names the date that will be saved. There is no "undo" toast; reversing a status change is done with the reopen / resume actions. | `JobListController` |

## User and role administration decisions (Phase 4)

| # | Decision | Where to change |
|---|---|---|
| D1 | **Temporary passwords.** New users and admin password resets get a generated 16-character password that is shown **once** on screen, stored only as a hash, flagged "must change at next sign-in", and never written to the audit log or any log file. There is no email delivery in the demo, so the administrator passes it on in person or over a private channel. | `UserService`, `flash.php` |
| D2 | **Resetting a password ends that user's open sessions** immediately and unlocks the account. An administrator cannot reset their own password this way; My Account asks for the current password. | `UserService::resetPassword` |
| D3 | **Least privilege.** You can only assign a role, edit a user, reset a password, deactivate, unlock or delete a user whose permissions you hold **all** of yourself. A user with `users.manage` but not the Manager permissions cannot make anyone a Manager; a Manager-level user cannot touch an Admin. In the permission matrix you can only tick or untick permissions you hold yourself. | `AdminGuard` |
| D4 | **Never zero administrators.** At least one active person must hold both `users.manage` and `roles.manage`; any change (role change, deactivate, delete, matrix save) that would break this is rolled back. | `AdminGuard`, `UserRepository::countAdministrators` |
| D5 | **Self-protection.** You cannot change your own role, deactivate yourself, delete yourself or reset your own password through user administration. | `UserService` |
| D6 | **Soft-deleted users** keep their rows (jobs and history still point at them) and their **username (and e-mail, if any) stay reserved**. There is no "restore user" screen in the demo; an administrator can re-create access with a new account on a different email. | `UserRepository::softDelete` |
| D7 | **Roles.** The built-in **Admin** role cannot be renamed or deleted and always holds every permission (re-synced on every seed); other roles can be created (optionally as a copy), renamed and deleted. A role cannot be deleted while any user, including a deleted one, still references it. Names are unique regardless of case. | `RoleService`, `Seeder` |
| D8 | **Permission matrix.** One page, roles as columns, permissions as rows grouped by area. If someone else changes permissions while you have the page open, your save is refused and you see the current state. Changes take effect on each user's next request, without signing out. Every change is audited with what was added and removed. | `RoleService::saveMatrix` |
| D9 | **`users.manage` and `roles.manage` are powerful by nature.** Whoever holds `roles.manage` can grant themselves any permission they already hold and edit other roles within that limit, and `users.manage` can create accounts up to their own level. Give them only to people you trust with administration. | Permissions |
| D10 | A user may change only their own **name** (My Account). Username, e-mail and role are changed by an administrator. | `AccountController` |

## Phase 5 - import and export

| # | Decision | Where |
|---|---|---|
| E1 | **Two-step import.** Upload -> preview (counts, per-row reasons, column mapping, warnings) -> explicit confirm. Nothing is saved before confirm; the stored file is re-validated at confirm and deleted afterwards. A preview expires after **24 hours** and is visible only to the user who uploaded it. | `ImportService` |
| E2 | **Limits:** 5 MB, 5,000 data rows, 60 columns, and never more than PHP's `upload_max_filesize` / `post_max_size` (the CLI default is only 2 MB: raise both in php.ini for real use). A request over `post_max_size` gets a clear 413 page. | `ImportService`, `CsrfMiddleware` |
| E3 | **Formats:** CSV (UTF-8, UTF-8 BOM, UTF-16, Windows-1252; comma, semicolon, tab or pipe separators sniffed) and Excel `.xlsx` (first sheet). Legacy `.xls` is not supported. The XLSX reader/writer is native (needs `ext-zip`), with zip-bomb and XXE defences, so no Composer dependency. Generated workbooks were checked for valid XML and read back; they have not been opened in Microsoft Excel itself. | `app/Services/Import/` |
| E4 | **Files are judged by content, not extension** (finfo MIME + zip signature); files are stored under random names with mode 0600 outside the web root. | `ImportService::stage` |
| E5 | **Headings are matched leniently** (case, spacing, punctuation and the client's own typos in the sample sheet). Unknown columns are ignored and listed; the first column claiming a field wins. | `ColumnMapper`, `JobFields` |
| E6 | **Dates:** ISO `2026-03-31` and US `3/31/2026` are accepted; `d/m/Y` only when unambiguous (day > 12); ambiguous values such as `04/05/2026` are read as month/day. Excel serial dates are understood in spreadsheets. | `ValueParser` |
| E7 | **Duplicates:** a job number that already exists (case-insensitive) or appears twice in the file is a problem row and is skipped, never overwritten. Import only creates; it does not update. | `ImportService::analyse` |
| E8 | **Permission-aware:** balance and storage fee are imported only with `jobs.edit_financials`; status, carrier, driver, phone and delivered date only with `jobs.status`. Otherwise those columns are ignored with a visible warning. Import also writes a history row for jobs created beyond Booked. | `ImportService` |
| E9 | **Spreadsheet-formula safety:** exported cells starting with `= + - @`, tab or CR get a leading apostrophe; the same prefix is stripped on import so round trips are stable. Job numbers starting with such characters are refused on import. | `ExportService::safeCell` |
| E10 | **Export** follows the current section, search, filters and sort; requires `jobs.export` and `jobs.view`; money columns are left out for users without financial access. Streamed in chunks; caps: CSV 50,000 rows, Excel 20,000. Every export is audited. | `ExportService` |

## Phase 7 - audit, settings and hardening

| # | Decision | Where |
|---|---|---|
| F1 | **Settings page** (`settings.manage`, Admin only by default) edits five values: company name (up to 60 characters), ready-for-delivery limit (whole days, 1-365, default 30), currency symbol (1-3 characters), rows per page (10, 25, 50 or 100) and the business time zone. Session length, lockout and password rules stay in `.env` so a wrong setting cannot lock everyone out. Storage fees remain manually editable per job. | `SettingsService`, `SettingsController` |
| F2 | **Changing the day limit re-sorts jobs immediately and without touching job data**, because sections are computed from the status and the 1st available date (assumption A2). | `JobBucket` |
| F3 | **Audit log viewer** (`audit.view`, Admin only by default; a role can hold it without any other administration right). Read-only: the application has no way to edit or delete entries. Filters: action, record type, user, date range (typed in the business time zone), single record. 50 entries per page, newest first. | `AuditController`, `AuditCriteria` |
| F4 | **What is recorded:** sign-ins and failures, lockouts, denied access, job create/edit/status/delete/restore, import/export, user, role and permission changes, settings changes, password changes, maintenance. Page views are not recorded. Passwords, tokens and secrets are redacted before storage. Entries hold names, e-mail addresses and IP addresses and are **kept indefinitely** unless an administrator runs `cli/cleanup.php --audit-days=N` (minimum 365). | `AuditService`, `MaintenanceService` |
| F5 | **Wrong "current password" guesses on My Account count towards the sign-in lockout** (5 failures, 15 minutes). On lock the session ends. | `AuthService::confirmPassword` |
| F6 | **Housekeeping** (`cli/cleanup.php`, dry run unless `--apply`): sign-in attempts older than 30 days, import previews older than 24 hours (file deleted), finished import records older than 90 days, unreferenced files in `storage/imports` older than an hour, log files older than 60 days. | `MaintenanceService` |
| F7 | **Self checks:** `cli/route-audit.php` (access rules on every route, no state change over GET) and `cli/doctor.php` (PHP, extensions, production settings, permissions, migrations, demo accounts). Both exit 1 on a failure so they can run in a deployment script. | `RouteAudit`, `EnvironmentCheck` |

## Phase 8 - testing

| # | Decision | Where |
|---|---|---|
| G1 | **Money is a plain amount:** digits with at most two decimals (`1200`, `1200.5`, `.5`). Three decimals, exponents (`1e3`), signs and symbols are refused rather than rounded silently. Import still understands `$1,200.50` (it strips the symbol and thousands commas first) but also refuses a third decimal. | `Validator` rule `money`, `JobRules` |
| G2 | **Export caps and import limits are limits of the demo,** not of the design: 50,000 rows CSV, 20,000 Excel, 5,000 rows and 5 MB per import. Bigger files are refused with a message. | `ExportService`, `ImportService` |
| G3 | **Job numbers compare case-insensitively in production** (MySQL's default collation, and explicitly in the importer). The SQLite used by the tests compares case-sensitively, so a test cannot prove this part. | `JobRules`, migrations |
| G4 | **UAT is done on a test copy** with demo data. `tests/all.sh` creates its own throw-away database and never touches yours. | `tests/all.sh` |

## Phase 9 - deployment

| # | Decision | Where |
|---|---|---|
| H1 | **Target:** a Linux server with Apache 2.4 or Nginx, PHP-FPM 8.1+, MySQL 8 / MariaDB 10.5+, HTTPS. Shared hosting works if the document root can point at `public/`. | `docs/deployment.md`, `deploy/` |
| H2 | **Backups** are database dumps (`cli/backup.php`, newest 14 kept, mode 0600) that the client must copy **off the server**; imported files are not kept after import, so the database is the only data to back up. | `BackupService` |
| H3 | **Forgotten administrator password** is recovered from the server with `cli/reset-password.php` (needs shell access). There is no e-mail "forgot password" flow in v1.0 because no e-mail service is assumed. | `cli/reset-password.php` |
| H4 | **Releases** are a tarball built by `scripts/build-release.sh` (no tests, no `.env`, no databases; Bootstrap and fonts bundled). Version number in `VERSION`, history in `CHANGELOG.md`. Rollback is switching back to the previous folder; migrations only add. | `scripts/`, `tests/release-check.sh` |
| H5 | **MySQL is unverified** in this build (no server was available). `docs/deployment.md` step 9 is the first-day check on staging. | `docs/handover.md` |

## Username sign-in (version 1.0.1)

| # | Decision | Where |
|---|---|---|
| I1 | **People sign in with a username, not an e-mail address.** A username is 3 to 40 characters: lower-case letters, numbers, dot, dash, underscore, starting with a letter or number. It is stored lower-case and typed in any case. The e-mail address is optional contact information (any number of users may have none) and cannot be used to sign in. | `users.username`, `Validator` rules `lower` and `username` |
| I2 | **Usernames are changed by administrators only,** like e-mail and role. Failed sign-ins are recorded in the audit log by the username that was typed. The lockout and throttling work as before, per username and per IP address. | `UserService`, `AuthService` |
| I3 | **Fresh installs only.** The users table definition changed in place (migration 002) because no installation held data yet. An existing development database must be re-created (`migrate` on an empty database, then `create-admin`). | `database/migrations/002` |

## Client change request (version 1.1.0)

| # | Decision | Where |
|---|---|---|
| J1 | **Automatic storage fee = CF × rate**, default rate **0.6** (CF 100 → 60.00). It is charged **once, not per day**. Rounded to 2 decimals. Rate, days and counting basis are on the **Settings** page; changing any of them re-assesses every open job at once. | `StorageFeeService` |
| J2 | **When does the fee start?** The brief says "after 30 days" without saying 30 days *from what*. **Assumption (please confirm):** from the job's **1st available date**, so a job still waiting more than 30 days after the date the customer could take delivery owes the fee from **day 31** (day 30 is free, like the 30-day limit of the Ready list). Settings can count from the **loaded date** instead. | `settings.storage_fee_basis`, `storage_fee_after_days` |
| J3 | **Frozen at delivery:** a delivered job keeps the fee it had on its delivery date, at the rate used then (a later rate change never rewrites history). Cancelled jobs owe nothing. Reopening a delivered job makes it an open job again. A job with no CF owes nothing. A fee typed on the job (**Storage fee override**, needs `jobs.edit_financials`, 0 = waived) always wins and is never recalculated; an old manually entered fee became an override when the database was upgraded. | `JobService`, migration 008 |
| J4 | **Move to In Transit is one click** (no dialog): status In Transit and **Loaded date = today** in the business time zone (Settings). Carrier, driver and driver phone are now **optional** and can be added under Edit while the job is in transit. A confirmation ("Today (date) will be saved as the loaded date") guards against stray clicks. | `JobWorkflowService`, `views/jobs/_quick_move.php` |
| J5 | **Mark as Delivered is one click** on the In Transit list or Job Details: status Delivered and **Delivery date = today**. The loaded date is kept. | `JobWorkflowService` |
| J6 | **Dates can be corrected** under Edit (loaded date for In Transit and Delivered jobs, delivery date for Delivered ones): not in the future, delivery not before loading, delivery date required on a delivered job. Corrections are audited. Going back to Booked clears both dates (kept in the history). | `JobRules::dateRules` |
| J7 | **Customer phone** is an optional field on Add/Edit Job (7-25 characters of digits, `+ ( ) - . space`), shown as a tap-to-call link on Job Details and under the customer name in every list, searchable, exported and importable (column "Customer Phone"; the plain "Phone" column still means the driver). | `jobs.customer_phone` |
| J8 | **Fees stay current without cron:** the first page view of each business day re-assesses open jobs; `php cli/storage-fees.php` does the same from cron (deploy/cron.example). The recalculation does not change a job's "last updated" time, so an open edit form is never made stale. | `StorageFeeService::refreshIfDue` |
| J9 | **Upgrading:** `php cli/migrate.php` applies migration 008: new columns, then dates are back-filled from the old dispatch/delivery times and manually entered fees become overrides. Nothing entered before is lost. | `database/migrations/008_...` |

The generated role x route matrix is in `docs/permission-matrix.md` (`php cli/permission-report.php`).

## Security defaults (can be tuned in `app/Config/app.php` / `.env`)

* Sessions end after **30 minutes idle** or **8 hours** in total.
* An account locks for **15 minutes** after **5** failed sign-ins; an IP address is throttled after **20** failures in 15 minutes.
* Passwords must be at least **10 characters** and are checked against a list of common passwords.
* No default or demo credentials ship with the application. The first Admin is created with `php cli/create-admin.php`.
