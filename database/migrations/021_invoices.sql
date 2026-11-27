-- Saved invoices (v1.16.0, "saved driver invoice"). A Carrier Statement can be saved as a numbered invoice (DI-1, DI-2, ...), a
-- SNAPSHOT of what the statement showed that day: the lines below keep the CF, rate, fee and balance as they were, so editing a job later
-- never changes an invoice already given to a carrier. Only adds two tables; no existing table or job is touched.
--   type + seq      the number: type DI (driver / carrier invoice) now, CI (company invoice) in a later version; seq counts per type
--   subtotal        the Bal. Due total of the lines;  carrier_fee = the CF Pay total (rate x A-CF per line)
--   labor, pads     subtracted;  paid_amount is ADDED (a minus takes money off), exactly as on the statement
--   total           subtotal - carrier_fee - labor - pads + paid_amount (can be negative: then the company owes the carrier)
--   paid_on         the day the invoice was settled (empty = still open);  voided_at / void_reason = cancelled, its jobs can be invoiced again
-- invoice_lines.job_id has no foreign key on purpose: a line must survive even if the job is later removed, and old invoices can be imported.

CREATE TABLE invoices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type CHAR(2) NOT NULL DEFAULT 'DI',
    seq INT UNSIGNED NOT NULL,
    invoice_date DATE NOT NULL,
    carrier VARCHAR(150) NULL,
    driver VARCHAR(150) NULL,
    broker VARCHAR(100) NULL,
    driver_phone VARCHAR(30) NULL,
    period_from DATE NULL,
    period_to DATE NULL,
    total_cf DECIMAL(12,2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
    carrier_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
    labor DECIMAL(12,2) NOT NULL DEFAULT 0,
    pads DECIMAL(12,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    remarks TEXT NULL,
    paid_on DATE NULL,
    voided_at DATETIME NULL,
    void_reason VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT uq_invoices_number UNIQUE (type, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_invoices_date ON invoices (invoice_date);
CREATE INDEX idx_invoices_status ON invoices (voided_at, paid_on);

CREATE TABLE invoice_lines (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    job_id INT UNSIGNED NULL,
    job_number VARCHAR(100) NOT NULL DEFAULT '',
    status VARCHAR(20) NULL,
    customer VARCHAR(255) NOT NULL DEFAULT '',
    phone VARCHAR(30) NULL,
    from_location VARCHAR(255) NOT NULL DEFAULT '',
    to_location VARCHAR(255) NOT NULL DEFAULT '',
    broker VARCHAR(100) NULL,
    carrier VARCHAR(150) NULL,
    driver VARCHAR(150) NULL,
    cf DECIMAL(12,2) NULL,
    rate DECIMAL(10,2) NULL,
    fee DECIMAL(12,2) NULL,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    remarks TEXT NULL,
    CONSTRAINT fk_invoice_lines_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_invoice_lines_invoice ON invoice_lines (invoice_id, sort_order);
CREATE INDEX idx_invoice_lines_job ON invoice_lines (job_id);
