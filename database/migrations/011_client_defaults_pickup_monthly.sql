-- The client asked for the 30-day rule to count from the PICKUP DATE and for the storage fee to be charged every month.
-- Settings that still hold the old defaults are moved to the new ones; a value an administrator chose on purpose
-- (for example the loaded date) is left alone. Only existing settings rows are touched: a missing row is created by
-- `php cli/seed.php` with the new default. Nothing on any job changes; sections and automatic fees follow the settings.

UPDATE settings SET setting_value = 'pickup' WHERE setting_key = 'section_basis' AND setting_value = 'first_available';
UPDATE settings SET setting_value = 'pickup' WHERE setting_key = 'storage_fee_basis' AND setting_value = 'first_available';
UPDATE settings SET setting_value = 'monthly' WHERE setting_key = 'storage_fee_mode' AND setting_value = 'once';
