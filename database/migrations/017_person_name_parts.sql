-- Add separately editable name parts for coordinators and company supervisors.
-- Existing full names are retained; name boundaries are not guessed.
ALTER TABLE coordinators
  ADD COLUMN first_name VARCHAR(60) DEFAULT NULL,
  ADD COLUMN middle_name VARCHAR(60) DEFAULT NULL,
  ADD COLUMN last_name VARCHAR(60) DEFAULT NULL;

ALTER TABLE companies
  ADD COLUMN supervisor_first_name VARCHAR(60) DEFAULT NULL,
  ADD COLUMN supervisor_middle_name VARCHAR(60) DEFAULT NULL,
  ADD COLUMN supervisor_last_name VARCHAR(60) DEFAULT NULL;
