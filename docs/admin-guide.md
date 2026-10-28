# Administrator guide

For people who manage the team and the settings. Day-to-day job work is in `docs/user-guide.md`.

## What each role can do (as delivered)

| | Admin | Manager | Staff |
|---|:--:|:--:|:--:|
| Dashboard, view jobs and all sections | yes | yes | yes |
| Create and edit jobs | yes | yes | yes |
| Change status (Move to In Transit, Mark as Delivered, hold, cancel) | yes | yes | yes |
| See balance and storage fee | yes | yes | no |
| Edit balance and storage fee | yes | yes | no |
| Delete and restore jobs, Trash | yes | yes | no |
| Reopen delivered or cancelled jobs | yes | yes | no |
| Import and export | yes | yes | no |
| Users, Roles & Permissions | yes | no | no |
| Audit Log, Settings | yes | no | no |

You can change this: **Roles & Permissions -> Permission matrix**. The full list of permissions and which screens each one opens is in `docs/permission-matrix.md`.

## Adding a person

1. **Users -> New user.** Enter the person's name, a **username** and a role. The username is what they type to sign in: 3 to 40 lower-case letters, numbers, dots, dashes or underscores, such as `maria.lopez`. An e-mail address is optional contact information and is not used to sign in.
2. A **temporary password** is shown **once**. Give it to the person in person or by phone (never by e-mail or chat). If you lose it, use *Reset password*.
3. At first sign-in they must choose their own password (at least 10 characters; a long phrase is best).

To remove access: **Deactivate** (keeps their name on old jobs and history) or **Delete** (hides the account; their username stays reserved). Deactivated people are signed out on their next click.

## Safeguards you will run into (on purpose)

- You can only give, change or remove permissions and roles that **you hold yourself**.
- You cannot change your own role, deactivate or delete yourself, or reset your own password here (use **My Account**, which asks for your current password).
- The system always keeps **at least one active administrator**; any change that would leave none is refused.
- After **5 wrong passwords** an account locks for 15 minutes. *Users -> person -> Unlock* clears it sooner. Wrong guesses on the "current password" box in My Account count too.
- Sessions end after **30 minutes without activity** and after **8 hours** in total.

## Roles and the permission matrix

- **New role:** *Roles & Permissions -> New role*, optionally copying an existing one. Delete a role only when nobody uses it.
- **Matrix:** roles are columns, permissions are rows. Tick, then **Save**. Changes apply on each person's next click; nobody needs to sign out.
- Two permissions are powerful: `users.manage` and `roles.manage`. Whoever holds them can create accounts and grant permissions up to their own level. Keep them for administrators.
- The built-in **Admin** role cannot be renamed or deleted and always has everything.

## Settings

**Settings** (administrators only):

| Setting | What it does |
|---|---|
| Company name | Shown in the menu, page titles and sign-in page |
| Ready-for-delivery limit | A booked job whose 1st available date is within this many days is **Ready for Delivery**; later ones are **More Than 30 Days**. Default 30. Changing it re-sorts every job immediately |
| Currency symbol | Shown before amounts |
| (Storage Fee page) | People with the **Change the storage fee rate and rules** permission (Manager by default on a new install) see a *Storage Fee* menu entry with only the fee settings below, not the rest of Settings |
| Storage fee rate | The automatic storage fee is **A-CF × rate** (default 0.6, or type cents such as `60c`). `0` turns it off |
| Storage fee after (days) | A job is charged once it has waited **more than** this many days (default 30) |
| Storage fee charged | **Every month** (default: the fee is repeated for each further 30 days, no prorating) or **once** (a single charge) |
| Storage fee counted from | The job's **pickup date** (default; 1st available date if it has none), its **1st available date**, or its **loaded date** |
| Rows per page | 10, 25, 50 or 100 |
| Business time zone | Decides what "today" means for sections, overdue flags and shown times |

Changing a storage fee setting re-calculates every open job immediately; delivered jobs keep the fee they had on delivery. A fee typed on a job (override) always wins.

Session length, lockout rules and password rules are server settings (`.env`) and are changed by whoever looks after the server.

## Foremen and trips

Five permissions control the module: **View foreman trips**, **Create, edit and delete foreman trips**, **See and edit trip expenses**, **Export trips and the foreman report to CSV** and **Manage foremen** (Admin has all, Manager has all on a new install, Staff none). On an existing install tick them for Manager under *Roles & Permissions*. Give a clerk *view + create/edit* without *expenses* to let them record trips without seeing any amounts. Everything is in the Audit Log (trip and foreman entries).

## Imports and exports

- **Import Jobs** takes `.csv` or `.xlsx` (up to 5 MB and 5,000 rows). Always read the **preview**: it shows what will be saved, what is skipped and why. Nothing is saved until you press **Import**. The *problem report* download lists skipped rows with reasons so they can be fixed and uploaded again.
- Import only **adds** jobs. A job number that already exists is skipped, never overwritten.
- Balance and storage fee are imported only if you hold the permission to edit financials; status, carrier, driver and phone only with the status permission. Otherwise the columns are ignored and the preview tells you.
- **Export** (on any job list) downloads what you are looking at: the same search, filters and sort, all pages. Exports of more than 50,000 rows (CSV) or 20,000 rows (Excel) are refused; narrow the filters.
- Dates are read as month/day/year (`3/31/2026`) or `2026-03-31`.

## Audit Log

**Audit Log** lists sign-ins and failures, job changes, status changes, deletions, imports, exports, user/role/permission changes, settings and backups, with who, when, from which address and the before/after values. You can filter by action, person, record and dates. Nothing in the application can edit or delete these entries, and passwords are never stored in them. Look at it monthly; filter **Failed sign-in** and **Access denied**.

## Good habits

- Give each person their own account; never share logins.
- Give people the lowest role that lets them work.
- Deactivate accounts the day someone leaves.
- Treat exports and backups as confidential: they contain customer details.
- Check the **Dashboard -> Overdue** list every morning.
