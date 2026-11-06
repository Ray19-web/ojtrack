-- Per-member inbox state. Adds no archive state to shared messages.
ALTER TABLE thread_members
  ADD COLUMN archived_at DATETIME NULL DEFAULT NULL,
  ADD KEY idx_thread_members_archive (user_id, archived_at);
