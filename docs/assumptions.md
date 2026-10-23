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
| C6 | **Dispatching** needs carrier name, driver name and a valid phone number. **Delivering** needs a delivery date that is not in the future (stored as a date; the time of day is not tracked). | `JobRules::dispatchRules`, `JobWorkflowService` |
| C7 | Status and its history row are saved in one transaction. If another user moved the job first, the second change is refused with a message instead of overwriting. | `JobWorkflowService`, `JobRepository::changeStatus` |
| C8 | The carrier details of a job that is **In Transit** can be corrected in the normal Edit form (e.g. a wrong phone number) without changing its status. | `JobService::update` |
| C9 | Search looks in job #, customer name, from, to, job location, carrier, driver and phone as typed; every word must match somewhere. Sorting and filtering by money are only available to users who may see money. Filters on the Delivered section use the delivery date; elsewhere the 1st available date. | `JobCriteria`, `JobRepository` |
| C10 | Dashboard money: **Outstanding balance** = balance of all jobs except cancelled. **Storage fees** = storage fee of jobs that are booked, in transit or on hold. Both are hidden from users without financial permission, and a user without `jobs.view` sees no job figures on the dashboard at all. | `JobRepository::moneyTotals`, `DashboardService` |
| C11 | List rows have one shortcut: **Dispatch** (booked) or **Mark delivered** (in transit). It opens the job with the right dialog already open. There is no "undo" toast; reversing a status change is done with the reopen / resume actions. | `JobListController` |

## Security defaults (can be tuned in `app/Config/app.php` / `.env`)

* Sessions end after **30 minutes idle** or **8 hours** in total.
* An account locks for **15 minutes** after **5** failed sign-ins; an IP address is throttled after **20** failures in 15 minutes.
* Passwords must be at least **10 characters** and are checked against a list of common passwords.
* No default or demo credentials ship with the application. The first Admin is created with `php cli/create-admin.php`.
