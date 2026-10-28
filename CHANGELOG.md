# Changelog

Versions follow `MAJOR.MINOR.PATCH`. The number lives in the `VERSION` file and is shown by `php cli/doctor.php`.

## 1.2.2 - trips connected to jobs and the dashboard (roadmap phase 8)

* **Job Details has a "Foreman trips" card** (for people who may view trips): the trips the job is on, linked, with the foreman and date. People who may manage trips can **add the job to an existing trip** from a drop-down (trips that already carry it are not offered) or start **a new trip with this job** already in the first row. A job added this way gets no expenses; enter them on the trip.
* **Dashboard card "Foreman trips, last 30 days"**: trips, job lines and (with `trips.financials`) the expenses, linking to the foreman report.
* A trip still shows a job that was later deleted, marked "job deleted" and without a dead link. Deleting a job never deletes trip rows.
* Adding a job to a trip counts as a change to the trip, so an edit form opened before it shows the "someone else changed this" warning, and it is audited.
* **Upgrade:** upload the new files and run `php cli/doctor.php`. No migration, no new permission (adding uses `trips.manage`).

## 1.2.1 - foreman report and CSV export of trips (roadmap phase 7)

* **Trips by foreman** report (Trips -> By foreman): one line per foreman with trips, jobs, each of the six expense columns and Total expenses, plus a grand total, for trips beginning in a chosen period (and optionally one foreman). Counts only for users without `trips.financials`.
* **Export CSV** of the trip list you are looking at (same search and filters): one line per job row with the trip details repeated, the client sheet's columns (Trip Number, Foreman, Trip Begins/Ends, Collected By, Job #, Customer Name, Date, From, BH, Diesel ... Extra, Remark). The report exports to CSV too. UTF-8 with BOM for Excel; cells that look like formulas are neutralised; expense columns only for users with `trips.financials`; every export is audited. Up to 50,000 lines.
* New permission **`trips.export`** ("Export trips and the foreman report to CSV"): Admin has it; Manager on a new install. Seeing the report needs only `trips.view`.
* **Upgrade:** upload the new files, then `php cli/seed.php` (adds the permission to Admin) and `php cli/doctor.php`. No migration. On an existing install tick **Export trips and the foreman report to CSV** for Manager under Roles & Permissions.

## 1.2.0 - foreman trips and expenses (roadmap phases 5 and 6)

