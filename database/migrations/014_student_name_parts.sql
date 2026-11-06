-- Store student name parts independently while preserving users.name for existing readers.
ALTER TABLE students
  ADD COLUMN IF NOT EXISTS first_name VARCHAR(60) DEFAULT NULL AFTER user_id,
  ADD COLUMN IF NOT EXISTS middle_name VARCHAR(60) DEFAULT NULL AFTER first_name,
  ADD COLUMN IF NOT EXISTS last_name VARCHAR(60) DEFAULT NULL AFTER middle_name;
