# User acceptance checklist

For the people who will use the software. You do not need technical knowledge. Use a **test copy with demo data**, never the live data.

**How to do it:** work down the list in order. For each scenario do the steps, compare with "You should see", and tick **Pass** or write what happened under **Notes**. Anything that confuses you counts, even if it technically works.

Sign in as **Admin** unless a scenario says otherwise. Replace `TEST-1` etc. with any job numbers you like.

| Tester | Date | Browser / device |
|---|---|---|
| | | |

## A. Signing in

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| A1 | Open the site. Type your username and a wrong password. | A message "Invalid username or password." It does not say which one is wrong. | ☐ | |
| A2 | Sign in with the right details. | The Dashboard, with your name at the top right. | ☐ | |
| A3 | Click your name, then **Sign out**. Press the browser's Back button. | You are on the sign-in page. Back does not show private pages. | ☐ | |
| A4 | Sign in, leave the page open 30 minutes without clicking, then click anything. | You are asked to sign in again. | ☐ | |
| A5 | Enter a wrong password 5 times in a row. | A message that you must wait a few minutes. (An administrator can unlock you sooner under **Users**.) | ☐ | |
| A6 | Sign in with **Keep me signed in on this device** ticked. Close the browser completely and open the site again. | You are still signed in (no password asked). My Account -> Devices says 1 device. | ☐ | |
| A7 | Click **Forget all devices** (or sign out), close the browser and open the site again. | You must sign in again. | ☐ | |

## B. Creating and finding jobs

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| B1 | **All Jobs → New job**. Fill in job number, customer, from, to, and a **1st available date 10 days from today**. Save. | "Job created". The job opens. | ☐ | |
| B2 | Open **Ready for Delivery**. | The new job is there, with a note like "in 10 days". | ☐ | |
| B3 | Create `TEST-2` with a 1st available date **45 days from today**. Open **More Than 30 Days**. | `TEST-2` is there and is **not** in Ready for Delivery. | ☐ | |
| B4 | Create jobs with a date exactly **30** and exactly **31** days from today. | 30 days → Ready for Delivery. 31 days → More Than 30 Days. | ☐ | |
| B5 | Create a job whose 1st available date was **last week**. | It is in Ready for Delivery and marked **Overdue**. | ☐ | |
| B10 | Add a job with E-CF 1000, A-CF 1200, E-Total 3000, Deposit 1000, Grand total 3500, BH total 2500, Paid 1250, Balance 1250, Foreman Joe, Job lot, Color. Open it. | Everything shows exactly as typed; nothing is calculated for you. Log in as Staff: no money fields on the form or on Job Details. | ☐ | |
| B6 | Create a job whose 1st available date was **45 days ago** (or use the demo data). | It is in **More Than 30 Days** (not Ready), and shows a storage fee of CF × 0.6. A job 30 days ago is still in Ready. *(assumption K1: client to confirm)* | ☐ | |
| B7 | Settings: type the storage rate as `60c` and save. | Accepted; shown as 0.6 dollars per CF (60 cents). | ☐ | |
| B6 | Try to create another job with the same job number. | A message that it is already in use. What you typed is still there. | ☐ | |
| B7 | Leave First name empty and save. | A message beside that field. Nothing is saved. | ☐ | |
| B8 | Type a balance of `12.345`. | A message that money can have at most two decimals. | ☐ | |
| B9 | Type a customer name with accents or another alphabet, e.g. `Zoë Müller` or `李小龍`. Save and open it. | It shows exactly as typed. | ☐ | |
| B10 | In **All Jobs**, search for part of a customer's name, then for a job number. | Only matching jobs. A note says the list is filtered; a button clears it. | ☐ | |
| B11 | Open **More filters** and try status, dates, balance. Click column headings to sort. | The list follows. The filters stay when you go to page 2. | ☐ | |
| B12 | Add a job with a **customer phone number**, then open it and look at the lists. | The number is on Job Details and under the customer's name in every list. On a phone, tapping it offers to call. A value like `abc` is refused with a message. | ☐ | |

