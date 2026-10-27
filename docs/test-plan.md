# Test plan (Phase 8)

## Goal
Show, with evidence a reader can reproduce, that the software does what the client asked for, refuses what it should refuse, and keeps working with realistic amounts of data.

## Levels

| Level | What it proves | How to run | Count |
|---|---|---|---|
| Unit and integration | Rules, services, repositories, controllers and views, called in-process on in-memory SQLite using the real migrations | `php tests/run.php` | 1644 checks |
| Release archive | The built archive contains the right files and nothing secret, and runs on its own: migrate, create administrator, sign in, create a job, back up, reset a password | `tests/release-check.sh` | 44 checks |
| Route audit | Every route has exactly one access rule; nothing changes data over GET | `php cli/route-audit.php` | 52 routes |
| HTTP smoke suites | The real application over real HTTP: cookies, redirects, headers, multipart uploads, downloads | `tests/smoke*.sh` (see README) | 337 checks in 6 suites |
| Browser walkthrough | The client's workflow clicked through in Chromium, desktop and phone, as Admin and Staff | `node tests/e2e/uat.js` (needs Playwright) | 24 steps |
| Scale test | Page, export and import times with 20,000 (and 100,000) jobs | `tests/perf.sh [jobs]` | 21 measurements |
| Accessibility and layout audit | Text contrast (WCAG AA), horizontal overflow and console errors on every screen at 360, 768 and 1280 px | scripts used in Phase 6/7 (not shipped; see `docs/ui-guide.md`) | 15 screens |
| User acceptance (UAT) | The client's own people confirm it fits their work | `docs/uat-checklist.md` | 48 scenarios |

**Everything automated, in one command, on a throw-away database:** `tests/all.sh --perf --e2e`.

## Test data
* Unit tests build their own data and use a fixed clock (`Clock::fake`) so date boundaries are exact and repeatable.
* HTTP suites run against a database seeded with `php cli/seed-demo.php` (60 jobs spread over every section) and three accounts (Admin, Manager, Staff). They create jobs with unique prefixes and delete them afterwards.
* `tests/support/perf_seed.php` creates up to hundreds of thousands of jobs deterministically.

## What the automated tests are deliberately aimed at
1. **Business rules from the client's sheet:** day 30 is Ready, day 31 is More Than 30 Days; jobs move by themselves as days pass; changing the day limit re-sorts jobs; storage fee is typed by hand.
2. **Permission boundaries:** each role against each route (generated matrix in `docs/permission-matrix.md`), including attempts by direct URL and by forged POST.
3. **Hostile or awkward input:** markup, SQL, formulas (`=cmd|...`), accents, emoji, 5,000-character notes, impossible dates, money such as `12.345`, `1e3`, `$1,200`, double-clicks, back button after sign-out.
4. **Mutation checks:** for the riskiest safeguards the code was deliberately broken to confirm the tests fail (about 20 mutations across Phases 5-8, e.g. removing a permission check, the CSRF check, the duplicate check, the zip-bomb limit, the money rule). A test that cannot fail is not counted.

## Defects found and fixed in this phase
| # | Found by | Defect | Fix |
|---|---|---|---|
| 1 | Edge-case suite | Money such as `12.345` was silently rounded to `12.35`, and `1e3` was accepted as 1,000 | New `money` rule: plain amount, at most two decimals, no exponent or symbols (form and import) |
| 2 | Phase 7 review | Unlimited guessing of the current password on My Account | Counts toward the sign-in lockout (see `docs/security-review.md`) |
| 3 | Phase 6 audit | Sidebar unreadable on desktop; text contrast failures | Fixed in Phase 6 |
| 4 | Phase 5 smoke | Small upload without CSRF token returned 413 instead of 403 | Fixed in Phase 5 |
| 5 | Edge-case suite | A PHP warning ("Array to string conversion") when a URL or form sends an array where text is expected (`?sort[]=x`, `job_number[]=x`) in the job, user and audit lists and in password validation | `Request::queryStr()` / `queryInt()` used everywhere; test sends arrays to every list and form and fails on any warning |
| 6 | Review of the tests themselves | Session idle limit (30 min), absolute limit (8 h) and the browser-fingerprint check had **no automated test** (the harness bypasses `Session::start`) | Tests added and proven to fail when each safeguard is removed |
| 7 | Code scan for SQL built from strings | `ImportRepository::update` took column names from array keys without a whitelist (only internal callers, so not exploitable today) | Whitelist added; test proves unknown columns are refused |

## Measured performance (SQLite, PHP built-in server, one request at a time)
| With | 20,000 jobs | 100,000 jobs |
|---|---|---|
| Any list page, sort, filter or search | 0.02 - 0.05 s | 0.10 - 0.27 s |
| Dashboard | 0.05 s | 0.20 s |
| Export all as CSV / Excel | 0.2 s / 1.4 s | refused politely (caps: 50,000 CSV, 20,000 Excel) |
| Preview + save a 5,000-row import | 0.2 s + 0.3 s | same |

These numbers show the queries are indexed and pages never load everything; they are **not a promise for production**. MySQL on a shared host, a busy server and many simultaneous users will differ. Searching for text in the middle of a name (`LIKE '%x%'`) cannot use an index, so it slows down roughly in proportion to the number of jobs; at the volumes above it is still instant. Re-run `tests/perf.sh` against a staging copy before relying on it.

## Not covered (and why)
* **MySQL/MariaDB:** none was available. The migrations are written for MySQL 8 / MariaDB 10.5 and avoid SQLite-only syntax, but they have not been run on one. First task of Phase 9.
* **Microsoft Excel and LibreOffice** opening the generated `.xlsx`: not available. The files are valid XML in a valid zip and read back correctly.
* **Safari, Firefox, Edge, real phones and tablets:** only Chromium with device emulation.
* **Screen readers and keyboard-only use on real assistive technology:** contrast, focus rings, labels, skip link and landmarks are verified automatically; real screen-reader use is not.
* **Many simultaneous users / load:** the scale test is one request at a time.
* **Penetration test by an independent party.**
