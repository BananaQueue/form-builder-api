-- Run this on an existing `form_builder` database that still has the old `questions` schema
-- (missing columns used by save_form.php / update_form.php / get_form_details.php).
-- Safe to run once; if a column already exists, remove that line or use MySQL 8+ IF NOT EXISTS patterns.

ALTER TABLE `questions`
  ADD COLUMN `rating_scale` varchar(64) DEFAULT NULL COMMENT 'e.g. numeric_5, agree_5, custom' AFTER `question_type`,
  ADD COLUMN `is_required` tinyint(1) NOT NULL DEFAULT 1 AFTER `position`,
  ADD COLUMN `condition_question_id` int(11) DEFAULT NULL AFTER `is_required`,
  ADD COLUMN `condition_type` varchar(32) DEFAULT 'equals' AFTER `condition_question_id`,
  ADD COLUMN `condition_value` text DEFAULT NULL AFTER `condition_type`;
