ALTER TABLE questions
  ADD COLUMN description TEXT DEFAULT NULL AFTER question_text;