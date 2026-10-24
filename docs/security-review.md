# Security review (Phase 7)

Scope: the code in this repository as of Phase 7, run on PHP 8.3 with SQLite in tests and a local dev server. This is a **self-review with automated checks, not a penetration test**. Findings below were found and verified by running the code, not by reading it alone.

## What was checked and how

| Area | Result | Evidence |
|---|---|---|
| Access control | Every route has exactly one access rule; deny by default; no state change over GET; no public POST except sign-in | `php cli/route-audit.php` (52 routes), `RouteAudit` unit tests, role x route sheet in `tests/phase4.php`, `docs/permission-matrix.md` |
| Broken access by ID | Import batches are owner-scoped; user/role management refuses actions above your own permissions | Phase 4 and 5 suites |
| CSRF | Every POST needs a token; cross-origin POSTs are refused; all 52 routes tested without a token | `tests/phase7.php` ("every POST route refuses...") |
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
5. Have the real MySQL deployment re-run the test suite's smoke scripts against a staging copy.
