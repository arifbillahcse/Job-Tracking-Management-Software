-- Two more job fields from the client, entered after Color: Range and Pads. Free text (up to 60 characters, like Job lot and Color),
-- optional, not unique. Only adds empty columns; existing jobs are untouched.
-- The column is named job_range because RANGE is a reserved word in MySQL / MariaDB.

ALTER TABLE jobs ADD COLUMN job_range VARCHAR(60) NULL;
ALTER TABLE jobs ADD COLUMN pads VARCHAR(60) NULL;
