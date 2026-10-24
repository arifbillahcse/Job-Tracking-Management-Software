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
| B6 | Try to create another job with the same job number. | A message that it is already in use. What you typed is still there. | ☐ | |
| B7 | Leave First name empty and save. | A message beside that field. Nothing is saved. | ☐ | |
| B8 | Type a balance of `12.345`. | A message that money can have at most two decimals. | ☐ | |
| B9 | Type a customer name with accents or another alphabet, e.g. `Zoë Müller` or `李小龍`. Save and open it. | It shows exactly as typed. | ☐ | |
| B10 | In **All Jobs**, search for part of a customer's name, then for a job number. | Only matching jobs. A note says the list is filtered; a button clears it. | ☐ | |
| B11 | Open **More filters** and try status, dates, balance. Click column headings to sort. | The list follows. The filters stay when you go to page 2. | ☐ | |

## C. Moving a job along

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| C1 | Open a Ready job, click **Dispatch**, leave the boxes empty and confirm. | The box stays open and asks for carrier, driver and phone. | ☐ | |
| C2 | Fill them in and confirm. | The job is **In Transit** with the driver's name and a tap-to-call phone number. | ☐ | |
| C3 | Click **Mark delivered**, check the date, confirm. | The job is **Delivered** and appears under Delivered. | ☐ | |
| C4 | Scroll to the job's history. | Every step with date, who did it, and any note. | ☐ | |
| C5 | Put a job **On hold** (a reason is required). | It leaves Ready for Delivery but is still in All Jobs. **Resume** brings it back. | ☐ | |
| C6 | Click **Dispatch** twice quickly (double-click). | Only one dispatch is recorded. | ☐ | |
| C7 | Edit a job: change the balance and add a note. Save. | The change is shown. The history/audit log records it. | ☐ | |
| C8 | Delete a job. Open **Trash**, restore it. | It disappears from the lists, appears in Trash, and comes back when restored. | ☐ | |
| C9 | **Storage fee**: edit a job and type a storage fee by hand. | It is saved exactly as typed; nothing changes it by itself. | ☐ | |

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

## G. Phone and tablet

| # | Do this | You should see | Pass | Notes |
|---|---|---|---|---|
| G1 | Open the site on a phone. | Lists show as cards, nothing scrolls sideways, buttons are easy to tap. | ☐ | |
| G2 | Open the menu (top-left button) and move around. | The menu slides in and out. | ☐ | |
| G3 | Create a job and dispatch it from the phone. | It works as on a computer. | ☐ | |

## Questions we still need your answer on

These are assumptions we made so the demo could be built. Please confirm or correct each one (details in `docs/assumptions.md`).

1. Is **exactly 30 days away** "ready for delivery", or "more than 30 days"? (We treat 30 as Ready.)
2. Which statuses do you really use? We have Booked, In Transit, Delivered, On Hold, Cancelled.
3. Should a **delivered job** ever go back? (We allow it only for people with the "reopen" permission.)
4. Who may see **balances and storage fees**? (Managers and Admins; not Staff.)
5. Is the **storage fee** always typed by hand, or should it be calculated later? (Now: typed by hand.)
6. Do you need **customer phone/email**, **invoices**, **documents/photos**, **e-mail or SMS notifications**? (Not built.)
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
