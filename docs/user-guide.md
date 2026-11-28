# User guide

For everyone who works with jobs. Ask your administrator if you cannot see something described here: your role decides what you see.

## Signing in and out

Tick **Keep me signed in on this device** on the sign-in page to stop typing your password every time (for 14 days, or until you sign out). Only do this on your own computer or phone. **My Account -> Devices** shows how many devices are remembered and lets you forget them all. Changing your password forgets them too.


Open the address you were given, type your **username** and password, press **Sign in**. After a wrong password you can try again; after **5 wrong tries** you must wait about 15 minutes (or ask an administrator to unlock you). If you do nothing for **30 minutes** you are signed out for safety.

**First time:** you will be asked to choose your own password (at least 10 characters; a sentence is easiest to remember). Change it anytime under your name -> **My Account**.

Always sign out on a shared computer: your name (top right) -> **Sign out**.

## The menu

| Item | What it shows |
|---|---|
| **Dashboard** | Counts for each section, overdue jobs, jobs coming up next, recent activity (and money totals, the Invoices box and trip figures if you may see them) |
| **All Jobs** | Every job. Search, filter and sort here. The tabs at the top are the sections: **Booked Jobs** (everything not delivered yet), **Future Jobs** (pickup date after today), **Ready for Delivery**, **More Than 30 Days**, **Stage**, **In Transit**, **Delivered** and the **Calendar** of pickups |
| **Ready for Delivery** / **More Than 30 Days** | Booked jobs by how long ago they were picked up (or how soon they are available) |
| **Stage** | Jobs that are staged with a carrier and driver but are not on the road yet |
| **In Transit** | Jobs on the road, with the loaded date, carrier and driver |
| **Delivered** | Finished jobs |
| **Carrier Statement** | (managers and administrators) what a carrier, driver or company is owed or owes, from the jobs |
| **Invoices** | (managers and administrators) saved, numbered invoices: DI-n for a driver or carrier, CI-n for a company |
| **Companies**, **Drivers** | (managers and administrators) address, DOT and MC of a company; phone and licence of a driver |
| **Import Jobs**, **Trips**, **By Foreman**, **Foremen** | As described below |

The two orange buttons at the very top of every page, **Driver Tracking** and **Job Tracking**, open those websites in the same tab.

On a phone, open the menu with the button at the top left.

## Finding a job

Type part of a **job number, customer name, city or route** in the search box and press **Search**. **More filters** adds status, dates and balance. Click a column heading to sort; click again to reverse. The grey note above the list tells you when it is filtered; the **x** button clears it.

## Adding a job

**All Jobs -> New job.** Required: job number (letters, numbers and `- _ / .`, must be unique), customer first and last name, from, to, and the **1st available date** (the first day the customer can accept delivery). Optional: the **customer's phone number** (on a phone it becomes a tap-to-call link), pickup date, job location, CF (cubic feet), notes, and, if you may see money, balance and storage fee.

**Storage fee is automatic:** A-CF × rate (default 0.6, so A-CF 1000 → 600.00) for each month, starting when a job has waited more than 30 days since its **pickup date** (days 31-60 = 1 month, 61-90 = 2 months, and so on; a part month counts as a full month). You never type it; a manager can type an *override* if a job needs a different amount. The Job Details page explains how the fee was worked out.

Amounts are plain numbers: `1200` or `1200.50`. No `$`, no commas, at most two decimals.

The job appears in **Ready for Delivery** or **More Than 30 Days** automatically.

## Moving a job along

Open the job. The buttons at the top show what you can do next:

1. **Move to Stage** (booked -> stage): **one click** on the job's row in any list or on its page. When your administrator has listed carriers in Settings, a small window asks for the **carrier** (choose from the list, required) and the **driver's name and phone** (optional; choose a known driver and the phone fills in). The loaded date is **not** saved yet.
2. **Move to In Transit** (stage -> in transit): **one click**. The carrier and driver are kept and today's date is saved as the **Loaded date**. (A booked job can also go straight to In Transit from its page.)
3. **Mark as Delivered** (in transit -> delivered): **one click**, on the In Transit list or on the job's page. Today's date is saved as the **Delivery date**.
4. **Back to Booked** (from Stage, clears the carrier), **Put on hold** or **Cancel job**: a short reason is required for hold and cancel. **Resume** brings an on-hold job back to Booked.
5. Managers and administrators can **Reopen** delivered or cancelled jobs.

If a click happened late, an administrator or anyone who may edit can correct the dates under **Edit**. Under **Edit**, a job that is staged or in transit also has its **Carrier** box (carrier from the list, driver name and phone).

Everything you do is written into the job's **history** (who, when, any note). If someone else changed the job a moment ago, you will be told and nothing is overwritten.

## Editing and deleting

**Edit** on the job page. If you leave a form with unsaved changes the browser asks you to confirm. **Delete** moves a job to the **Trash** (managers and administrators): it disappears from the lists but can be restored from **All Jobs -> Trash**.

## Importing a list (managers and administrators)

1. **Import Jobs**, download the **CSV template** (or use your own sheet: the column names are matched loosely).
2. Upload the file. Read the **preview**: how many rows are ready, and which have problems and why.
3. Press **Import**. Problem rows are skipped; download the problem report, fix those rows and upload them again.

## Exporting

On any list press **Export -> CSV** or **Excel**. You get exactly the jobs matching your current search and filters. Open CSV in Excel normally; accents display correctly.

## Foreman trips (managers)

