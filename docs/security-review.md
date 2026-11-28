# Security review (Phase 7)

Scope: the code in this repository as of Phase 7, run on PHP 8.3 with SQLite in tests and a local dev server. This is a **self-review with automated checks, not a penetration test**. Findings below were found and verified by running the code, not by reading it alone.

## What was checked and how

| Area | Result | Evidence |
|---|---|---|
| Access control | Every route has exactly one access rule; deny by default; no state change over GET; no public POST except sign-in | `php cli/route-audit.php` (52 routes), `RouteAudit` unit tests, role x route sheet in `tests/phase4.php`, `docs/permission-matrix.md` |
| Broken access by ID | Import batches are owner-scoped; user/role management refuses actions above your own permissions | Phase 4 and 5 suites |
| CSRF | Every POST needs a token; cross-origin POSTs are refused; every POST route tested without a token | `tests/phase7.php` ("every POST route refuses...") |
| SQL injection | Native prepared statements only; sort columns and filters come from whitelists; LIKE is escaped; audit filters must look like identifiers | Phase 2-7 suites, injection strings in smoke tests |
| XSS | Every dynamic value goes through `e()`; a scan of all `<?=` in `views/` found only literal strings and helpers that escape. Company name, audit text, user agent, imported cells with markup are escaped | `tests/phase7.php`, smoke tests |
| CSP | `script-src 'self'`, no inline script or style anywhere, `frame-ancestors 'none'` | headers on every response incl. errors |
| Sessions | Strict mode, HttpOnly, SameSite, id regenerated on login, idle (30 min) and absolute (8 h) limits, other sessions end on password change | Phase 1 suites |
| Brute force | Per-account lock (5 failures / 15 min), per-IP throttle, constant-time-ish unknown-user path | Phase 1; **see finding 1** |
| Uploads | Content-based type check, random names, outside web root, size/row/column/zip limits, XXE and zip-bomb defences, formula neutralising | Phase 5 suite + mutations |
| Secrets in logs | Passwords, tokens, secrets redacted (also nested) before the audit row is written; temporary passwords never logged | Phase 4 and 7 suites |
| Error handling | Generic error pages; stack traces only when `APP_DEBUG` is on, which is ignored in production | Phase 1 suite |
| Private files over HTTP | `.env`, `.git`, `app/`, `storage/`, `database/`, `tests/`, `docs/`, path traversal variants return 404/403 on the built-in server | `tests/smoke-audit.sh` |
| Redirects / Host header | Return paths must be internal; a forged Host header does not reach redirects | `tests/smoke-audit.sh`, Phase 1 |

## Findings

1. **Fixed - unlimited guessing of the current password.** The "Change password" form on My Account compared the typed current password without counting failures. Someone holding a hijacked session could guess the password without limit. Wrong answers now count towards the same lockout as sign-in; on lock the session is ended and `account_locked` is audited. Tests: `tests/phase7.php` (verified to fail when the counting is removed).
2. **Fixed (Phase 8) - array parameters:** sending `?sort[]=x` or `job_number[]=x` raised PHP warnings (no data exposure, but noisy logs and sloppy input handling). All query values now go through `Request::queryStr()` / `queryInt()`.
3. **Hardened (Phase 8):** `ImportRepository::update` now whitelists column names (they were only ever set by internal code). A scan of every `{$...}` interpolated into SQL found all others come from fixed lists or regex-validated identifiers.
4. **Test gap closed (Phase 8):** session idle/absolute limits and the browser-fingerprint check are now covered by tests that fail when the safeguard is removed.
5. No other defects found in the areas above.

## Version 1.2 review: foremen, trips and expenses

Reviewed by reading the new code and by automated checks that fail when the safeguard is removed (`tests/phase12.php`, "Trips: security").

