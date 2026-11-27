# Changelog

Versions follow `MAJOR.MINOR.PATCH`. The number lives in the `VERSION` file and is shown by `php cli/doctor.php`.

## 1.18.0 - companies (phase 1)

* **New menu item Companies (Jobs group).** A company has a name, address, city, phone, **DOT number** and **MC number** (all but the name optional). **A company is the same as a job's Broker**: they are matched by name, ignoring letter case, so nothing changes on the jobs you already have, and a broker with no record here simply has no details.
* **The Companies page** lists the companies with how many jobs carry each name, has a search (name, city, address, DOT or MC), and lists the **brokers that are on jobs but have no details yet**, each with an "Add details" button that opens the form with the name filled in.
* **Renaming:** changing a company's name can change the Broker on its jobs too (a box, ticked by default, says how many). Removing a company only removes its details; its jobs and invoices keep the broker name.
* **The details are printed:** a company statement shows the company's address, DOT / MC and phone under its name, and a **company invoice (CI)** shows them too. They are **copied onto the invoice when it is saved**, so changing an address later never changes an invoice that was already given out.
* **The Broker box on the job form** now also offers the companies you added (each name once).
* **New permission `companies.manage`** (Admin and Manager hold it by default). **Migration 023** adds the `companies` table and one column on invoices.
* **Upgrade:** `git pull origin main`, `php cli/migrate.php`, `php cli/seed.php`, `php cli/sync-roles.php --apply`, `php cli/verify.php`, then Ctrl+F5 and log out / in.

## 1.17.1 - polish and a safer check (stabilise)

* **Amounts no longer break across two lines** in the statement and invoice tables on a phone ("$2,500.0" / "0"). The table now scrolls inside its box instead.
* **Header wording:** `Total due: $5,200.00 — Carrier owes company (not final)` instead of brackets inside brackets with the amount twice.
* **The "Paid" box on the statement and on a saved invoice is now "Paid / adjustment"**, with the explanation: a plus adds to Total Due; money that was already paid is typed as a minus (-100), which takes it off. (The sums are unchanged.)
* **`php cli/verify.php` and `php cli/doctor.php` now also check the statement and the invoices:** the two invoice tables with all their columns, and every statement / invoice / search query, against the real database. Run `php cli/verify.php` after upgrading and paste the output if a line says FAIL.
* No migration. **Upgrade:** `git pull origin main`, then Ctrl+F5, then `php cli/verify.php`.

## 1.17.0 - company invoices (invoice phase 4b)

