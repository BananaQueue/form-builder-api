-- Migration 005: Add step_mode column to the forms table
--
-- step_mode controls whether the public form renders as a single
-- continuous scroll (0) or as a multi-step wizard driven by section
-- blocks (1).
--
-- When step_mode = 1:
--   - Each section block becomes a step boundary
--   - Questions before the first section become Step 1 ("General")
--   - The respondent sees one step at a time with Next/Back navigation
--   - Required questions in the current step must be answered before Next
--
-- When step_mode = 0 (default):
--   - Form renders exactly as before — one continuous page
--   - Section blocks are just visual dividers
--
-- DEFAULT 0 means all existing forms stay in continuous mode unless
-- you explicitly enable stepper in the Form Builder.
--
-- Run this once in your MySQL database after migration 004.

ALTER TABLE `forms`
  ADD COLUMN `step_mode` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '0 = continuous form, 1 = multi-step form driven by section blocks'
  AFTER `privacy_notice`;