ALTER TABLE doc_files
  ADD COLUMN share_hash VARCHAR(64) DEFAULT NULL AFTER fallback_url,
  ADD UNIQUE KEY doc_files_share_hash (share_hash);

