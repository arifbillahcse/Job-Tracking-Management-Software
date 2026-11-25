# Invoice Manager for Moving Company

A web-based invoice management system built for moving companies. It manages companies, drivers, and two invoice types — company invoices and driver invoices — with PDF/print export and CSV data export.

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | PHP 8+ |
| Database | MySQL 5.7+ / MariaDB |
| Frontend | Vanilla JavaScript, HTML5, CSS3 |
| PDF Generation | Browser print dialog (Save as PDF) via `invoice-print.php` |
| DB Access | PDO with prepared statements |

No frameworks or build tools are required.

---

## Features & Functions Overview

The system is organized around a two-invoice workflow: a **Driver Invoice** is created first to record all jobs a driver completed for one or more companies. From there, Company Invoices can be generated automatically — one per company — pre-filled with the relevant jobs and the driver already linked. Remarks, pads, and labor cost entered on the driver invoice are automatically copied into the generated company invoice. This eliminates double-entry and keeps both invoice types in sync.

### Pages & Modules

- **Dashboard** — at-a-glance stats (total companies, drivers, invoices) with a Recent Invoices feed sorted by date
- **Companies** — create, edit, delete, and search company records (name, address, city, phone, DOT #, MC #)
- **Drivers** — create, edit, delete, and search driver records (name, phone, license #)
- **Invoice / Driver** — create and manage invoices billed to a driver; each line item captures job #, company, customer name, customer phone, from/to location, cubic feet, rate, balance due, new balance, and remarks; a 10% carrier fee is applied automatically to the subtotal
- **Invoice / Company** — create and manage invoices billed to a company; same line-item structure with driver reference instead of company; can be created manually or generated automatically from a Driver Invoice

### Key Functions

| Function | Where | Description |
|----------|-------|-------------|
| Create / Edit / Delete | All invoice pages | Full CRUD on both Driver and Company Invoices with an inline multi-job form |
| **Generate Company Invoices** | Invoice / Driver | One click on any Driver Invoice row (or inside its view modal) groups its jobs by company and creates one Company Invoice per unique company, with the driver, remarks, pads, and labor cost pre-filled |
| Auto-increment Invoice # | System-generated | Invoice numbers (`DI-N` for driver, `CI-N` for company) are assigned automatically by the database |
| View Invoice | All invoice pages | Opens a formatted invoice detail modal matching the printable layout |
| Print / Save as PDF | All invoice pages | Opens a dedicated print page (`invoice-print.php`) in a new tab; use the browser's "Save as PDF" option to download |
| CSV Export | Header (any page) | Downloads a ZIP of four CSV files covering all companies, drivers, and both invoice types |
| **Search / Filter** | All list pages | Live search across invoice tables and entity lists (see details below) |
| **Collapsible Totals Panel** | Invoice / Driver, Invoice / Company | Expandable panel above the table showing each invoice's total and a grand sum for the current filtered view |
| Pagination | All invoice tables | Tables paginate at 30 rows per page with prev/next controls |
| Change Password | Header (any page) | Inline modal to update the admin password; enforces bcrypt hashing |
| Authentication | All pages | Session-based login guards every page and API endpoint; unauthenticated requests are redirected or receive a `401` JSON response |

### Search & Filter

| Page | Filter Fields |
|------|---------------|
| Invoice / Driver | Customer name, Customer phone |
| Invoice / Company | Customer name, Customer phone |
| Drivers | Full name, Phone, License # |
| Companies | Company name, Phone, DOT # |

All filters are applied client-side in real time. The invoice page filters also update the Collapsible Totals Panel to reflect only the currently visible invoices.

### Date Display

All dates are stored in the database in ISO 8601 format (`YYYY-MM-DD`) for correct sorting. They are displayed throughout the UI, CSV exports, and the print template in `MM-DD-YYYY` format.

---

## Project Structure

```
invoice-manager-for-moving-company/
├── api/
│   ├── change-password.php   # POST: update admin password
│   ├── clear.php             # POST: wipe all data (danger)
│   ├── companies.php         # CRUD for companies
│   ├── drivers.php           # CRUD for drivers
│   ├── export.php            # GET: download ZIP of CSVs
│   ├── inv-company.php       # CRUD for company invoices + line items
│   └── inv-driver.php        # CRUD for driver invoices + line items
├── assets/
│   ├── css/style.css         # All styles
│   └── js/
│       ├── app.js            # Page-specific logic (formatDate, filters, totals panel)
│       ├── data.js           # Shared data layer (API calls, global arrays)
│       └── utils.js          # Shared utilities (toast, esc, pagination)
├── config/
│   └── db.php                # DB credentials, PDO factory, jsonOut/jsonIn helpers
├── includes/
│   ├── auth.php              # Session guard for page routes
│   ├── auth-api.php          # Session guard for API routes (returns 401 JSON)
│   ├── header.php            # Shared HTML header + nav
│   └── footer.php            # Shared HTML footer + script tags
├── schema.sql                # Full database schema — run once to set up
├── create-admin.php          # One-time admin account creation (delete after use)
├── login.php                 # Login page
├── logout.php                # Session destroy + redirect
├── dashboard.php             # Dashboard overview
├── companies.php             # Companies management page
├── drivers.php               # Drivers management page
├── inv-company.php           # Company invoices page
├── inv-driver.php            # Driver invoices page
└── invoice-print.php         # Standalone print/PDF page (opened in new tab)
```

---

## Setup

### Requirements

- PHP 8.0 or higher with the `pdo_mysql` and `zip` extensions enabled
- MySQL 5.7+ or MariaDB 10.3+
- A web server (Apache, Nginx, or PHP's built-in server for local development)

### 1. Create the database

```bash
mysql -u root -p < schema.sql
```

This creates the `invoice_manager` database and all required tables. Alternatively, run the contents of `schema.sql` in phpMyAdmin.

> **Existing installations:** The API endpoints automatically run `ALTER TABLE … ADD COLUMN IF NOT EXISTS` migrations on startup, so upgrading an existing database does not require manually re-running the schema.

### 2. Configure the database connection

Edit `config/db.php` and update the four constants to match your environment:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'invoice_manager');
```

### 3. Create the first admin account

Navigate to `create-admin.php` in your browser:

```
http://your-server/create-admin.php
```

Fill in a username and password (minimum 8 characters). The page is only accessible when no admin user exists yet.

> **Important:** Delete `create-admin.php` from your server immediately after creating the admin account.

### 4. Log in

Go to `login.php` (or the root URL, which redirects there) and sign in with the credentials you just created.

---

## Database Schema

```
companies
  id, name, address, city, phone, dot_number, mc_number, created_at

drivers
  id, first_name, last_name, phone, license, created_at

company_invoices
  id, company_id (FK → companies), date, subtotal, carrier_fee, total,
  paid, paid_date, invoice_remarks, labor_cost, pads, created_at

company_invoice_items
  id, invoice_id (FK → company_invoices), sort_order, job_number, driver_id,
  customer_name, phone, from_location, to_location, cubic_feet, rate,
  balance_due, new_balance, remarks

driver_invoices
  id, driver_id (FK → drivers), date, subtotal, carrier_fee, total,
  paid, paid_date, invoice_remarks, labor_cost, pads, created_at

driver_invoice_items
  id, invoice_id (FK → driver_invoices), sort_order, job_number, company_id,
  customer_name, phone, from_location, to_location, cubic_feet, rate,
  balance_due, new_balance, remarks

users
  id, username, password_hash, created_at
```

Invoice line items cascade-delete when their parent invoice is deleted. Company invoices cascade-delete when their company is deleted; driver invoices cascade-delete when their driver is deleted.

---

## Invoice Pricing

For both invoice types the totals are calculated as follows:

```
Job total   = cubic_feet × rate
Subtotal    = sum of all job totals
Carrier fee = subtotal × 10%
Total due   = subtotal + carrier_fee + labor_cost + pads + paid
```

All numeric fields (CF, Rate, Balance Due, New Balance, Paid, Labor Cost, Pads) accept negative values and decimals.

---

## API Endpoints

All endpoints require an authenticated session. Unauthenticated requests return `401 {"error":"Unauthorized"}`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `api/companies.php` | List all companies |
| POST | `api/companies.php` | Create company |
| PUT | `api/companies.php?id=N` | Update company |
| DELETE | `api/companies.php?id=N` | Delete company |
| GET | `api/drivers.php` | List all drivers |
| POST | `api/drivers.php` | Create driver |
| PUT | `api/drivers.php?id=N` | Update driver |
| DELETE | `api/drivers.php?id=N` | Delete driver |
| GET | `api/inv-company.php` | List all company invoices (includes line items) |
| POST | `api/inv-company.php` | Create company invoice |
| PUT | `api/inv-company.php?id=N` | Update company invoice |
| DELETE | `api/inv-company.php?id=N` | Delete company invoice |
| GET | `api/inv-driver.php` | List all driver invoices (includes line items) |
| POST | `api/inv-driver.php` | Create driver invoice |
| PUT | `api/inv-driver.php?id=N` | Update driver invoice |
| DELETE | `api/inv-driver.php?id=N` | Delete driver invoice |
| GET | `api/export.php` | Download ZIP of all data as CSV files |
| POST | `api/change-password.php` | Change the logged-in admin's password |
| POST | `api/clear.php` | Delete all companies, drivers, and invoices |

---

## Local Development

PHP's built-in server works for quick local testing:

```bash
cd invoice-manager-for-moving-company
php -S localhost:8000
```

Then open `http://localhost:8000` in your browser.

Make sure your local MySQL instance is running and `config/db.php` points to it.

---

## Security Notes

- All database queries use PDO prepared statements — no raw string interpolation.
- Passwords are hashed with `bcrypt` via `password_hash()`.
- All API routes enforce session authentication and return JSON errors for unauthorized access.
- HTML output uses `htmlspecialchars()` / `esc()` helpers to prevent XSS.
- `create-admin.php` blocks access once any admin exists; delete it after first use.
- `api/clear.php` permanently deletes all data — restrict or remove it in production if not needed.
