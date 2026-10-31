-- Trip job rows: an Activity (Pickup / Delivery / Other), a "To" place next to "From", and a Foreman for each row.
-- The same job may now appear on a trip more than once (for example Pickup on one date and Delivery on the next), so nothing here
-- is unique on (trip, job); the application refuses only the exact same job + date + activity. jobs.job_number stays unique.
-- Only adds columns and fills them from data already there (the job's own To place, the trip's foreman). No row is changed or removed,
-- and no amount is touched. trips.collected_by is no longer used by the application but is kept, with its data, in the database.

ALTER TABLE trip_jobs ADD COLUMN activity VARCHAR(20) NULL;
ALTER TABLE trip_jobs ADD COLUMN to_location VARCHAR(190) NULL;
ALTER TABLE trip_jobs ADD COLUMN foreman_id INT UNSIGNED NULL;

UPDATE trip_jobs SET to_location = (SELECT j.to_location FROM jobs j WHERE j.id = trip_jobs.job_id) WHERE to_location IS NULL;
UPDATE trip_jobs SET foreman_id = (SELECT t.foreman_id FROM trips t WHERE t.id = trip_jobs.trip_id) WHERE foreman_id IS NULL;

CREATE INDEX idx_trip_jobs_entry ON trip_jobs (trip_id, job_id, job_date);
