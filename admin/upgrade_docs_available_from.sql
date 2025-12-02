ALTER TABLE doc_files
  ADD COLUMN available_from DATETIME NULL AFTER share_hash;

