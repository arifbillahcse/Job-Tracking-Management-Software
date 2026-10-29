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
| **Dashboard** | Counts for each section, overdue jobs, jobs coming up next, recent activity (and money totals if you may see them) |
| **All Jobs** | Every job. Search, filter and sort here |
| **Ready for Delivery** | Booked jobs whose **1st available date is within 30 days**. They get here by themselves as the date approaches |
| **More Than 30 Days** | Booked jobs whose 1st available date is **more than 30 days away** |
| **In Transit** | Jobs on the road, with the loaded date. Carrier and driver are shown when they were entered |
| **Delivered** | Finished jobs |

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

1. **Move to In Transit** (booked -> in transit): **one click**, on the job's row in any list or on its page. Today's date is saved as the **Loaded date**. You are asked to confirm first.
2. **Mark as Delivered** (in transit -> delivered): **one click**, on the In Transit list or on the job's page. Today's date is saved as the **Delivery date**.

If a click happened late, an administrator or anyone who may edit can correct the dates under **Edit**. Carrier name, driver and driver phone are optional: add them under **Edit** while the job is in transit.
3. **Put on hold** or **Cancel job**: a short reason is required. **Resume** brings an on-hold job back to Booked.
4. Managers and administrators can **Reopen** delivered or cancelled jobs.

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

**Foremen** lists the people who pick jobs up; **Trips -> New trip** records one trip: trip number, foreman, when it began and ended, who collected, and a row for every job picked up. Type the job number and the customer and "from" are filled in for you. Fill in the expense amounts (Diesel, Hotels, Helper, Misc, Commission, Extra) exactly as they are; the trip page adds up each column and shows **Total expenses**. Spare rows may stay empty. Search finds a trip by number, foreman, collected by, job number or customer. If you may not see expenses, those columns are simply not there. **Trips -> By foreman** adds the expenses up per foreman for a period, and **Export CSV** (on the trip list and on the report) downloads exactly what you are looking at for Excel. **Every job has a Foreman and a Trip** (choose them on Add/Edit Job, or put the job on a trip from the Trips pages): the trip brings its foreman, and the job appears on the trip. A job's own page lists its trips and has **Add to a trip** and **New trip with this job** buttons; the dashboard has a small trips card. Deleting a trip never deletes its jobs; a foreman who left is deactivated, not deleted.

## Questions

| | |
|---|---|
| "I cannot see balances" | Your role does not include financial information |
| "I cannot import / delete / see Users" | Your role does not include it; ask an administrator |
| "The job is not in Ready for Delivery" | Its status must be Booked and the 1st available date within 30 days (or the day limit your administrator set) |
| "It says access denied" | Your role does not allow that page |
| "I typed 12.345 and it was refused" | Money has at most two decimals |
| "I was signed out" | 30 minutes without activity, or 8 hours since you signed in |
| "My password does not work" | After 5 tries the account locks for 15 minutes; ask an administrator to unlock or reset it |
