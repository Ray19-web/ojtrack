-- Preserve each person's ordered extension entries as separate typed rows.
CREATE TABLE IF NOT EXISTS user_name_extensions (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  extension_type VARCHAR(30) NOT NULL,
  extension_value VARCHAR(50) NOT NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_name_extensions_order (user_id, sort_order),
  CONSTRAINT fk_user_name_extensions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_supervisor_name_extensions (
  id INT NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  extension_type VARCHAR(30) NOT NULL,
  extension_value VARCHAR(50) NOT NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_company_supervisor_name_extensions_order (company_id, sort_order),
  CONSTRAINT fk_company_supervisor_name_extensions_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO user_name_extensions (user_id, extension_type, extension_value, sort_order)
SELECT u.id, u.name_extension_type, u.name_extension_value, 0
FROM users u
WHERE u.name_extension_type IS NOT NULL AND u.name_extension_type <> ''
  AND u.name_extension_value IS NOT NULL AND u.name_extension_value <> ''
  AND NOT EXISTS (SELECT 1 FROM user_name_extensions e WHERE e.user_id=u.id);

INSERT INTO company_supervisor_name_extensions (company_id, extension_type, extension_value, sort_order)
SELECT c.id, c.supervisor_name_extension_type, c.supervisor_name_extension_value, 0
FROM companies c
WHERE c.supervisor_name_extension_type IS NOT NULL AND c.supervisor_name_extension_type <> ''
  AND c.supervisor_name_extension_value IS NOT NULL AND c.supervisor_name_extension_value <> ''
  AND NOT EXISTS (SELECT 1 FROM company_supervisor_name_extensions e WHERE e.company_id=c.id);
