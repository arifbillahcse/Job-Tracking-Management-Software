# Administrator guide

For people who manage the team and the settings. Day-to-day job work is in `docs/user-guide.md`.

## What each role can do (as delivered)

| | Admin | Manager | Staff |
|---|:--:|:--:|:--:|
| Dashboard, view jobs and all sections | yes | yes | yes |
| Create and edit jobs | yes | yes | yes |
| Change status (Move to Stage, Move to In Transit, Mark as Delivered, hold, cancel) | yes | yes | yes |
| See balance and storage fee | yes | yes | no |
| Edit balance and storage fee | yes | yes | no |
| Delete and restore jobs, Trash | yes | yes | no |
| Reopen delivered or cancelled jobs | yes | yes | no |
| Import and export | yes | yes | no |
| Carrier Statement (balances, CF Pay) | yes | yes | no |
| Invoices: see, save, change, mark paid, void | yes | yes | no |
| Companies and Drivers | yes | yes | no |
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
| Company details on printed statements | Address, phone, DOT and MC number (all optional) printed at the top of the Carrier Statement and invoices. A logo is shown if the file `public/assets/img/company-logo.png` exists |
| Tracking buttons | The two web addresses behind the orange **Driver Tracking** and **Job Tracking** buttons at the top of every page. Leave one empty to hide its button. Only `http` / `https` addresses are accepted |
| Carriers | One carrier per line. While the list is empty, moving a job to Stage or In Transit asks for nothing; once it has names, a carrier from the list is required at **Stage** (or when a job goes straight to In Transit) |
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

## Companies, Drivers and Invoices

- **Companies** and **Drivers** are small lists of details (a company's address, DOT, MC; a driver's phone, licence). They are matched to the jobs **by name** (a company is the job's Broker, a driver is the job's Driver name; letter case is ignored), with no hard link, so nothing breaks if a name has no record. Renaming one can rename it on its jobs too; removing one removes only its details.
- **Invoices** are saved from the **Carrier Statement** and are **snapshots**: they keep the lines and the company / driver details they had when saved. They can be changed (date, remarks, Labor, Pads, Paid), marked paid, or **voided** with a reason; they are never deleted, and a voided invoice's number is never used again. Voiding frees the jobs so they can be invoiced again.
- Permissions: `invoices.view`, `invoices.manage`, `companies.manage`, `drivers.manage` (and `jobs.export` for the invoice export, because it is money data). Admin and Manager hold them by default.

## After pulling a new version

On the server, in the application folder, in this order:

```bash
php cli/backup.php             # a backup before anything changes the database
git pull origin main
php cli/migrate.php            # new tables / columns (it only adds; there is no rollback file)
php cli/seed.php               # adds new permissions (the Admin role gets every one)
php cli/sync-roles.php --apply # gives the Manager role the new default permissions (only ever adds)
php cli/verify.php             # runs every list, statement, invoice and export query against the real database
php cli/doctor.php             # checks the setup; the version shown should be the new one
```
Then press Ctrl+F5 in the browser, and sign out and in again (permissions are read at sign-in). If `verify.php` shows a **FAIL** line, copy the output to your developer.

## Importing the old application's data

Done once, by the administrator, with `php cli/import-old.php` (a dry run first). The steps, what is kept and what is not, are in `docs/operations.md` ("Importing the data of the old application").

## Audit Log

**Audit Log** lists sign-ins and failures, job changes, status changes, deletions, imports, exports, user/role/permission changes, settings and backups, with who, when, from which address and the before/after values. You can filter by action, person, record and dates. Nothing in the application can edit or delete these entries, and passwords are never stored in them. Look at it monthly; filter **Failed sign-in** and **Access denied**.

## Good habits

- Give each person their own account; never share logins.
- Give people the lowest role that lets them work.
- Deactivate accounts the day someone leaves.
- Treat exports and backups as confidential: they contain customer details.
- Check the **Dashboard -> Overdue** list every morning.
