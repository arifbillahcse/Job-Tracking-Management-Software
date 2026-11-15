-- Storage fee payments. Some customers pay the storage fee month by month; each payment is recorded on the job and deducted from
-- the fee that is still due. jobs.storage_fee stays the fee assessed by the automatic rule (or the manual override); jobs.storage_paid
-- is the sum of the payments (kept in step by the application, always recomputed from this table), and
-- due = storage_fee - storage_paid (never below 0). Only adds a column and a table; existing jobs start with 0 paid.

ALTER TABLE jobs ADD COLUMN storage_paid DECIMAL(12,2) NOT NULL DEFAULT 0;

CREATE TABLE storage_payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    paid_on DATE NOT NULL,
    note VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_storage_payments_job FOREIGN KEY (job_id) REFERENCES jobs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_storage_payments_job ON storage_payments (job_id, paid_on);
