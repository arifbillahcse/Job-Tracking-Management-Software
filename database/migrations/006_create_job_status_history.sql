-- Every status change of a job: who, when, why, and the details that were captured with it
-- (carrier/driver/phone on dispatch, delivery date, carrier data cleared on resume ...).
-- Written in the same transaction as the status change itself, so the two can never disagree.

CREATE TABLE job_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_id INT UNSIGNED NOT NULL,
    from_status VARCHAR(20) NOT NULL,
    to_status VARCHAR(20) NOT NULL,
    note TEXT NULL,
    details TEXT NULL,
    changed_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_job_status_history_job FOREIGN KEY (job_id) REFERENCES jobs (id),
    CONSTRAINT fk_job_status_history_user FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_job_status_history_job ON job_status_history (job_id, id);
CREATE INDEX idx_job_status_history_created ON job_status_history (created_at);