## C. Moving a job along

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| C1 | In **Ready for Delivery**, click **Move to Stage** on a job's row, then in the **Stage** list click **Move to In Transit** (with the carrier list empty each is one click; with carriers listed, Stage asks for the carrier, see section I). | A question names the date. After OK the job is **In Transit** and shows today as **Loaded date**. (Moving a booked job straight to In Transit from its page is still possible.) | ☐ | |
| C2 | Open **In Transit**. Under **Edit** on that job add a carrier, driver and driver phone (optional). | The job is listed with its loaded date; once saved it also shows the driver and a tap-to-call phone number. | ☐ | |
| C3 | On the **In Transit** list click **Mark as Delivered** next to the job. | After OK the job is **Delivered**, has today as **Delivery date**, and is under Delivered. The Dashboard counts have moved (In Transit down by 1, Delivered up by 1). | ☐ | |
| C4 | Scroll to the job's history. | Every step with date, who did it, and any note. | ☐ | |
| C5 | Put a job **On hold** (a reason is required). | It leaves Ready for Delivery but is still in All Jobs. **Resume** brings it back. | ☐ | |
| C6 | Click **Move to Stage** or **Move to In Transit** twice quickly (double-click). | Only one move is recorded in the history. | ☐ | |
| C7 | Edit a job: change the balance and add a note. Save. | The change is shown. The history/audit log records it. | ☐ | |
| C8 | Delete a job. Open **Trash**, restore it. | It disappears from the lists, appears in Trash, and comes back when restored. | ☐ | |
| C9 | **Storage fee**: add a job with **CF 100** and a 1st available date **45 days ago**. Open it. | **Storage fee $60.00** and a note "Automatic: CF 100 × rate 0.6". A job with the same CF whose date was 10 days ago shows $0.00 and says when the fee starts. | ☐ | |
| C10 | As Admin open **Settings**, change the storage fee rate to 1 and save. Look at the first job again. | Its fee is now $100.00 at once. Delivered jobs keep their old fee. Change the rate back to 0.6. | ☐ | |
| C11 | Create a job with A-CF 1000 and a pickup date 45 days ago, then another 75 days ago. Open **More Than 30 Days** or Job Details. | 45 days: fee 600.00 (1 month). 75 days: 1,200.00 (2 months). The note on Job Details says "× 2 months". | ☐ | |
| C11 | On a job you moved by mistake late, open **Edit** and correct the **Loaded date**. | It saves; a date in the future is refused; the change is in the Audit Log. | ☐ | |

## D. Dashboard

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| D1 | Open the Dashboard. | Counts for all jobs, Ready, More Than 30 Days, In Transit and Delivered. Clicking a count opens that list. | ☐ | |
| D2 | Compare a count with the list it links to. | The same number. | ☐ | |
| D3 | Look at **Overdue** and **Coming up next**. | The oldest overdue jobs, and the ones due soonest. | ☐ | |

## E. Import and export

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| E1 | **Import Jobs → CSV template**. Open it in Excel. | A sheet with the right headings and one example row. | ☐ | |
| E2 | Put your own 5 jobs in, one with a wrong date. Upload it. | A preview: how many are ready, which row has a problem and why. **Nothing is saved yet.** | ☐ | |
| E3 | Click **Import**. | The good rows are saved, the bad row is skipped. You can download a file listing the problem rows. | ☐ | |
| E4 | Upload the same file again. | Every row is reported as already existing. Nothing is duplicated. | ☐ | |
| E5 | Upload your own existing sheet (with your own column headings). | The columns are recognised even if the names differ a little; anything unknown is listed as ignored. | ☐ | |
| E6 | On **All Jobs**, search for something, then **Export → CSV** and **Excel**. | A file with exactly the jobs you were looking at, opening correctly in Excel (accents intact). | ☐ | |
| E7 | Upload a photo or a Word file renamed to `.csv`. | It is refused with a clear message. | ☐ | |

