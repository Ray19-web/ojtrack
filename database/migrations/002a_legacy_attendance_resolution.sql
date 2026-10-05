CREATE TABLE IF NOT EXISTS legacy_attendance_resolutions (
  attendance_id INT NOT NULL,
  placement_id INT NOT NULL,
  resolution_note VARCHAR(500) NOT NULL,
  resolved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (attendance_id),
  KEY idx_lar_placement (placement_id),
  CONSTRAINT fk_lar_attendance FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE RESTRICT,
  CONSTRAINT fk_lar_placement FOREIGN KEY (placement_id) REFERENCES placements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
