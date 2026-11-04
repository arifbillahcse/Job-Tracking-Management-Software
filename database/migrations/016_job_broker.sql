-- A broker on every job (the client's sheet: Top Nation, Unted, Prime ...). Free text, optional, NOT unique:
-- the same broker is used on many jobs. The index serves sorting and filtering by broker. Only adds an empty column.

ALTER TABLE jobs ADD COLUMN broker VARCHAR(100) NULL;
CREATE INDEX idx_jobs_broker ON jobs (broker);
