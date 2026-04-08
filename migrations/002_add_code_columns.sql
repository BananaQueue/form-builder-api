-- Add unique form code column
ALTER TABLE forms 
ADD COLUMN form_code VARCHAR(20) UNIQUE NULL AFTER id;

-- Create index for faster lookups
CREATE INDEX idx_form_code ON forms(form_code);