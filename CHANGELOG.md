# Changelog

Versions follow `MAJOR.MINOR.PATCH`. The number lives in the `VERSION` file and is shown by `php cli/doctor.php`.

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