## F. People and permissions (Admin)

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| F1 | **Users → New user** with the Staff role, a username and no e-mail. | A temporary password is shown **once**. The person must choose their own at first sign-in. | ☐ | |
| F2 | Sign in as that Staff user. | No Users, Roles, Settings or Audit Log in the menu. No balances or storage fees visible. | ☐ | |
| F3 | As Staff, type `/users` or `/settings` into the address bar. | "Access denied". | ☐ | |
| F4 | **Roles & Permissions → Permission matrix.** Tick or untick something for Staff and save. | It takes effect for that role on its next click, without signing out. | ☐ | |
| F5 | Try to remove your own Admin rights or deactivate yourself. | Refused with an explanation. The system always keeps at least one administrator. | ☐ | |
| F6 | **Audit Log**: find what you did today. Filter by person and by date. | Each action with who, when and the before/after values. No passwords anywhere. | ☐ | |
| F7 | **Settings**: change the day limit from 30 to 45. | Jobs 31 to 45 days away move to Ready for Delivery by themselves. Change it back. | ☐ | |

## H. Foreman trips

| # | Do this | You should see | Pass | Notes |
|---|---------|----------------|------|-------|
| H1 | Open **Foremen** and add a foreman (name, phone). | He is listed as Active. Adding the same name again is refused. | ☐ | |
| H2 | Open **Trips -> New trip**. Enter a trip number, choose the foreman, dates, **Collected by**. In the first row type the job number of an existing job, a BH, Diesel, Hotels, Helper, Misc, Commission, Extra and a remark. | Saved. The trip page shows the customer and From of the job, each amount exactly as typed, a Totals row and **Total expenses** (the sum of the six columns). | ☐ | |
| H3 | Add a second job on the same trip with a Diesel of 0.20 when the first has 120.10. | Diesel total is 120.30 (no lost cent). | ☐ | |
| H4 | Type a job number that does not exist, a deleted job, or the same job twice. | Each is refused with a message next to the row. | ☐ | |
| H5 | Search Trips by trip number, foreman, collected by, a job number and a customer name; filter by foreman and by dates. | The right trips only. | ☐ | |
| H6 | Edit the trip: change an amount, remove a job, add one. Open the Audit Log. | Saved; "Trip updated" shows what changed. | ☐ | |
| H7 | Sign in as a user whose role has Trips but **not** "See and edit trip expenses". | No expense columns, totals or inputs anywhere; changing a remark does not erase the amounts (check again as Manager). | ☐ | |
| H8 | Delete a trip. | It disappears from the list; the jobs on it are not affected. | ☐ | |
| H9 | Open **Trips -> By foreman**. Pick a period. | One line per foreman with trips, jobs, each expense column and Total expenses, and a Total line. The numbers match the trip pages. | ☐ | |
| H10 | Click **Export CSV** on the trip list (after a search) and on the report. Open the files in Excel. | One line per job with the trip details repeated; only the trips you searched for; amounts as numbers. | ☐ | |
| H12 | Add a job and choose a **Trip**. Open the job, then the trip. | The job shows the trip number (a link) and the trip's foreman, and appears on the trip with no expenses. | ☐ | |
| H13 | Edit the job and choose another trip, then none. | The job moves; the row on the old trip and its expenses are still there. | ☐ | |
| H14 | Search the job list for a trip number and for a foreman name. | The jobs assigned to them. | ☐ | |
| H15 | On the job lists, look at the **Foreman / trip** column; use **More filters -> Foreman** and **Trip number**; sort by the column. | The column shows the foreman and the trip number (a link). The filters narrow the list; the Export button exports the same jobs. | ☐ | |
| H16 | Open a trip whose job has a customer phone number. Export the trip list. | The number shows under the customer's name (tap-to-call on a phone) and is in the Customer Phone column of the CSV. | ☐ | |
| H17 | Create a trip with only a number, a foreman and dates (no job). Then create a job and choose that trip in the Trip drop-down. Open the trip. | The trip saves with no job. The job form offers the trip. The trip page lists the job. | ☐ | |
| H18 | On the trip page type another job's number in the Add job box. | The job joins the trip and gets its foreman. | ☐ | |
| H19 | Create a trip. Add an existing job twice: **Pickup** on 30 October and **Delivery** on 31 October (set From and To on each). Save and open the trip. | Both entries show with date, activity, From, To, BH and Foreman. No new job was created. | ☐ | |
| H20 | On a trip, type the same job number in three rows (nothing else), and save. Also add the same job again with the Add job box. | All entries are saved. A note says the job appears 3 times (a warning, not an error). | ☐ | |
| H21 | New Job: type a Job # that already exists (also in other letter case). | It is refused with a message that says the number already exists and to add an entry to its trip instead. No second job appears. | ☐ | |
| H22 | New Job: choose a Trip. | The Foreman field fills in with the trip's foreman. | ☐ | |
| H23 | On a trip, enter the same job on three different dates, each with different expenses. | Three entries; the trip total is the sum of all of them; the Jobs list still shows the job once. | ☐ | |
| H11 | Open a job that is on a trip, then a job that is on none and use **Add to a trip**; also try **New trip with this job**. Look at the dashboard. | The first job lists its trips. The second is added to the chosen trip (no amounts yet) or opens a new trip form with its number filled in. The dashboard has a "Foreman trips" card (last 30 days). | ☐ | |

