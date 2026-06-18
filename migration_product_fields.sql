-- ============================================================
-- MIGRATION: Add Product Fields — unit_qty, machine_count
-- Run AFTER database.sql and migration_subscription.sql
-- ============================================================
USE product_enquiry;

-- Add unit and machine columns to products table
ALTER TABLE products
  ADD COLUMN IF NOT EXISTS unit_qty      SMALLINT UNSIGNED DEFAULT 1 COMMENT 'Unit quantity (1,2,5,10,…,500)',
  ADD COLUMN IF NOT EXISTS machine_count TINYINT  UNSIGNED DEFAULT 1 COMMENT 'Machine count (1–15)';

-- (Optional) Index for filtering
-- ALTER TABLE products ADD INDEX idx_unit_qty (unit_qty);
-- ALTER TABLE products ADD INDEX idx_machine_count (machine_count);

-- DONE
SELECT 'Migration complete: unit_qty and machine_count added to products.' AS status;
