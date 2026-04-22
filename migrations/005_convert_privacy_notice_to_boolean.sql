-- Migration 004: Convert privacy_notice from TEXT to TINYINT(1)
--
-- Previously privacy_notice stored free-form text. We are changing it to
-- a simple on/off boolean (0 = no privacy notice, 1 = show privacy notice).
--
-- The CASE statement below handles existing data gracefully:
--   - If the column had any non-empty text → convert to 1 (enabled)
--   - If the column was NULL or empty      → convert to 0 (disabled)
--
-- Run this once in your MySQL database after migration 003.
-- Safe to run even if no forms have a privacy_notice set yet.

-- Step 1: Convert any existing text values to 0 or 1
-- We do this BEFORE changing the column type, while it's still TEXT,
-- so MySQL can read and compare the values properly.
UPDATE `forms`
SET `privacy_notice` = CASE
    WHEN `privacy_notice` IS NOT NULL AND TRIM(`privacy_notice`) != '' THEN '1'
    ELSE '0'
END;

-- Step 2: Now change the column type from TEXT to TINYINT(1).
-- DEFAULT 0 means new forms will have privacy notice OFF by default,
-- which is the right behaviour — it should be an explicit opt-in.
-- NOT NULL ensures we never have to deal with NULL checks in PHP/JS,
-- just a clean 0 or 1 every time.
ALTER TABLE `forms`
  MODIFY COLUMN `privacy_notice` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '0 = no privacy notice modal, 1 = show standard privacy notice on submit';