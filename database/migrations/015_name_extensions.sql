-- Store a person's extension type and value separately from their display name.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS name_extension_type VARCHAR(30) DEFAULT NULL AFTER name,
  ADD COLUMN IF NOT EXISTS name_extension_value VARCHAR(50) DEFAULT NULL AFTER name_extension_type;

ALTER TABLE companies
  ADD COLUMN IF NOT EXISTS supervisor_name_extension_type VARCHAR(30) DEFAULT NULL AFTER supervisor_name,
  ADD COLUMN IF NOT EXISTS supervisor_name_extension_value VARCHAR(50) DEFAULT NULL AFTER supervisor_name_extension_type;
