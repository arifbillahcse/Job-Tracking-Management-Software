-- Companies (v1.18.0): the address, phone, DOT and MC number of the companies jobs are done for. A company is the same thing as the job's
-- Broker: they are matched by NAME (ignoring letter case), with no foreign key, so existing jobs, trips and invoices keep working and a
-- broker that has no company record here simply has no details. Only adds a table and a column; nothing existing is changed.
--   invoices.company_info  a JSON copy of the company's details taken when an invoice is saved, so changing an address later does not
--                          change an invoice that was already given out (same idea as the saved lines)

CREATE TABLE companies (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address VARCHAR(190) NULL,
    city VARCHAR(100) NULL,
    phone VARCHAR(30) NULL,
    dot_number VARCHAR(30) NULL,
    mc_number VARCHAR(30) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT uq_companies_name UNIQUE (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE invoices ADD COLUMN company_info TEXT NULL;