## G. Phone and tablet

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| G1 | Open the site on a phone. | Lists show as cards, nothing scrolls sideways, buttons are easy to tap. | ☐ | |
| G2 | Open the menu (top-left button) and move around. | The menu slides in and out. | ☐ | |
| G3 | Create a job and move it to In Transit from the phone. | It works as on a computer. | ☐ | |

## I. Pickup details, calendar, Stage and carriers

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| I1 | New job: fill **Pickup time** (free text such as 08-10AM), **Miles**, tick **Confirmed** and **E-Signed**. Save and open the job. | All four are saved and shown on the job page. | ☐ | |
| I2 | Make a booked job with a pickup date **after today**. Open **Jobs -> Future Jobs** and **Calendar**. | It is in Future Jobs, and on the calendar on its pickup day (time, customer, route, CF, foreman, ticks). | ☐ | |
| I3 | Open **Booked Jobs**. | Every job that is not delivered yet (booked, staged, in transit, on hold). Delivered and cancelled jobs are not here. | ☐ | |
| I4 | **Settings -> Carriers**: type two carrier names, one per line, save. | The names are saved. | ☐ | |
| I5 | On a booked job press **Move to Stage**. | A dialog asks for the **carrier** (required, from the list) and the driver name and phone (optional). The job goes to the **Stage** tab. No loaded date is saved yet. | ☐ | |
| I6 | On the staged job press **Move to In Transit**. | One click. The carrier and driver are kept and today is saved as the loaded date. | ☐ | |
| I7 | Stage a job again, then **Back to Booked**. | The carrier is cleared (it stays in the job's history). A staged job cannot be marked Delivered directly. | ☐ | |
| I8 | **Edit** a staged or in-transit job and change its carrier to a name that is **not** on the list. | It is refused with "Choose a carrier from the list." | ☐ | |

## J. Top buttons and company details

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| J1 | Look at the top of any page (not the sign-in page). Press **Driver Tracking**, then **Job Tracking**. | Two orange buttons; each opens its address **in the same tab**. | ☐ | |
| J2 | **Settings -> Tracking buttons**: empty one address, save. | That button is gone; the other stays. Restore it afterwards. | ☐ | |
| J3 | **Settings -> Company details on printed statements**: fill address, phone, DOT, MC. | Saved; they show at the top of the Carrier Statement (see K6). | ☐ | |

## K. Carrier Statement

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| K1 | Sign in as Staff and look at the menu. | **Carrier Statement** is not in the menu and its address gives "Access denied". | ☐ | |
| K2 | Make an In Transit job: carrier KEN, **A-CF 900**, **Carrier rate 2.50**, Grand total 2275.74 (so Balance due 2275.74). Open **Carrier Statement**, choose carrier KEN, **Show statement**. | The row shows A-CF 900, Rate $2.50, **CF Pay $2,250.00**, Bal. Due $2,275.74. **TOTAL DUE $25.74** and "**Carrier owes company $25.74**". | ☐ | |
| K3 | Remove the rate from the job and show the statement again. | CF Pay is a dash, a yellow notice says how many jobs lack a rate, and the result says "(not final)". | ☐ | |
| K4 | Type **Labor 10**, **Pads 5**, **Paid -3** and show the statement (rate back at 2.50). | Total Due = 25.74 - 10 - 5 - 3 = **7.74**. Each line shows in the summary only when it is not 0. | ☐ | |
| K5 | Give the job a **Broker** and choose that **Company** (and no carrier). | "Company Statement", every carrier's jobs of that company, a Carrier / Driver column. | ☐ | |
| K6 | Press **Print** (or Save as PDF). | A clean page: company header (name, address, DOT, MC, phone), title, driver / carrier, date, period, the table, the summary, Remarks and Signature. No menu, no filter box, no buttons. | ☐ | |
| K7 | Press **Download CSV** and open it in Excel. | The same rows and totals as the screen; a negative total is a plain number. | ☐ | |
| K8 | Use a job whose carrier fee is bigger than its balance. | Total Due is negative and the line says "Company owes carrier". | ☐ | |
| K9 | On a phone, open the statement. | Nothing makes the page scroll sideways; amounts do not break across two lines. | ☐ | |

## L. Saved invoices

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| L1 | On a carrier statement press **Save as invoice** (date today). | Invoice **DI-1** opens with the lines, totals and who owes whom. It is in **Invoices**. | ☐ | |
| L2 | Edit one of its jobs (change the balance, the CF or the rate). Open the invoice again. | **The invoice has not changed.** | ☐ | |
| L3 | Show the same statement again. | The jobs are marked **"On DI-1"**. Saving again says every job is already on an invoice. Ticking "Also include jobs already on an invoice" makes DI-2. | ☐ | |
| L4 | Try to save a statement that has a job with no rate or no A-CF. | It is refused and says to enter them on the job first. | ☐ | |
| L5 | On an invoice press **Mark as paid** (a date), then **Mark as not paid**. | The status and date follow. A future date is refused. | ☐ | |
| L6 | Try to **Void** a paid invoice; mark it not paid; void it with a reason. | A paid one cannot be voided; the open one can. It stays on file marked Void, its number is not used again, its jobs can be invoiced again. | ☐ | |
| L7 | **Change this invoice**: Labor 20, Remarks "Week 46". | The total is recalculated; the jobs are as they were. | ☐ | |
| L8 | On a driver invoice press **Generate company invoices**. | One company invoice (**CI-n**) per company, from the saved lines; Remarks and date copied, Labor, Pads and Paid are 0; jobs with no company are left out and the message says how many. The driver invoice lists them; each says "Made from DI-n". | ☐ | |
| L9 | Press Generate again. | Refused while those company invoices are open. After voiding them it works again, with new numbers. | ☐ | |
| L10 | Choose only a **Company** on the statement and save. | A **company invoice (CI)**, with the company's address, DOT and MC. | ☐ | |
| L11 | Open **Invoices**, filter by Open / Paid / Void, by type, by dates, search "DI-1" and a driver name. | The right invoices; the totals are shown **per kind** (driver and company invoices are never added together). | ☐ | |
| L12 | Print an invoice. | Same clean layout as the statement, with the invoice number and date. | ☐ | |
| L13 | Open the **Dashboard** and Staff's dashboard. | Admin / Manager: an Invoices box (open, not settled, paid in 30 days, latest five). Staff: no box and no menu item. | ☐ | |

## M. Companies and Drivers

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| M1 | Open **Companies**. Under "Brokers on jobs with no company details" press **Add details** for a broker; fill address, DOT, MC. | The company is listed with its number of jobs. Adding the same name (any letter case) is refused. | ☐ | |
| M2 | Open **New Job** and click in the **Broker** box. | The companies are offered, each name once. | ☐ | |
| M3 | Rename a company with the box "also change the Broker on its jobs" ticked. | The jobs now carry the new name and stay linked. | ☐ | |
| M4 | Save a company invoice, then change the company's address. | The saved invoice still shows the old address; a new statement shows the new one. | ☐ | |
| M5 | Open **Drivers**; add a driver with phone and licence number. | Listed with its jobs. Same duplicate rule. | ☐ | |
| M6 | **Move to Stage** a job; type the first letters of the driver's name and choose it. | The driver's phone fills in the empty phone box. | ☐ | |
| M7 | Show a **driver statement** and save it as an invoice. | The licence (and the phone) are on the statement and on the invoice. | ☐ | |

## N. Export

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| N1 | On **Invoices** press **Invoices CSV**, **Lines CSV** and **Everything (ZIP)**. Open them in Excel. | Invoices: one row per invoice. Lines: one row per job line. ZIP: invoices, lines, companies and drivers. | ☐ | |
| N2 | Filter the list (status Open) and export again. | Only the filtered invoices. | ☐ | |
| N3 | Sign in as Staff and open the address `/invoices/export`. | "Access denied". | ☐ | |

## O. Importing the old application's data (once, done by the administrator)

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| O1 | `php cli/backup.php`, then a **dry run**: `php cli/import-old.php --zip=FILE` (the old application's Export) or `--old-db=... --old-user=...`. | A report: companies, drivers, invoices (DI and CI), lines, how many lines are linked to a job, and warnings. **Nothing is written.** | ☐ | |
| O2 | Read the warnings (a total that differs, a missing date, a rate with more than 2 decimals). Decide if they are acceptable. | | ☐ | |
| O3 | Run it again with `--apply`. | "Done". Invoices, Companies and Drivers are filled. | ☐ | |
| O4 | Open one old invoice (for example DI-295). Compare its number, lines and **total** with the old application's printout. | Same number, same jobs, same total. | ☐ | |
| O5 | Run the same `--apply` again. | Nothing is imported twice ("already here"). | ☐ | |
| O6 | Make a new invoice. | It gets the next number after the highest old one. | ☐ | |

## P. Backup and restore drill (do it once before going live)

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| P1 | `php cli/backup.php`. | A file in `storage/backups`. Copy it off the server. | ☐ | |
| P2 | Create an **empty second database**, restore the backup into it (`docs/operations.md`, "Restore"), then run `DB_DATABASE=<second database> php cli/verify.php`. | Every line says ok; the counts (jobs, invoices) match the live site. | ☐ | |
| P3 | `php cli/verify.php` on the live site. | Every line says ok. | ☐ | |

## Questions we still need your answer on

_Foreman trips:_ what do **BH**, **Total to BH**, **Addio**, **Job Beg** and the **15%** mean, and how are **Grand Total, BH Total, Paid and Balance** worked out? Is Total expenses = Diesel + Hotels + Helper + Misc + Commission + Extra? (Assumptions T1-T4.)

These are assumptions we made so the demo could be built. Please confirm or correct each one (details in `docs/assumptions.md`).

1. Is **exactly 30 days away** "ready for delivery", or "more than 30 days"? (We treat 30 as Ready.)
2. Which statuses do you really use? We have Booked, In Transit, Delivered, On Hold, Cancelled.
3. Should a **delivered job** ever go back? (We allow it only for people with the "reopen" permission.)
4. Who may see **balances and storage fees**? (Managers and Admins; not Staff.)
5. **Storage fee:** is it counted from the **pickup date** (our assumption, from the client's sheet) and charged **every month** (month = 30 days, a part month counts as a full month)? Is the rate **0.6 per CF** on the **A-CF**, and does "30 days" mean the fee starts on day 31?
6. Do you need customer **e-mail or address**, **documents/photos**, **e-mail or SMS notifications**? (Not built. Invoices are built: see section L.)
7. How long must we **keep records**, especially the audit log? (Now: forever.)
8. Is **US date order (month/day/year)** right for the sheets you import?

_Invoices and statements (versions 1.10 - 1.21), please confirm:_

9. **Company** on an invoice: is it the same as the **Broker** on our jobs? (We assume yes.)
10. **Who owes whom:** a positive Total Due (Bal. Due total minus CF Pay) means the **carrier owes the company**, a negative one means the **company owes the carrier**. Is that the right way round for your business?
11. **Paid / adjustment** is **added** to Total Due (as in the old application): money already paid is typed as a **minus**. Is that how you want it?
12. **Company invoices made from a driver invoice** start with **Labor, Pads and Paid at 0** (the old application copied them to every company invoice, counting one cost several times). Do you agree?
13. Is there a **10% carrier fee** anywhere? (The old application's print said "Carrier Fee (10%)", but its screens used CF x Rate. We use CF x the job's own Carrier rate.)
14. A saved invoice is a **snapshot** (editing a job later does not change it) and its number is never reused after a void. Do you want invoices to be deletable, or is voiding enough? (Voiding.)
15. After importing the old data, may the old application be switched off?

## Sign-off

| | Name | Date | Signature |
|---|---|---|---|
| Accepted as tested | | | |
| Accepted after the fixes listed below | | | |
| Not accepted (reasons below) | | | |

Fixes needed:

&nbsp;

&nbsp;
