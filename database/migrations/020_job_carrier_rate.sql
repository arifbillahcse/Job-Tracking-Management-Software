-- The rate paid to the carrier per cubic foot, set on each job (v1.12.0, client: "rate times A-CF", a different rate on each job):
--   carrier_rate  dollars per CF with 2 decimals (like 2.50); empty = no rate yet
-- Only adds a column: existing jobs get no rate. Nothing is changed or removed. The Carrier Statement multiplies it by the actual CF.
ALTER TABLE jobs ADD COLUMN carrier_rate DECIMAL(8,2) NULL;
