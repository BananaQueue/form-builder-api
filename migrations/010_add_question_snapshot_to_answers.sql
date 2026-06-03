-- Preserve question labels on each answer so response history survives form edits.
ALTER TABLE answers
  ADD COLUMN question_text TEXT NULL AFTER question_id,
  ADD COLUMN question_type VARCHAR(50) NULL DEFAULT NULL AFTER question_text;
