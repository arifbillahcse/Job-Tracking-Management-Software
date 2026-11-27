-- Drivers (v1.19.0): the phone and licence number of the drivers who carry jobs. A driver is the same thing as the job's Driver name:
-- they are matched by NAME (ignoring letter case), with no foreign key, so existing jobs and invoices keep working and a driver with no
-- record here simply has no details (like the companies, migration 023). Only adds a table and a column; nothing existing is changed.
--   invoices.driver_license  a copy of the driver's licence number taken when an invoice is saved (the phone is already copied as
--                            driver_phone), so changing a licence later does not change an invoice already given out

CREATE TABLE drivers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    license VARCHAR(50) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT uq_drivers_name UNIQUE (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE invoices ADD COLUMN driver_license VARCHAR(50) NULL;
