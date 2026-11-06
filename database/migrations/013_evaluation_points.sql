-- Add point-based forms without changing existing percentage/rating versions.
ALTER TABLE evaluation_definition_versions
  MODIFY score_mode ENUM('percentage','rating','points') NOT NULL DEFAULT 'percentage';
