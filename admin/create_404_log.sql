-- Log table for 404 (Not Found) events

CREATE TABLE IF NOT EXISTS http_404_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_uri VARCHAR(1024) NOT NULL,
  referer VARCHAR(1024) NULL,
  user_agent VARCHAR(512) NULL,
  ip VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_404_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

