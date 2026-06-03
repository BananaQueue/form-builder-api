-- Soft-delete questions that still have historical answers instead of
-- CASCADE-deleting answer rows when a form is edited.
ALTER TABLE questions
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER position;
