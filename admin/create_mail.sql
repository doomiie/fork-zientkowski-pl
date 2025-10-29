-- Mail settings for Gmail OAuth2 sending

CREATE TABLE IF NOT EXISTS mail_settings (
  id TINYINT UNSIGNED NOT NULL DEFAULT 1,
  provider VARCHAR(32) NOT NULL DEFAULT 'gmail_oauth',
  client_id VARCHAR(255) NULL,
  client_secret VARCHAR(255) NULL,
  refresh_token VARCHAR(255) NULL,
  sender_email VARCHAR(255) NULL,
  sender_name VARCHAR(255) NULL,
  last_used_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO mail_settings (id) VALUES (1)
  ON DUPLICATE KEY UPDATE id = id;

