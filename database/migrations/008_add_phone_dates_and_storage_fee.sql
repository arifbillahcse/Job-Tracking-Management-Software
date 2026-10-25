-- Customer phone, loaded and delivery dates, and automatic storage fees.
-- One column per statement (SQLite, used by the tests, cannot add several at once).

ALTER TABLE jobs ADD COLUMN customer_phone VARCHAR(30) NULL;
ALTER TABLE jobs ADD COLUMN loaded_date DATE NULL;
ALTER TABLE jobs ADD COLUMN delivery_date DATE NULL;
ALTER TABLE jobs ADD COLUMN storage_fee_override DECIMAL(12,2) NULL;
ALTER TABLE jobs ADD COLUMN storage_rate DECIMAL(10,4) NULL;

-- Jobs that already moved on keep their dates; storage fees typed by hand before this version become overrides.
UPDATE jobs SET loaded_date = DATE(in_transit_at) WHERE in_transit_at IS NOT NULL;
UPDATE jobs SET delivery_date = DATE(delivered_at) WHERE delivered_at IS NOT NULL;
UPDATE jobs SET storage_fee_override = storage_fee WHERE storage_fee > 0;

CREATE INDEX idx_jobs_phone ON jobs (customer_phone);
