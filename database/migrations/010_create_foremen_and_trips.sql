-- Foreman trips and their per-job expenses (client spreadsheet: Trip number, Forman, job rows with Diesel, Helper, Misc, Comision, extra, remark, BH).
-- Money amounts are stored exactly as typed; no column is calculated here. trip_jobs keeps a copy of the job number, customer name and
-- "from" so a trip still reads correctly if the job is later changed or deleted. Rows are never deleted with a job (jobs are soft-deleted).

CREATE TABLE foremen (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(30) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE trips (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    trip_number VARCHAR(40) NOT NULL UNIQUE,
    foreman_id INT UNSIGNED NOT NULL,
    begins_on DATE NULL,
    ends_on DATE NULL,
    collected_by VARCHAR(150) NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    CONSTRAINT fk_trips_foreman FOREIGN KEY (foreman_id) REFERENCES foremen (id),
    CONSTRAINT fk_trips_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_trips_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_trips_foreman ON trips (foreman_id, begins_on);
CREATE INDEX idx_trips_begins ON trips (begins_on);
CREATE INDEX idx_trips_deleted ON trips (deleted_at);

CREATE TABLE trip_jobs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    job_id INT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    job_number VARCHAR(40) NOT NULL,
    customer_name VARCHAR(210) NOT NULL,
    job_date DATE NULL,
    from_location VARCHAR(190) NULL,
    bh VARCHAR(100) NULL,
    diesel DECIMAL(12,2) NULL,
    hotels DECIMAL(12,2) NULL,
    helper DECIMAL(12,2) NULL,
    misc DECIMAL(12,2) NULL,
    commission DECIMAL(12,2) NULL,
    extra DECIMAL(12,2) NULL,
    remark VARCHAR(500) NULL,
    CONSTRAINT fk_trip_jobs_trip FOREIGN KEY (trip_id) REFERENCES trips (id) ON DELETE CASCADE,
    CONSTRAINT fk_trip_jobs_job FOREIGN KEY (job_id) REFERENCES jobs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_trip_jobs_trip ON trip_jobs (trip_id, sort_order);
CREATE INDEX idx_trip_jobs_job ON trip_jobs (job_id);