* **New module: Foremen & Trips.** Add foremen, then record each trip: trip number, foreman, trip begins/ends, collected by, notes, and the **jobs picked up on it**. For each job row: date, BH, **Diesel, Hotels, Helper, Misc, Commission, Extra** and a remark (the sheet's columns). Job rows are matched to real jobs by job number; the customer name and "from" are filled in from the job.
* **Amounts are stored exactly as typed.** The only calculation is the column totals and **Total expenses** (the sum of the six expense columns), done in whole cents (assumption T1). The sheet's Grand Total, BH Total, Paid and Balance stay on the job (they are not repeated on the trip).
* Trip list with search (trip #, foreman, collected by, job # or customer), foreman and date filters, paging; trip page with totals; edit with a "someone else changed this" warning; soft delete. The foremen page shows each foreman's trip count and total expenses. Foremen are deactivated, never deleted.
* **Permissions** (new): `trips.view`, `trips.manage`, `trips.financials` (see/edit the expense amounts), `foremen.manage`. Admin has all four; Manager has all four on a new install; Staff none. Without `trips.financials` nobody sees the amounts, and saving a trip never wipes expenses they cannot see.
* All changes are audited (trip created / updated / deleted, foreman created / updated / activated / deactivated).
* **Upgrade:** upload the new files, then `php cli/migrate.php` (migration 010 adds three tables, nothing existing changes), `php cli/seed.php` (adds the permissions to Admin) and `php cli/doctor.php`. On an existing install tick the four new permissions for Manager under Roles & Permissions.
* Not built yet (roadmap phase 7+): foreman-wise reports and CSV export of trips, trips on the Job Details page.

## 1.1.6 - Storage Fee column in every list; storage fee permission (roadmap phase 4)

* **A-CF and Storage fee columns** are now in All Jobs, Ready for Delivery, More Than 30 Days, In Transit and Delivered (the fee only for users who may see financials). Every export has a Storage Fee column too (same rule).
* New permission **`settings.storage_fee`** ("Change the storage fee rate and rules"): a person with it gets a **Storage Fee** page with only the rate, days, counting basis and month rule, without the rest of Settings. Admin has it; **Manager has it by default on a new install**. Changes are audited and open jobs are re-calculated at once.
* **Upgrade:** upload the new files, then `php cli/seed.php` (adds the permission and gives it to Admin). On an existing install tick **Change the storage fee rate and rules** for Manager under Roles & Permissions if you want managers to have it. No migration.
* The sheet's *Balance Due* column is not added: how it differs from Balance is not defined (assumption M3).

## 1.1.5 - storage fee counted from the pickup date, charged every month (roadmap phase 3)

* **Storage fee = A-CF x rate x months** (client sheet: "A-CF @0.6 after 30 days from the pick up date and times whatever months stay, no prorating"). Month 1 starts on day 31 after the **pickup date**; each further 30 days adds one more month; part of a month counts as a whole month. Example: A-CF 1000 x 0.6 = 600 for days 31-60, 1200 for days 61-90.
* New setting **Charged: every month / once** (default every month). *Once* is the earlier behaviour (a single CF x rate).
* **Counted from** now offers the pickup date (default for new installs; a job with no pickup date uses its 1st available date), the 1st available date, or the loaded date.
* A fee typed by hand on a job (override) is **never** recalculated. A delivered job keeps the months and rate it had on its delivery day.
* **Upgrade:** upload the new files, then `php cli/seed.php` (adds the new setting, default every month) and `php cli/doctor.php`. No migration. **Seeding turns on monthly charging**, so open jobs older than 60 days will show a larger fee after the next daily refresh. If you installed an earlier version, open **Settings** and set *Counted from* to **Pickup date** (a saved setting is never overwritten by seeding).

## 1.1.4 - the client's sheet fields on a job (roadmap phase 2)

* New job fields from the client's spreadsheet: **E-CF** (estimated cubic feet), **E-Total**, **Deposit**, **Grand total**, **BH total**, **Paid**, **Foreman**, **Job lot** and **Color**. The existing CF is now labelled **A-CF** (actual CF); the storage fee is calculated from it.
* The money fields (E-Total, Deposit, Grand total, BH total, Paid) are visible and editable only with the same financial permissions as Balance. E-CF, Foreman, Job lot and Color are ordinary fields.
* **They are stored exactly as typed. No formula is applied** (the sheet's Grand total, BH total and Balance relationships are not confirmed; see assumption M2).
* Add/Edit Job, Job Details, search (foreman, job lot), CSV/Excel export and import all carry the new columns. The import reads the client's own headings (`Forman`, `A-CF`, `E-Total`, `Depo`, ...).
* **Upgrade:** upload the new files, then `php cli/migrate.php` (migration 009 only adds empty columns; nothing existing changes) and `php cli/doctor.php`.

## 1.1.3 - section rule default set back to the 1st available date

* The client's sheet says a job is ready for delivery when its **1st available date** is within 30 days, and that the **storage fee** counts 30 days from the **pickup date**. So the default of *Count the days from* is the **1st available date** again; the Pickup date choice stays available in Settings.
* **If you already ran 1.1.2:** open Settings, set *Count the days from* to **1st available date**, Save. (Seeding never overwrites a saved setting.)
* The storage fee moving to the pickup date, charged per month, comes in the next phase. No migration.

## 1.1.2 - sections count from the Pickup Date (roadmap phase 1)

* **More Than 30 Days is now counted from the Pickup Date.** A booked job picked up more than 30 days ago is in More Than 30 Days; picked up within 30 days (or not picked up yet) is Ready for Delivery. A job with no pickup date uses its 1st available date, and the list says so. Assumption L1.
* New setting **Count the days from** (Pickup date, default / 1st available date). Choosing the 1st available date restores the earlier rule with no data change; switching back and forth only changes how jobs are sorted into sections.
* The storage fee calculation is **not changed in this release** (still counted from the 1st available date by default); it moves to the pickup date in the next phase.
* **Upgrade:** upload the new files, then `php cli/seed.php` (adds the new setting, default Pickup date) and `php cli/doctor.php`. No migration.

## 1.1.1 - overdue jobs go to More Than 30 Days; storage rate in cents

* A booked job whose 1st available date is **more than 30 days in the past** now shows in **More Than 30 Days** (not Ready for Delivery). These are the jobs that carry the automatic storage fee (CF × 0.6 by default). Ready for Delivery = within 30 days either side of today. The dashboard Overdue panel still lists every overdue job. *Interpretation of the client's note, to be confirmed (assumption K1).*
* The storage fee rate can be typed in dollars (`0.6`) or cents (`60c`, `60 cents`, `60¢`) on the Settings page.
* **Upgrade:** upload the new files; no migration. Run `php cli/doctor.php`.

## 1.1.0 - client change request: one-click moves, automatic storage fee, customer phone

* **Move to In Transit** and **Mark as Delivered** are one-click buttons on every list row and on Job Details. Today's date is saved as the **Loaded date** / **Delivery date**. No dialog; a short confirmation guards against stray clicks. Carrier and driver details are now optional.
* **Automatic storage fee = CF × rate** (default 0.6) once a job has waited more than 30 days. Rate, number of days and the date it is counted from are on the Settings page. A fee typed on a job is kept as an override. Delivered jobs keep the fee they had on delivery.
* **Customer phone number** on Add/Edit Job, shown on Job Details and in the lists as a tap-to-call link, searchable, exported and importable.
* Loaded and delivery dates can be corrected under Edit. Lists, exports and imports use them.
* Actions column stays pinned at the right of wide lists so the one-click buttons are always visible; In Transit list compacted.
* **Upgrade:** upload the new files, then `php cli/migrate.php` (migration 008: new columns, back-filled dates, hand-typed storage fees become overrides) and `php cli/seed.php` (adds the three new settings). Optional cron line for `php cli/storage-fees.php`.

## 1.0.1 - sign in with a username

* People now sign in with a **username** instead of an e-mail address. E-mail is optional contact information.
* `create-admin`, `create-user` and `reset-password` take `--username=`.
* **Fresh installs only:** the users table definition changed in place. An existing development database must be re-created.
* Command-line tools print a readable message (and a hint when `.env` is missing or the database settings are wrong) instead of an HTML error page.
* The root `.htaccess` now serves from `public/` on hosts where the document root cannot be changed (cPanel sub-folder), while still refusing every private folder.
* New: `cli/backup.php`, `cli/reset-password.php`, deployment templates, release builder (see 1.0.0 notes for the rest of the handover pack).

## 1.0.0 - first complete release (demo handed to the client)

Built in nine phases; each phase is one commit in the history.

* **Foundation and security:** Core PHP 8.1+ / MySQL / Bootstrap 5.3 (no framework), secure sessions, CSRF protection, Argon2id passwords, sign-in lockout, role-based permissions with deny-by-default routing, strict security headers.
* **Jobs:** create, edit, soft-delete and restore; search, filter and sort; the client's fields.
* **Sections and workflow:** Ready for Delivery / More Than 30 Days computed automatically from the 1st available date (limit configurable, default 30), In Transit, Delivered, dashboard, status workflow with history.
* **Administration:** users, roles and a permission matrix, with least-privilege and last-administrator safeguards.
* **Import and export:** CSV and Excel import with preview, validation, duplicate detection and a problem report; filtered CSV and Excel export.
* **UI polish and accessibility:** responsive layouts, WCAG AA text contrast, keyboard and screen-reader support, print styles.
* **Audit and settings:** read-only audit log, settings page, housekeeping, route audit and environment self-check tools, security review.
* **Testing:** 1,300+ automated checks, scale test, browser walkthrough, UAT pack, requirements traceability.
* **Deployment and handover:** Apache and Nginx configurations, backups, password recovery tool, release builder, operations, administrator and user guides.

Known limits are listed in `docs/handover.md`.
