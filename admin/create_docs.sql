-- Documents (downloadable files) managed via admin

CREATE TABLE IF NOT EXISTS doc_files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  display_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(512) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(127) NULL,
  file_size BIGINT UNSIGNED NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  fallback_url VARCHAR(512) NULL,
  expires_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
