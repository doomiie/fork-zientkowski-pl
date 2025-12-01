ALTER TABLE doc_files
  ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER file_size,
  ADD COLUMN fallback_url VARCHAR(512) NULL AFTER is_enabled;

