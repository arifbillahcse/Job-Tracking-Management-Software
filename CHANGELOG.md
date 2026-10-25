# Changelog

Versions follow `MAJOR.MINOR.PATCH`. The number lives in the `VERSION` file and is shown by `php cli/doctor.php`.

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
