-- Add email verification support for self-registered users.
-- Run once on databases that already have the users table.

ALTER TABLE users
  ADD COLUMN email_verified_at DATETIME NULL AFTER is_active;

UPDATE users
SET email_verified_at = COALESCE(email_verified_at, NOW())
WHERE email_verified_at IS NULL;

CREATE TABLE IF NOT EXISTS user_email_verifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_email_verifications_token (token),
  KEY idx_user_email_verifications_user (user_id),
  CONSTRAINT fk_user_email_verifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