**Foremen** lists the people who pick jobs up; **Trips -> New trip** records one trip: trip number, foreman, when it began and ended, who collected, and a row for every job picked up. Type the job number and the customer and "from" are filled in for you. Each row of a trip is an **entry**: the job number, the **date**, the **activity** (Pickup / Delivery / Other), **From** and **To**, BH, the **foreman** and the expenses. The same job can have several entries (for example Pickup on one day and Delivery the next); there is no limit, and if two entries look exactly alike you get a note after saving so you can check it was not a slip. Fill in the expense amounts (Diesel, Hotels, Helper, Misc, Commission, Extra) exactly as they are; the trip page adds up each column and shows **Total expenses**. Spare rows may stay empty. Search finds a trip by number, foreman, collected by, job number or customer. If you may not see expenses, those columns are simply not there. **Trips -> By foreman** adds the expenses up per foreman for a period, and **Export CSV** (on the trip list and on the report) downloads exactly what you are looking at for Excel. **You can create a trip before any job exists** (number, foreman, dates), then choose it when you create a job, or type a job number in the **Add job** box on the trip page. **A Job # belongs to one job only:** New Job refuses a number that already exists (even in another letter case, or in the Trash). For another day of the same job, add an **entry** on its trip, which never creates a job, has its own expenses, and counts in the trip's total. **Every job has a Foreman and a Trip** (choose them on Add/Edit Job, or put the job on a trip from the Trips pages): the trip brings its foreman, and the job appears on the trip. A job's own page lists its trips and has **Add to a trip** and **New trip with this job** buttons; the dashboard has a small trips card. Deleting a trip never deletes its jobs; a foreman who left is deactivated, not deleted.

## Pickup details and the calendar

A job has a **Pickup time** (any text, such as 08-10AM), **Miles** (typed by hand) and four ticks: **Confirmed**, **E-Signed**, **Job Docs**, **Bill of Lading**. **Future Jobs** are booked jobs with a pickup date after today; the **Calendar** shows them on their pickup day, month by month, and a trip entry with the activity Pickup on a later day shows as a dashed "2nd pickup".

## Carrier Statement (managers and administrators)

**Carrier Statement** works out, from the jobs, what a carrier, a driver or a company is owed or owes. Choose a **Carrier**, a **Driver** and/or a **Company** (a company is the job's Broker); leave the dates empty to include every job in Stage, In Transit and Delivered.

- Each job has its own **Carrier rate (per CF)**, typed on the job under Edit -> Cargo & money.
- **CF Pay = Carrier rate x A-CF** (the actual cubic feet). A job with no rate or no A-CF shows a dash, a yellow notice says so, and the result says "(not final)".
- **Total Due = Bal. Due total - CF Pay total - Labor - Pads + Paid / adjustment.** Labor, Pads and Paid / adjustment are optional boxes; a minus sign is allowed (money already paid is typed as a minus).
- Under the total it says who owes whom: **"Carrier owes company"** when Total Due is positive, **"Company owes carrier"** when it is negative.
- **Print** (or Save as PDF) gives a clean page with your company header, Remarks and Signature. **Download CSV** gives the same rows.

Nothing is saved by looking at a statement. To keep it, press **Save as an invoice**.

## Invoices (managers and administrators)

- **Save as an invoice** (under the statement) keeps the statement exactly as it is that day as **DI-1, DI-2, ...** (a driver or carrier statement) or **CI-1, CI-2, ...** (a statement for a company only). **Editing a job later never changes a saved invoice.**
- A job is on **one open invoice of each kind**. The statement marks jobs that are already on one ("On DI-3"); saving leaves them out unless you tick "Also include jobs already on an invoice". A statement with a job that has no rate or no A-CF cannot be saved.
- On an invoice you can change the date, the remarks and Labor, Pads and Paid / adjustment, **mark it paid** (with a date) or not paid, **print** it, or **void** it with a reason (it stays on file, its number is never used again, and its jobs can be invoiced again). A paid invoice must be marked not paid before it can be voided.
- On a driver invoice, **Generate company invoices** makes one CI per company from the saved lines (Labor, Pads and Paid start at 0 on each).
- **Invoices** lists them (search by number, name; filter by status, type and dates; totals are shown per kind) and exports them: **Invoices CSV**, **Lines CSV**, **Everything (ZIP)** (needs the export permission).

## Companies and Drivers (managers and administrators)

A **company** is the same as a job's **Broker**; a **driver** is the same as a job's **Driver name** (they are matched by name, letter case ignored). Keep their details here: a company's address, city, phone, DOT and MC number (printed on company statements and invoices), a driver's phone and licence number (the phone fills in when you choose the driver on a job; the licence is printed on the driver statement and invoice). Each page also lists the names that are on jobs but have no details yet, with an **Add details** button.

## Questions

| | |
|---|---|
| "I cannot see Carrier Statement or Invoices" | Your role does not include them (they show balances); ask an administrator |
| "The statement says (not final)" | A job on it has no Carrier rate or no A-CF: open the job and fill them in |
| "Save as invoice says every job is already on an invoice" | Those jobs are on an open invoice of that kind; tick the box to invoice them again, or void the other invoice |
| "I cannot see balances" | Your role does not include financial information |
| "I cannot import / delete / see Users" | Your role does not include it; ask an administrator |
| "The job is not in Ready for Delivery" | Its status must be Booked and the 1st available date within 30 days (or the day limit your administrator set) |
| "It says access denied" | Your role does not allow that page |
| "I typed 12.345 and it was refused" | Money has at most two decimals |
| "I was signed out" | 30 minutes without activity, or 8 hours since you signed in |
| "My password does not work" | After 5 tries the account locks for 15 minutes; ask an administrator to unlock or reset it |
