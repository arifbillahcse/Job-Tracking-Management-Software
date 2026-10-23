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
| A7 | **Storage Fee is a manually edited field.** There is no automatic calculation until the client defines the rule. | Field on the job form |
| A8 | **Balance** is the amount the customer still owes; a single number, no payment ledger. | Schema comment (Phase 2) |
| A9 | **CF** means cubic feet: an optional decimal number. | Label only |
| A10 | **Job #** is typed in by staff and must be unique. Auto-numbering is deferred. | Validation rule (Phase 2) |
| A11 | Moving a job **In Transit** requires carrier name, driver name and phone number. | Validation rule (Phase 3) |
| A12 | One company, one time zone, one currency symbol, English only. Times are stored in UTC. | `settings` table |
| A13 | Three seeded roles: **Admin** (every permission, cannot be deleted), **Manager** (everything except administration), **Staff** (no financial figures, no deletion, no import). | `app/Config/permissions.php` |
| A14 | No self-service password reset by email and no "remember me". An Admin resets passwords. Accounts can be flagged to force a password change at next sign-in. | Deferred |
| A15 | Excel (.xlsx) import/export is optional; CSV is the baseline. | Phase 5 |

## Security defaults (can be tuned in `app/Config/app.php` / `.env`)

* Sessions end after **30 minutes idle** or **8 hours** in total.
* An account locks for **15 minutes** after **5** failed sign-ins; an IP address is throttled after **20** failures in 15 minutes.
* Passwords must be at least **10 characters** and are checked against a list of common passwords.
* No default or demo credentials ship with the application. The first Admin is created with `php cli/create-admin.php`.
