-- Company invoices (v1.17.0). A company invoice (type CI) can be made from a driver invoice (type DI): it keeps the number of the
-- driver invoice it came from here, so the two stay linked (and "generate company invoices" is not done twice by accident).
-- Only adds a column and an index; existing invoices get no source.
ALTER TABLE invoices ADD COLUMN source_invoice_id INT UNSIGNED NULL;

CREATE INDEX idx_invoices_source ON invoices (source_invoice_id);