| Area | Result |
|---|---|
| **Access control** | Every new route declares a permission (`php cli/route-audit.php`, 71 routes, no problems). Viewing, changing, expenses, export and foremen are separate permissions. Without `trips.financials` the expense amounts are removed in the service before any template sees them, and a save keeps amounts the user could not see. A forged money value is ignored |
| **CSRF** | All trip, foreman and "add to trip" POST routes refuse a forged token and change nothing (tested route by route) |
| **XSS** | Trip, foreman, customer and "from" text is escaped on the trip page, edit form, list, report, foremen page, new-trip form and job page; tested with `<script>` and `"><img onerror>` payloads |
| **SQL injection** | All values are bound parameters; the only SQL built from text is column names from fixed lists. Hostile strings in every search, filter, page and form field create no trip, change no job and break no page |
| **CSV injection** | Trip and report exports neutralise cells that start with `= + - @` exactly as the job export does |
| **Mass assignment / input size** | Only whitelisted fields are read from a row. A trip holds at most 60 rows and a request with more than 120 rows is refused before validation (denial-of-service guard, and PHP's `max_input_vars` would otherwise cut a big form off silently) |
| **Data protection** | Exporting takes data out, so it needs its own permission and is audited with the filters used. Trips are soft-deleted; foremen are deactivated, never deleted |
| **Findings** | No defect found. One design risk was removed before release: a bigger-than-1000-field form losing rows silently (now a clear refusal) |

Not checked: the same items as above (real Apache/Nginx, TLS, MariaDB behaviour, browsers other than Chromium, an independent penetration test).

## Version 1.4 review: "Keep me signed in"

Reviewed by reading the code and by tests that fail when the safeguard is removed (`tests/phase14.php`, and a real-browser check).

| Area | Result |
|---|---|
| **Secret handling** | 96 random bits for the selector and 256 for the secret. Only a SHA-256 hash of the secret is stored and compared in constant time; the secret is nowhere in the database (tested) |
| **Cookie** | HttpOnly, SameSite=Lax, Secure over HTTPS, scoped to the application path (checked in a real browser) |
| **Lifetime** | Fixed from the day of issue, never extended by use; expired tokens are deleted on use and on the next sign-in; at most 10 per user |
| **Revocation** | Sign-out, "Forget all devices", a password change (also checked by a marker stored with the token), deactivation, locking, removal of the user, and a wrong secret (treated as guessing: the token is deleted and the event audited) all stop it. Each is tested |
| **Input** | Malformed, over-long and SQL-looking cookie values are ignored without error (tested) |
| **Sessions** | Signing in from a cookie creates a fresh session id and a new CSRF token, exactly like a password sign-in |
| **Residual risk** | A stolen cookie works until it expires or is revoked (that is what "remember me" means). It is not bound to an IP or browser string (X3). Someone who steals the remembered computer stays signed in until the user signs out elsewhere or forgets devices |
| **Findings** | No defect found. One mutation (ignoring an inactive user in the cookie check) was first missed by the tests and is now caught |

## Versions 1.5 - 1.22 review: statements, invoices, companies, drivers, import and export

What changed since the last review is new money-bearing pages, new tables, an import command and downloads. Each was checked by reading the code, by a pattern search over every view and service, and by tests; where a mutation or an attack string could have slipped through, a test now catches it.

| Area | What was checked | Result |
|---|---|---|
| **CSRF** | A safety-net test (`tests/phase40.php`) walks the registered route table: **every POST route** (about 50) answers 403 to a forged token and changes no row in the jobs, trips, people, roles, settings, invoices, companies, drivers, payments or audit tables | Pass. It covers future routes automatically |
| **Access control** | `php cli/route-audit.php` (every route has exactly one rule; nothing that changes data answers GET; no GET route has a data-changing name). New permissions `invoices.view`, `invoices.manage`, `companies.manage`, `drivers.manage`; saving an invoice also needs the right to see money; the invoice export needs `jobs.export` as well. Staff get 403 on invoices, export, companies, drivers, the statement, settings, users, audit and roles | Pass |
| **Money visibility** | The carrier rate, balance and every invoice figure are removed server-side for people without the financial permission (a posted rate from Staff is ignored); the statement, invoices and export are behind permissions, not just hidden in the menu | Pass |
| **Output (XSS)** | A search over all views for unescaped output found only the layout's `$content` (already-rendered, escaped views). Names, remarks, addresses, search terms and company details are escaped; tests post `<script>` strings to every new form and search and check they are never printed raw. The company details copied onto an invoice are decoded from JSON and escaped | Pass |
| **CSP** | No inline `style=`, `<script>` or `on...=` attribute in any view (the only script tags are the self-hosted and CDN Bootstrap and `app.js`); new behaviour (print button, driver phone fill) is in `app.js`; new styles are classes in `app.css` | Pass |
| **SQL** | Every value is a bound parameter. The only text put into SQL is constant (column names from fixed lists, the order of `IN (?, ?)` marks, integer `LIMIT` / `OFFSET`). **Search terms** in Companies, Drivers and Invoices now escape `%` and `_` (`LIKE ? ESCAPE '!'`) like the job search: before this review they were used as wildcards (harmless, no injection, but a `%` matched everything) | Fixed; tests added |
| **CSV / spreadsheet injection** | Statement, invoice and export CSVs: text cells that start with `= + - @` get an apostrophe; money and CF columns are written as plain numbers; the file names are made from the date or from the names with every character outside A-Z a-z 0-9 replaced (no header injection) | Pass |
| **Invoice integrity** | Totals are recomputed on the server from the jobs; nothing the browser posts (amounts, lines) is trusted except the Labor, Pads and Paid typed by the person. A saved invoice is a snapshot; numbers are made inside a transaction with a unique key and a retry, so two people cannot take the same number; a voided invoice's number is not reused; a job cannot be on two open invoices of one kind by accident | Pass |
| **Import command** (`php cli/import-old.php`) | It is a command-line tool (a web request cannot reach it). The old database is read with `SELECT` only; the password is asked for without echo (or taken from `OLD_DB_PASSWORD`) and is never logged or stored; the database, user, host and port arguments are checked against a strict pattern before they go into a connection string (**added in this review**). A dry run writes nothing; `--apply` is all-or-nothing and safe to repeat. Old **users and password hashes are never imported** | Pass |
| **The old application's source in the repository** | The folder `Old_Application_source_code/` holds PHP files and a `config/db.php`. It is not part of the release archive, the root `.htaccess` now refuses it, and the document root must be `public/`. **If that `config/db.php` ever contained real credentials, they are in the repository's history: change them** | Action for the owner |
| **Personal data** | Invoices, their lines, exports and backups hold customer names and phone numbers. Exports are permission-gated and audited; backups stay off the web root | As before |

**Findings of this review:** one low-severity issue (search wildcards, fixed), one hardening (import-command arguments, added), one defence in depth (`.htaccess` rule for the old folder). No defect that exposes data or lets a request change data without a valid token or permission was found.

## Accepted risks and things this review could not check

* **Not tested here:** Apache/Nginx configuration (`.htaccess` rules are present but only the PHP built-in server was available), TLS (HSTS and the Secure cookie flag are implemented and only apply over HTTPS in production), MySQL/MariaDB behaviour (tests run on SQLite), real browsers other than Chromium, screen readers.
* **Dependencies:** Composer has no runtime packages. Bundled front-end files: Bootstrap 5.3.3 and the Inter font, both self-hosted; update them by replacing the files in `public/assets/`.
* **Sessions are file based** under `storage/sessions` (outside the web root). Fine for one server; use a shared store if you ever run several.
* **IP addresses** are taken from the connection. Behind a proxy or CDN set `TRUST_PROXY=true` only if that proxy overwrites `X-Forwarded-*`, otherwise throttling would see the proxy's address.
* **Audit log contains personal data** (names, email addresses, IP addresses, browser strings). It is kept indefinitely by default; see assumption F4.
* **Administrators are powerful by design** (assumption D9): `users.manage` and `roles.manage` can grant permissions up to their own level.
* No multi-factor authentication, no e-mail based password reset (assumption D1), no per-user API tokens. Out of scope for the demo.

## Before going live

1. `php cli/doctor.php` must report no failures (debug off, HTTPS, MySQL, migrations, no demo accounts, writable storage, upload limits).
2. `php cli/route-audit.php` must report no problems (run it after every change to routes).
3. Point the web server's document root at `public/`, serve over HTTPS, keep `.env` mode 640.
4. Schedule `php cli/cleanup.php --apply` daily.
5. Have the real MySQL deployment re-run the test suite's smoke scripts against a staging copy, and run `php cli/verify.php` after every upgrade.
6. Run the **backup and restore drill** once (`docs/uat-checklist.md` section P, `docs/operations.md`): a backup is only proven when it has been restored.
7. If the old application's `config/db.php` ever held real credentials, change them; do not run the old application next to this software.
