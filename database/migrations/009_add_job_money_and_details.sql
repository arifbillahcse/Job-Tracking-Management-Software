-- Fields from the client's spreadsheet: estimated CF/total, deposit, grand total, BH total, paid, foreman, job lot and colour.
-- Only adds nullable columns; nothing existing is changed. One column per statement (SQLite, used by the tests, cannot add several at once).
-- `cf` stays and is shown as A-CF (actual CF); the storage fee is calculated from it.

ALTER TABLE jobs ADD COLUMN estimated_cf DECIMAL(10,2) NULL;
ALTER TABLE jobs ADD COLUMN estimated_total DECIMAL(12,2) NULL;
ALTER TABLE jobs ADD COLUMN deposit DECIMAL(12,2) NULL;
ALTER TABLE jobs ADD COLUMN grand_total DECIMAL(12,2) NULL;
ALTER TABLE jobs ADD COLUMN bh_total DECIMAL(12,2) NULL;
ALTER TABLE jobs ADD COLUMN paid DECIMAL(12,2) NULL;
ALTER TABLE jobs ADD COLUMN foreman_name VARCHAR(150) NULL;
ALTER TABLE jobs ADD COLUMN job_lot VARCHAR(60) NULL;
ALTER TABLE jobs ADD COLUMN color VARCHAR(60) NULL;

CREATE INDEX idx_jobs_foreman ON jobs (foreman_name);
