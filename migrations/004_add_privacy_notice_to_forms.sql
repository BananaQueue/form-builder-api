-- Migration 004: Add privacy_notice column to the forms table
-- 
-- Why ALTER TABLE and not recreate the table?
-- ALTER TABLE modifies the existing table structure without touching any
-- existing rows. All your current forms stay intact, they just get a new
-- column with a NULL value (empty) by default.
--
-- Run this once in your MySQL database (phpMyAdmin, TablePlus, or CLI).

ALTER TABLE `forms`
  ADD COLUMN `privacy_notice` TEXT DEFAULT NULL
  COMMENT 'Optional privacy notice shown as a modal when the user submits the form. NULL means no modal appears.'
  AFTER `description`;