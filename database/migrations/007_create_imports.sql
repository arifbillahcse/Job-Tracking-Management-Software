-- Import batches (one per uploaded file) and the rows that failed validation. The file is parsed and validated
-- when it is uploaded (preview) and again when the user confirms, so what is imported is always what was checked.
-- The uploaded file itself lives outside the web root (storage/imports) and is deleted after confirm / cancel.

CREATE TABLE import_batches (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(80) NULL,
    file_format VARCHAR(10) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'previewed',
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    valid_rows INT UNSIGNED NOT NULL DEFAULT 0,
    invalid_rows INT UNSIGNED NOT NULL DEFAULT 0,
    imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_rows INT UNSIGNED NOT NULL DEFAULT 0,
    headers TEXT NULL,
    mapping TEXT NULL,
    ignored TEXT NULL,
    warnings TEXT NULL,
    sample TEXT NULL,
    created_at DATETIME NOT NULL,
    committed_at DATETIME NULL,
    CONSTRAINT fk_import_batches_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_import_batches_user ON import_batches (user_id, id);

CREATE TABLE import_errors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    batch_id INT UNSIGNED NOT NULL,
    row_no INT UNSIGNED NOT NULL,
    job_number VARCHAR(60) NULL,
    messages TEXT NOT NULL,
    raw_data TEXT NULL,
    CONSTRAINT fk_import_errors_batch FOREIGN KEY (batch_id) REFERENCES import_batches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_import_errors_batch ON import_errors (batch_id, row_no);
