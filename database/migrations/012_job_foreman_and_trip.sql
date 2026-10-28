-- A job is assigned to a foreman (from the foremen list) and to a trip (by trip number).
-- Both are plain references, enforced by the application (a database foreign key cannot be added with one ALTER on every supported database).
-- The free-text foreman typed on jobs in version 1.1.4 (jobs.foreman_name) is kept as it is and mapped to a foreman below; the application no longer writes it.
-- Nothing is deleted. Rows already on a trip are not changed.

ALTER TABLE jobs ADD COLUMN foreman_id INT UNSIGNED NULL;
ALTER TABLE jobs ADD COLUMN trip_id INT UNSIGNED NULL;

-- 1. Every distinct foreman name already typed on a job becomes a foreman (skipping names that already exist).
INSERT INTO foremen (name, phone, active, created_at, updated_at)
    SELECT DISTINCT TRIM(j.foreman_name), NULL, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    FROM jobs j
    WHERE j.foreman_name IS NOT NULL AND TRIM(j.foreman_name) <> ''
      AND TRIM(j.foreman_name) NOT IN (SELECT name FROM foremen);

UPDATE jobs SET foreman_id = (SELECT f.id FROM foremen f WHERE f.name = TRIM(jobs.foreman_name))
    WHERE foreman_name IS NOT NULL AND TRIM(foreman_name) <> '';

-- 2. A job that is already on a trip gets the newest live trip as its current trip, and that trip's foreman.
UPDATE jobs SET trip_id = (
        SELECT tj.trip_id FROM trip_jobs tj JOIN trips t ON t.id = tj.trip_id
        WHERE tj.job_id = jobs.id AND t.deleted_at IS NULL
        ORDER BY COALESCE(t.begins_on, '1000-01-01') DESC, t.id DESC LIMIT 1)
    WHERE EXISTS (SELECT 1 FROM trip_jobs tj2 JOIN trips t2 ON t2.id = tj2.trip_id WHERE tj2.job_id = jobs.id AND t2.deleted_at IS NULL);

UPDATE jobs SET foreman_id = (SELECT t.foreman_id FROM trips t WHERE t.id = jobs.trip_id) WHERE trip_id IS NOT NULL;

CREATE INDEX idx_jobs_foreman_id ON jobs (foreman_id);
CREATE INDEX idx_jobs_trip_id ON jobs (trip_id);
