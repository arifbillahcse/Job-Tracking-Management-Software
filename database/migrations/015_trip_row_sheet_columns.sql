-- The client's spreadsheet (Forman_Closing.xlsx) columns that were still missing on a trip entry:
-- Grand, Job Begins, Deposit, Additional, Total to BH, the amount paid to the Foreman, and the Broker.
-- All money columns are amounts. Only adds empty columns; nothing existing is changed.

ALTER TABLE trip_jobs ADD COLUMN grand DECIMAL(12,2) NULL;
ALTER TABLE trip_jobs ADD COLUMN job_beg DECIMAL(12,2) NULL;
ALTER TABLE trip_jobs ADD COLUMN depo DECIMAL(12,2) NULL;
ALTER TABLE trip_jobs ADD COLUMN addio DECIMAL(12,2) NULL;
ALTER TABLE trip_jobs ADD COLUMN total_to_bh DECIMAL(12,2) NULL;
ALTER TABLE trip_jobs ADD COLUMN foreman_pay DECIMAL(12,2) NULL;
ALTER TABLE trip_jobs ADD COLUMN broker VARCHAR(100) NULL;
