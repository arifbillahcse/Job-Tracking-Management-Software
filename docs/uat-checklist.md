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
| C1 | In **Ready for Delivery**, click **Move to In Transit** on a job's row (or open the job and click it there). | A question names today's date as the loaded date. After OK there is **no form**: the job is **In Transit** and shows today as **Loaded date**. | ☐ | |
| C2 | Open **In Transit**. Under **Edit** on that job add a carrier, driver and driver phone (optional). | The job is listed with its loaded date; once saved it also shows the driver and a tap-to-call phone number. | ☐ | |
| C3 | On the **In Transit** list click **Mark as Delivered** next to the job. | After OK the job is **Delivered**, has today as **Delivery date**, and is under Delivered. The Dashboard counts have moved (In Transit down by 1, Delivered up by 1). | ☐ | |
| C4 | Scroll to the job's history. | Every step with date, who did it, and any note. | ☐ | |
| C5 | Put a job **On hold** (a reason is required). | It leaves Ready for Delivery but is still in All Jobs. **Resume** brings it back. | ☐ | |
| C6 | Click **Move to In Transit** twice quickly (double-click). | Only one move is recorded in the history. | ☐ | |
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
| H11 | Open a job that is on a trip, then a job that is on none and use **Add to a trip**; also try **New trip with this job**. Look at the dashboard. | The first job lists its trips. The second is added to the chosen trip (no amounts yet) or opens a new trip form with its number filled in. The dashboard has a "Foreman trips" card (last 30 days). | ☐ | |

## G. Phone and tablet

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| G1 | Open the site on a phone. | Lists show as cards, nothing scrolls sideways, buttons are easy to tap. | ☐ | |
| G2 | Open the menu (top-left button) and move around. | The menu slides in and out. | ☐ | |
| G3 | Create a job and move it to In Transit from the phone. | It works as on a computer. | ☐ | |

## Questions we still need your answer on

_Foreman trips:_ what do **BH**, **Total to BH**, **Addio**, **Job Beg** and the **15%** mean, and how are **Grand Total, BH Total, Paid and Balance** worked out? Is Total expenses = Diesel + Hotels + Helper + Misc + Commission + Extra? (Assumptions T1-T4.)

These are assumptions we made so the demo could be built. Please confirm or correct each one (details in `docs/assumptions.md`).

1. Is **exactly 30 days away** "ready for delivery", or "more than 30 days"? (We treat 30 as Ready.)
2. Which statuses do you really use? We have Booked, In Transit, Delivered, On Hold, Cancelled.
3. Should a **delivered job** ever go back? (We allow it only for people with the "reopen" permission.)
4. Who may see **balances and storage fees**? (Managers and Admins; not Staff.)
5. **Storage fee:** is it counted from the **pickup date** (our assumption, from the client's sheet) and charged **every month** (month = 30 days, a part month counts as a full month)? Is the rate **0.6 per CF** on the **A-CF**, and does "30 days" mean the fee starts on day 31?
6. Do you need customer **e-mail or address**, **invoices**, **documents/photos**, **e-mail or SMS notifications**? (Not built.)
7. How long must we **keep records**, especially the audit log? (Now: forever.)
8. Is **US date order (month/day/year)** right for the sheets you import?

## Sign-off

| | Name | Date | Signature |
|---|---|---|---|
| Accepted as tested | | | |
| Accepted after the fixes listed below | | | |
| Not accepted (reasons below) | | | |

Fixes needed:

&nbsp;

&nbsp;
