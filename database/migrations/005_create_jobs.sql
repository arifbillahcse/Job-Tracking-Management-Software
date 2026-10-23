-- Jobs: one row per job. The sections (Ready / More Than 30 Days / In Transit / Delivered) are NOT stored;
-- they are computed from status + first_available_date (Phase 3), so a row can never be in the wrong section.
-- Rows are soft-deleted (deleted_at). job_number stays unique across deleted rows as well.

CREATE TABLE jobs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_number VARCHAR(40) NOT NULL UNIQUE,
    customer_first_name VARCHAR(100) NOT NULL,
    customer_last_name VARCHAR(100) NOT NULL,
    pickup_date DATE NULL,
    first_available_date DATE NOT NULL,
    from_location VARCHAR(190) NOT NULL,
    to_location VARCHAR(190) NOT NULL,
    cf DECIMAL(10,2) NULL,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    job_location VARCHAR(190) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'booked',
    carrier_name VARCHAR(150) NULL,
    driver_name VARCHAR(150) NULL,
    driver_phone VARCHAR(30) NULL,
    storage_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
    in_transit_at DATETIME NULL,
    delivered_at DATETIME NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT fk_jobs_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_jobs_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Drives every list page and dashboard count (Phase 3).
CREATE INDEX idx_jobs_status_available ON jobs (status, first_available_date);
CREATE INDEX idx_jobs_customer ON jobs (customer_last_name, customer_first_name);
CREATE INDEX idx_jobs_deleted ON jobs (deleted_at);
