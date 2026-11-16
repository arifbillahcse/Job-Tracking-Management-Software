-- Pickup details the client asked for on every job (v1.8.0):
--   pickup_time  free text, up to 40 characters (like "08-10AM"); the client chose free text, not a fixed list
--   miles        a number typed by hand (there is no map service, so nothing is calculated)
--   confirmed, e_signed, job_docs, bill_of_lading   four yes / no checks, 0 = not ticked
-- Only adds columns: existing jobs get empty time and miles and no ticks. Nothing is changed or removed.

ALTER TABLE jobs ADD COLUMN pickup_time VARCHAR(40) NULL;
ALTER TABLE jobs ADD COLUMN miles DECIMAL(8,2) NULL;
ALTER TABLE jobs ADD COLUMN confirmed TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE jobs ADD COLUMN e_signed TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE jobs ADD COLUMN job_docs TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE jobs ADD COLUMN bill_of_lading TINYINT(1) NOT NULL DEFAULT 0;