* **Company invoices (CI-1, CI-2, ...).** On the Carrier Statement, choose just a **Company** (the job's broker) and the "Save as an invoice" box makes a **company invoice**. The hishab is the same as a driver invoice (Bal. Due total - CF Pay total - Labor - Pads + Paid). A statement with a carrier or a driver is still saved as a driver invoice (DI), the company then only narrows it down.
* **Generate company invoices from a driver invoice.** On a driver invoice (DI) there is a "Generate company invoices" button: it splits the jobs saved on that invoice by company and makes one company invoice for each, from the **saved lines** (the jobs are not read again). Remarks, date and carrier are copied; **Labor, Pads and Paid start at 0 on each company invoice** (copying them to every one would count the same cost several times) and can be changed on the invoice. Jobs with no company are left out and the page says how many. A generated company invoice says "Made from DI-3", and the driver invoice lists its company invoices.
* **It cannot be done twice by accident:** while any company invoice made from a driver invoice is open (not voided), generating again is refused; void them first to make them again (with new numbers).
* **A job can be on one open driver invoice and one open company invoice** (they are different documents), but not on two of the same kind. The statement marks a job with every number it is on ("On DI-1, CI-1").
* The Invoices list has a **Type filter** (Driver Invoice / Company Invoice) and a Type column; searching "CI-3" or a company name works.
* **Migration 022** adds one column (`source_invoice_id`). **Upgrade:** `git pull origin main`, `php cli/migrate.php`, `php cli/doctor.php`, then Ctrl+F5. No new permissions.

## 1.16.0 - saved invoices (invoice phase 4a)

* **A Carrier Statement can now be saved as a numbered invoice (DI-1, DI-2, ...).** Under the statement table there is a "Save as an invoice" box (for a carrier or a driver; an invoice for a whole company comes next). It keeps the statement exactly as it is that day: each job with its CF, rate, CF Pay, balance, carrier, driver and remarks, plus the Labor, Pads and Paid you typed, and the total. **Editing a job later never changes a saved invoice.**
* **A job is on one open invoice at a time.** The statement marks jobs that are already on an invoice ("On DI-3"). Saving leaves those out unless you tick "Also include jobs already on an invoice", so a job cannot be billed twice by accident. A statement with a job that has no rate or no A-CF cannot be saved (enter them on the job first), because an invoice must not hold an unknown CF Pay.
* **New menu item Invoices:** the list (search by number, carrier, driver or company; filter Open / Paid / Void and dates; totals of what is shown and what is still open), and each invoice as a printable page with the company header, the lines, the summary, who owes whom, Remarks and Signature.
* **On an invoice:** change the date, remarks, Labor, Pads and Paid (the total is worked out again; the saved jobs stay as they were), **mark it paid** on a date (and back to not paid), or **void** it with a reason (it stays on file, its number is never used again, and its jobs can be invoiced again). A paid invoice must be marked not paid before it can be voided.
* **Two new permissions:** `invoices.view` and `invoices.manage` (Admin and Manager hold both by default). **Migration 021** adds two tables (`invoices`, `invoice_lines`); no existing data is touched.
* **Upgrade:** `git pull origin main`, `php cli/migrate.php`, `php cli/seed.php`, `php cli/sync-roles.php --apply` (gives Manager the two new permissions), `php cli/doctor.php`, then Ctrl+F5.

## 1.15.1 - the statement says who owes whom

* **A line under TOTAL DUE now says who pays whom:** "Carrier owes company $25.74" when Total Due is positive, "Company owes carrier $3,251.22" when it is negative (shown without the minus), and "Settled, nothing due" at zero. It also appears next to the total in the header and in the CSV.
* When a job on the statement has no rate or no A-CF, the line ends with "(not final)".
* No hishab changed. No migration. **Upgrade:** `git pull origin main`, then Ctrl+F5.

## 1.15.0 - Labor, Pads and Paid on the statement (print phase 3)

* **Three optional boxes on the Carrier Statement: Labor cost, Pads and Paid** (all default to 0, nothing is stored). **Total Due = Subtotal - CF Pay - Labor - Pads + Paid**, the same as the old application. A minus sign is allowed: a negative Paid (like -100) takes money off Total Due.
* A line for each appears in the summary box only when it is not 0, so a statement without them looks exactly as before. The CSV has the same lines and the new Total Due, and the CSV link keeps what was typed.
* No migration. **Upgrade:** `git pull origin main`, then Ctrl+F5.

## 1.14.0 - a statement by company (print phase 2)

* **New "Company" choice on the Carrier Statement.** The company is the job's **Broker**. Pick a company alone (a "Company Statement" with every carrier and driver that took its jobs) or together with a carrier and/or a driver. The hishab is the same: Bal. Due total minus CF Pay total.
* When the statement does not name both a carrier and a driver, each row has a **Carrier / Driver** column saying who took the job.
* The header names the company ("Company: Prime") and the CSV has three new columns at the end: Company, Carrier, Driver.
* Choosing a carrier, a driver or a company is now enough (at least one). Carrier, driver and company are matched ignoring letter case.
* No migration. **Upgrade:** `git pull origin main`, then Ctrl+F5.

## 1.13.0 - a printable statement (print phase 1)

* **The Carrier Statement now has a proper header**: the company name and (when filled in) its address, DOT and MC number and phone on the left; on the right the title ("Driver Statement" when a driver is chosen, else "Carrier Statement"), the driver or carrier, the driver's phone (taken from the jobs), the date and the period. Below the table there is a **Remarks** and **Signature** line. The filter box and buttons are left out when printing.
* **New "Company details on printed statements" box in Settings:** Address, Phone, DOT number and MC number, all optional (an empty line is not printed).
* **Logo:** put your logo file at `public/assets/img/company-logo.png` and the header shows it; without the file nothing changes.
* No migration. **Upgrade:** `git pull origin main`, then Ctrl+F5. Then fill in the company details in Settings and use the Print button.

## 1.12.1 - the Carrier Statement uses each job's own rate (rate phase 2)

* **The statement no longer asks for one rate.** Each row shows the job's own **carrier rate**, and **CF Pay = that rate x the job's actual CF**. Two jobs of one carrier can have different rates. Total Due is still the Bal. Due total minus the CF Pay total.
* A job with **no rate** or **no actual CF** shows a dash for CF Pay and counts $0; a yellow notice says how many jobs lack a rate and how many lack an A-CF, and that Total Due is not final until they are entered on the job.
* The CSV has the per-row rate. The rate is typed on the job (Edit > Cargo & money > Carrier rate).
* No migration. **Upgrade:** `git pull origin main`, then Ctrl+F5.

## 1.12.0 - a carrier rate on each job (rate phase 1)

* **New field "Carrier rate (per CF)" on every job**, in the Cargo & money box of the job form and on the job page (only for people who may see money). It is the rate paid to the carrier, in dollars per cubic foot, like 2.50, set separately on each job. Empty means no rate yet. This phase only stores it; the Carrier Statement uses it in the next version.
* **Migration 020** adds one column (`carrier_rate`); existing jobs get no rate. **Upgrade:** `git pull origin main`, `php cli/migrate.php`, `php cli/doctor.php`, then Ctrl+F5.

## 1.11.1 - wording

* The help text under Carriers in Settings now says a carrier is needed to move a job to Stage (or straight to In Transit). No other change, no migration. **Upgrade:** `git pull origin main`, then Ctrl+F5.

## 1.11.0 - the Stage step and the carrier statement

* **New step "Stage" between Booked and In Transit** (Jobs > Stage, with its own tab and count). A booked job's button now says **Move to Stage**; a staged job has **Move to In Transit**. A job can still go straight from Booked to In Transit.
* **Carrier and driver are chosen when the job is staged** (the carrier list from Settings; the driver name and phone are optional). Moving a staged job to In Transit is then one click and keeps them; the loaded date is saved when it goes In Transit. A staged job that has no carrier (staged before the list had names) is asked for one at In Transit. While the carrier list is empty both moves stay one click.
* A staged job can go Back to Booked (the carrier is cleared, kept in the history), be put on hold or be cancelled. It cannot be marked Delivered without going In Transit first. It still counts in Booked Jobs and its storage fee keeps running. It is in no other section (Ready, More Than 30 Days, Future) while staged, like a job in transit.
* **New page Jobs > Carrier Statement** (for people who may see money). Choose a carrier and/or a driver, type the **rate per CF**, and optionally dates (empty = every job). It lists that person's jobs in Stage, In Transit and Delivered: Job #, Status, Customer, Phone, From, To, **A-CF**, Rate, **CF Pay** (= rate x actual CF), **Bal. Due**, Remarks, with totals, then **Subtotal** (Bal. Due total) - **CF Pay** = **TOTAL DUE**. Print and Download CSV (CSV needs the export permission). Nothing is stored: the rate is only typed on the page.
* No migration. **Upgrade:** `git pull origin main`, then Ctrl+F5.

## 1.10.0 - Driver Tracking and Job Tracking buttons

* **Two orange buttons at the very top of every page** (above the page title): **Driver Tracking** (https://app.bhrelocationinc.com/) and **Job Tracking**. Both open in the same tab. Not shown on the login page or when printing.
* **The two addresses are in Settings** ("Tracking buttons"). Leave one empty to hide that button. Only http / https addresses are accepted.
* No migration. **Upgrade:** `git pull origin main`, then Ctrl+F5.

## 1.9.0 - a carrier from a fixed list when a job goes In Transit (calendar phase 4)

* **New "Carriers" box on the Settings page:** one carrier per line (a foreman can be listed too).
* **Once the list has names, moving a job to In Transit needs a carrier from it.** Moving a job (from its page, or the "In Transit" button in a list, which now opens the job's dialog) asks for the **carrier** (required drop-down) and the **driver's name and phone** (optional). Today's date is still saved as the loaded date. The carrier and driver show in the job's history, its page and the In Transit list.
* **While the list is empty nothing changes:** Move to In Transit stays one click, so this update changes nothing until carriers are added in Settings.
* On an In Transit job the Carrier on the edit form is a drop-down of the list (a carrier the job already has stays choosable). Changing it to a name that is not on the list is refused. Jobs already In Transit without a carrier keep working.
* Hold, Cancel, Delivered and reopening a delivered job do not ask for a carrier. Renaming a carrier in Settings does not rename it on existing jobs.
* No migration. **Upgrade:** `git pull origin main`, then Ctrl+F5. Then add the carriers in Settings.

## 1.8.2 - second pickups on the calendar (calendar phase 3)

* **A trip entry with the activity "Pickup" on a later day now shows on the pickup calendar** as a second pickup: a dashed card with a "2nd pickup" label on that day, beside the job's normal pickup. Only booked jobs, only dates after today; an entry on the job's own pickup day, a delivery entry, an entry on a deleted trip, or a job on hold, in transit or delivered does not add one. The title adds "+ N second pickups" and the Future Jobs count is unchanged. The calendar knows a second pickup only when staff enter it on a trip.
* No migration. **Upgrade:** `git pull origin main`, then Ctrl+F5.

## 1.8.1 - the pickup calendar (calendar phase 2)

* **New page Jobs > Calendar** (a Calendar button next to the section tabs): a month grid with the **Future Jobs** on their pickup day, like the client's reference. Each job shows its number (a link), pickup time, customer, route, miles and cubic feet, foreman, balance (only for people who may see money) and its ticked checks (Confirmed, E-Signed, Job Docs, Bill of Lading). A day shows how many pickups it has, and today is highlighted.
* **Previous Month / Today / Next Month** buttons, a **Foreman filter** (any, none, or one foreman) and the month's count in the title ("Future Jobs (Pick-up): 8"), which equals the jobs in the Future Jobs list for that month.
* Only Future Jobs: not on hold, cancelled, in transit or delivered, and no job without a pickup date. No pickup limit per day. On a phone the grid becomes a list of the days that have jobs.
* No migration. Second pickups from trip entries come in the next version.
* **Upgrade:** `git pull origin main`, then Ctrl+F5.

## 1.8.0 - pickup time, miles and four checks on a job (calendar, phase 1)

* **New boxes on New Job / Edit Job, in the Route & dates section:** **Pickup time** (free text, like 08-10AM), **Miles** (a number you type; nothing is calculated) and four ticks: **Confirmed, E-Signed, Job Docs, Bill of Lading**. They show on the job page and in the audit log. Pickup Time and Miles are also optional columns in the CSV import and export. The Jobs list has no new columns.
* Preparation for the pickup calendar (next version): the calendar will show these on each job.
* Migration **019** adds six empty columns to `jobs` (existing jobs are untouched, nothing ticked).
* **Upgrade:** `git pull origin main`, `php cli/migrate.php` (019), `php cli/doctor.php`, `php cli/verify.php`, then Ctrl+F5.

## 1.7.1 - the Jobs list fits a 1650 px screen; a job is counted once; Overdue matches its list

* **The Jobs lists fit on a Windows screen at 115 to 125% scaling (about 1650 px wide) with the menu shown.** The job's status badge now sits under the Job #, the quick button reads "In Transit" / "Delivered" (the full wording is in its tooltip and the confirmation), and the actions column is narrower. Before, Balance due and Status were pushed off the right edge.
* **A job is counted once however many daily entries it has.** Trips list, Trip Details ("Jobs on this trip (1, 3 entries)"), the foreman report (new **Entries** column, also in its CSV) and the dashboard trip card show jobs and entries separately.
* **The dashboard's Overdue card now matches the list it links to.** "View all overdue" opens Booked Jobs filtered to booked jobs whose 1st available date has passed (new "Overdue only" filter, same count as the card). "Coming up next" lists the booked jobs whose 1st available date is today or later and links to the booked list sorted by that date.
* No migration. **Upgrade:** `git pull origin main`, then Ctrl+F5.

## 1.7.0 - Future Jobs, Booked Jobs, Range and Pads, storage fee payments

* **Two new sections on top and on the dashboard.** **Booked Jobs**: every job that is not delivered yet (booked, in transit, on hold). **Future Jobs**: booked jobs with a **pickup date after today** (a job with no pickup date is not a future job). A future job is no longer listed under Ready for Delivery (Ready is now picked up from 30 days ago up to today), so a job is in one date section only. Both have a tab with a count, a dashboard card, a CSV export and the same columns, sorting and filters as the other lists.
* **Range and Pads** on every job, entered right after Color: New Job / Edit Job, Job details, a "Range / Pads" column in the Jobs lists (each sortable, dash when empty), CSV export and optional import columns. Free text, up to 60 characters. Migration **017**.
* **Storage fee payments.** For customers who pay the storage fee every month: the Job page has a "Storage fee payments" box (needs permission to edit money) to record an amount, a date and a note. The fee **still due** is the fee minus what was paid, shown on the job, in the Jobs lists (with "paid $x"), in sorting and in the dashboard total. A payment can be at most what is due; a mistaken one can be removed (the amount becomes due again). Recording and removing are audited. Migration **018**. The CSV export still carries the assessed fee.
* **Upgrade:** `git pull origin main`, `php cli/migrate.php` (017, 018), `php cli/doctor.php`, `php cli/verify.php`, then hard-refresh the browser (Ctrl+F5).

## 1.6.6 - Jobs lists: labelled two-line rows, and a button to hide the menu

* **The Jobs lists have 11 columns plus the buttons, and every field of the job is on screen.** Related fields share a column and every line carries its own small label: Broker / Lot / Color, Pick / 1st available, Route / location, E and A cubic feet, Est / Dep / Grand, BH / Paid, Balance due / fee. Each field keeps its own sort link and shows a dash when empty.
* **A button in the top bar hides or shows the left menu** on a desktop screen (remembered in the browser). With the menu hidden the whole table fits on a 1366 px laptop; on a 1920 px screen it fits with the menu shown.
* A label and its figure no longer split across two lines in the money cells. No migration.
* **Upgrade:** `git pull origin main`, then hard-refresh the browser (Ctrl+F5) so the new stylesheet loads.

## 1.6.5 - the Jobs lists fit on one screen

* **Related fields are stacked in one column** (21 columns became 14): Job lot / Color, Pickup / 1st available, Route / Job location, E-CF / A-CF, Deposit / Grand total, Total to BH / Paid. Each field in a stacked heading keeps its own sort link, and an empty field still shows a dash.
* **The Jobs tables are set smaller and tighter** (13px text, less padding, headings wrap to two lines), so on a 1920 px screen every column is visible without scrolling. Narrower screens still scroll sideways. No migration.
* **Upgrade:** `git pull origin main`.

## 1.6.4 - every job field is a column in the Jobs lists

* **All Jobs, Ready, More Than 30 Days, In Transit and Delivered now show every field of the job form as a column**, with a dash when nothing is entered: Broker, Job lot, Color, Job location, E-CF, A-CF, and (for people who may see money) E-Total, Deposit, Grand total, Total to BH, Paid, Balance due, Storage fee. Each new column is sortable. The table is wider than a screen now, so it scrolls sideways.
* Negative amounts read **-$799.76** (they were shown as $-799.76).
* The "Balance" heading is now "Balance due". No migration.
* **Upgrade:** `git pull origin main`.

## 1.6.3 - fix: a job with a negative Total to BH or Balance due could not be saved

* The job form calculates Total to BH (Grand total - Deposit) and Balance due (Total to BH - Paid), and either can be negative (a deposit larger than the grand total, a customer who paid more than they owe). The amount check refused the minus sign, so saving failed with "must be an amount such as 1200". The two calculated boxes are now never validated or trusted from the form: the server calculates them from Grand total, Deposit and Paid. A negative balance typed by hand with nothing to calculate from is still refused. No migration.
* **Upgrade:** `git pull origin main`.

## 1.6.2 - Trip Details: Total to BH from the job, and a full Totals row

* **Total to BH on a trip entry comes from the job's own Total to BH by default** (Grand total - Deposit on the job). A figure typed on the entry wins. Shown on Trip Details and in the trip CSV, only to people who may see job money. No migration.
* **The Totals row now adds every money column:** Addio, Total to BH, BH (amounts only; a note such as "cash" is not added), Foreman, and each expense column. **"Total expenses" is unchanged** (the six expense columns).
* **Upgrade:** `git pull origin main`.

## 1.6.1 - the client's money rules on a job

* **Total to BH = Grand total - Deposit** and **Balance due = Total to BH - Paid**, always calculated (a blank amount counts as 0), live in the New Job / Edit Job form, on every save and on import. Both boxes are read-only; "BH total" is now labelled "Total to BH". A job paid more than it owes shows a negative balance (a credit). With no Grand total and no Deposit a typed Total to BH is kept. No migration. Existing jobs keep their stored figures until they are saved again.
* **Upgrade:** `git pull origin main`.

## 1.6.0 - Broker on every job; sheet formulas; Foreman as an amount

* **Broker** on the New Job / Edit Job form (free text with suggestions from the brokers already used), on Job details, as a **sortable column in every Jobs list**, a **Broker filter**, in search, in the CSV export and as an optional Import column. The same broker can be on any number of jobs. Migration **016** adds an empty `jobs.broker` column and an index; existing jobs are untouched.
* **Trip entries:** the Foreman column is an amount (number box); Broker, Grand, Job Beg and Depo are no longer shown on trip screens or in the trip CSV (stored values are kept); Addio and Total to BH are typed amounts. When Grand / Job Beg / Depo are supplied through a form post, the sheet formulas apply on every save (Addio = Grand - Job Beg; Total to BH = Job Beg - Depo + Addio).
* **Upgrade:** `git pull origin main`, `php cli/migrate.php` (013 to 016), `php cli/doctor.php`, `php cli/verify.php`.

## 1.5.0 - daily entries with sheet fields (client requirements, complete)

* **Daily entry form on Trip Details** ("Add daily entry"): Job #, Date, Activity, Foreman, From, To, the sheet fields and expenses in one step. An entry never creates a job.
* **One entry per job per day.** The same Job # and the same date in one trip is refused with a clear message (edit that day's entry instead); other dates are allowed. Entries without a date are never defaulted and never counted as a repeat. A future need for several entries on one day is a switch, `TRIP_SAME_DAY_ENTRIES=true` in `.env`; there is no database unique key on trip entries.
* **Closing-sheet fields** (from the client's Forman_Closing.xlsx): **Broker**, Grand, **Job Beg** (an amount), Depo, Addio, Total to BH and **Foreman pay** (the amount paid to the foreman, the sheet's "Forman" column) on each entry. Money fields need trips.financials. The sheet's own formulas fill an **empty** cell only: Addio = Grand - Job Beg; Total to BH = Job Beg - Depo + Addio (a typed figure is never overwritten). Trip Details also shows "All expenses as on the closing sheet" (Diesel + Hotels + Helpers + MISC) and the Foreman-pay total next to the existing six-column total.
* **Not built (needs the client):** day-off/storage rows with no job, the carry-over from the last trip, "days of work" rate and the closing balances, and whether the sheet's Extra (15% of Addio) replaces our Extra expense.
* Labels Helpers, MISC, Remarks; trip table/CSV order keeps BH then Foreman after the sheet fields.
* **Upgrade:** `git pull origin main`, `php cli/migrate.php` (013, 014, 015), `php cli/doctor.php`, `php cli/verify.php`.

## 1.4.4 - a new Job and a daily entry are two different things (client clarification)

* **New Job: the Job Number is unique, always.** A second job with the same Job # can never be created, in any letter case (`se1` and `SE1` are the same number), including a number that belongs to a deleted job in the Trash. The error is now clear: it names the number, says a Job # can only be used for one job, and tells the user to **add an entry to the job's trip** instead (for a deleted job: that it is in the Trash and can be restored). The database also refuses it on its own.
* **Trip daily entries never create a job.** An entry needs an existing Job #; an unknown number is refused with "a trip entry never creates a job, create the job first". The same existing job can have any number of entries on different dates, **each with its own expenses**, and **all of them are in the Trip's total** (trip page, trip list, foreman report, dashboard and CSV all agree). The Jobs list still shows the job once.
* **New Job form unchanged in fields and order** (Job #, Customer first name, Trip, Foreman, Color, Customer phone, From, To, Job location, Pickup date, 1st available date). **Choosing a Trip now fills in the Foreman on the form itself** (it used to be applied only when saving); the server applies the same rule when saving.
* **Upgrade:** `git pull origin main`, `php cli/doctor.php`. No migration.

## 1.4.3 - the same job number as many times as needed on a trip

* **A job number can be used on a trip as many times as the client likes**, with or without a date and an activity. The previous release refused the second row when nothing distinguished it (the job's pickup date was filled in for both), which is what the client hit. **Nothing is refused any more.**
* Instead, if entries look exactly alike (same job, date and activity), the trip is saved and a **note** appears: *"Job X appears 3 times on 2026-10-14 with no activity. Check that this is what you meant."* The **Add job** box behaves the same (*"was added again ... Check that this is what you meant"*).
* The job page's "Add to a trip" still adds a job to the trip once (it is an assignment, not an entry).
* **Upgrade:** `git pull origin main`, `php cli/doctor.php`. No migration.

## 1.4.2 - trip entries: Activity, From / To, Foreman; the same job on several dates (client feedback)

* **"Collected by" is gone** from the trip form, trip page, trip list and export (the client does not need it). The Foreman of the trip stays. The old values are kept in the database, untouched.
* **Each trip entry now has: Job #, Date, Activity (Pickup / Delivery / Other), From, To, BH, Foreman**, then the expenses and a remark. BH and Foreman follow each other as in the client's spreadsheet. From and To default to the job's own places and can be changed per entry (a delivery runs from the warehouse); Foreman defaults to the trip's foreman and can be another.
* **The same job can be on a trip more than once**, for example *Pickup on 30 October* and *Delivery on 31 October*. No new Job record or Job Number is needed, and Job Numbers stay unique in the Jobs table. **Only the exact same job + date + activity is refused** (message names the date and activity).
* The **Add job** box on the trip page also takes a date and an activity, so a second entry can be added later. The job page's "Add to a trip" still adds a job once.
* Trip Details shows every entry with Date, Activity, From, To, BH and Foreman; the CSV export has one line per entry with Activity, To and Foreman (right after BH).
* **Upgrade:** `git pull origin main`, `php cli/migrate.php` (014 adds three columns to the trip rows and fills them from data already there; nothing is changed or removed), `php cli/doctor.php`.

## 1.4.1 - create the trip first, assign jobs later (client follow-up)

* **A trip no longer needs a job.** The client creates the trip first (number, foreman, dates, collected by), then creates jobs and chooses that trip on the New Job form. Job Number is still required on the job.
* **Add job box on the trip page:** type a job number and **Add job**; the job joins the trip and gets its foreman. An empty trip says "No jobs on this trip yet". Jobs can still be put on a trip from the job form, the job page's "Add to a trip", the trip form or an import.
* **Empty trips everywhere:** they appear in the trip list (0 jobs), the foreman report (0 jobs, 0.00 expenses), the dashboard figure, and as one line (empty job columns) in the trip CSV export.
* Saving the trip form with every row removed leaves an empty trip; the jobs lose the assignment and keep their foreman.
* **Upgrade:** `git pull origin main`, `php cli/doctor.php`. No migration.

## 1.4.0 - "Keep me signed in" on the sign-in page

* **A checkbox on the sign-in page: "Keep me signed in on this device"** (for 14 days, or until you sign out), so administrators and staff do not have to type their password again every time the session times out or the browser is closed. Tick it only on your own computer or phone.
* **How it works, and why it is safe enough:** the browser keeps a random cookie (HttpOnly, SameSite=Lax, Secure over HTTPS); the database keeps only a **hash** of its secret, so a copy of the table cannot sign anyone in. A remembered sign-in is refused, and removed, when it has expired (fixed 14 days from the day it was ticked; using it does not extend it), the secret is wrong, the account is deactivated, locked or gone, or the user's **password has changed since**. Signing out ends it for that browser. The ordinary 30-minute idle timeout still applies to a session; a remembered browser simply gets a new session without asking.
* **My Account -> Devices:** shows how many devices are remembered and has **Forget all devices**. Changing the password on My Account forgets every device. A user keeps at most 10 remembered browsers. Everything is in the audit log (kept signed in, forgot devices, refused a cookie).
* **Switch it off:** `REMEMBER_ME_DAYS=0` in `.env` removes the checkbox and stops existing cookies from working (1-90 days otherwise; the default is 14).
* **Upgrade:** `git pull origin main`, `php cli/migrate.php` (013 adds the `remember_tokens` table), `php cli/doctor.php`. Until migrated, the checkbox is simply not shown and signing in works as before.

## 1.3.5 - `php cli/verify.php`: a read-only check of a real deployment (client follow-up, phase 5)

* **New command `php cli/verify.php`.** It runs every job, trip and foreman query the application uses (all five job lists, the new Foreman and Trip filters and sorting, the dashboard, trip list and search, trip pick lists, the foreman report, the trip export, the foremen page) against the real database, and checks the tables and columns the newer features need. This proves the queries work on the real database engine (MariaDB on cPanel); the automated tests only run on SQLite. It also reports the code version and commit, the database version, the settings that decide what people see (counting the 30 days from the pickup date, monthly storage fee), the storage fee rate, the data counts and any inconsistency (a job assigned to a deleted trip, a foreman that does not match the trip's, booked jobs with no pickup date).
* It never writes anything (tested: no job, trip, audit entry or timestamp changes). Exit code 1 only when something is broken; a warning is a hint.
* **Upgrade:** `git pull origin main`, then `php cli/verify.php`. No migration.

## 1.3.4 - customer phone on trips; phone-width checks (client follow-up, phase 4)

* **The customer's phone number now shows on the trip page** (tap-to-call, under the customer's name) and is a **Customer Phone** column in the trip CSV export, for people who may see jobs (`jobs.view`). It is the job's current number, so a correction shows on the trip at once. Without `jobs.view` the number is removed before any page or export sees it.
* **Phone-width test:** a new browser check opens 17 pages (dashboard, all five job lists, job form and details, trip list, form, details and report, foremen, storage fee settings) at 375 px and fails if any scrolls sideways, if the row buttons do not fit, or if a trip table is not inside its scroll container.
* Nothing needs migrating: **upload the code and run `php cli/doctor.php`**.

## 1.3.3 - Foreman / trip on the job lists (client follow-up, phase 3)

* **All five job lists have a "Foreman / trip" column** (the foreman's name and, under it, the trip number, linked to the trip for people who may view trips). Click the heading to sort.
* **New filters** under *More filters*: **Foreman** (any, a foreman, or "No foreman assigned") and **Trip number** (part of a number is enough). They combine with the search box, the other filters and every section, are kept by the sort links and paging, and the **export follows them**.
* The search box also finds a job by its foreman or trip number.
* Nothing needs migrating: **upload the code and run `php cli/doctor.php`**.

## 1.3.2 - a foreman and a trip number on every job (client follow-up, phase 2)

* **Every job can be assigned to a Foreman and a Trip.** Add/Edit Job has a *Trip* and a *Foreman* choice; Job Details shows both (the trip number links to the trip). A job assigned to a trip appears on that trip, and a trip brings its own foreman.
* **Kept in step with the trip pages:** a job added to a trip (trip form, "Add to a trip", import) is assigned to it; a job taken off a trip loses the assignment; changing a trip's foreman updates the jobs on it; deleting a trip clears the trip on its jobs (they keep their foreman). A job already assigned to a newer trip is never taken over by re-saving an older one. Rows on a trip, and their expenses, are never deleted by any of this.
* **Search and export:** the job list search finds a job by its foreman or trip number; every export and the import template have *Foreman* and *Trip Number* columns. The import checks them: the foreman must be on the Foremen page, the trip must exist, and a Foreman that is not the trip's foreman is refused.
* **Permission:** assigning needs `trips.manage` (Admin; Manager by default). Everyone who can see jobs sees the foreman and trip number; people without `trips.manage` see them as text, and a forged value in their request is ignored.
* **Existing data:** migration 012 turns every foreman name typed on a job (version 1.1.4) into a foreman on the Foremen page and links the job to it; a job already on a trip gets its newest live trip and that trip's foreman. The old text column is kept untouched. No trip row or expense changes.
* List columns and filters for Foreman and Trip come in the next phase.
* **Upgrade:** `git pull origin main`, `php cli/migrate.php` (012), `php cli/seed.php`, `php cli/doctor.php`.

## 1.3.1 - the updates are visible: version in the footer, defaults, role sync

* **The footer shows the version** of the code that is running (and, to administrators, the commit it was deployed from). `php cli/doctor.php` prints the same, so it is easy to see what a server is really running.
* **Default roles now get permissions added by newer versions.** Roles were created once and never changed afterwards, so a Manager created before the trip module could not see Trips, Foremen, the storage fee page or the CSV export, which looks exactly like "the update is missing". `php cli/doctor.php` now warns when a default role lacks permissions, and `php cli/sync-roles.php --apply` grants exactly the missing defaults (it only adds, never removes).
* **The client's two rules are now the default:** the 30-day rule counts from the **Pickup Date** (a job without one uses its 1st available date) and the storage fee is charged **every month**. Migration 011 moves settings that still hold the old defaults (1st available date / once); a value chosen on purpose (for example the loaded date) is kept. Nothing on any job changes.
* **Upgrade:** `git pull origin main`, `php cli/migrate.php` (011), `php cli/seed.php`, `php cli/sync-roles.php` (look) then `php cli/sync-roles.php --apply`, `php cli/doctor.php`.

## 1.3.0 - final pass for the client's change request (roadmap phase 9)

* **Limits for the trip form:** a trip holds at most **60 job rows** (a bigger form would be silently cut off by PHP's default `max_input_vars` of 1000, so it is refused with a message instead), and a request carrying more than 120 rows is refused before any row is checked.
* **Security review of the new module** (`docs/security-review.md`, "Version 1.2 review"): output escaping on every page, SQL injection strings in every search/filter/form field, forged tokens on every new POST route, hidden expenses surviving a save, export permission and CSV formula injection. No defect found; 6 new checks added for the limits.
* **Performance:** `tests/perf.sh` now builds trips too (2,000 trips, about 7,000 job rows at 20,000 jobs) and times the trip list, search, details, report, foremen and CSV export (all well inside their budgets).
* `php cli/seed-demo.php` also creates two demo foremen and three demo trips.
* Handover, test plan and security review brought up to date.
* **Upgrade:** upload the new files and run `php cli/doctor.php`. No migration.

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
