-- Uniwersalne tokeny dostepu + sesje po wymianie tokenu
-- max_uses:
--   0 = bez limitu uzyc do czasu expires_at
--   >0 = limit liczby wymian tokenu

CREATE TABLE IF NOT EXISTS access_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) NOT NULL,
  target_key VARCHAR(64) NOT NULL,
  scope VARCHAR(16) NOT NULL DEFAULT 'view',
  resource_type VARCHAR(64) DEFAULT NULL,
  resource_id VARCHAR(191) DEFAULT NULL,
  max_uses SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  used_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  session_ttl_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME DEFAULT NULL,
  note VARCHAR(255) DEFAULT NULL,
  created_by_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_access_tokens_hash (token_hash),
  KEY idx_access_tokens_target (target_key, scope),
  KEY idx_access_tokens_exp (expires_at),
  KEY idx_access_tokens_res (resource_type, resource_id),
  CONSTRAINT chk_access_tokens_scope CHECK (scope IN ('view', 'edit')),
  CONSTRAINT chk_access_tokens_max_uses CHECK (max_uses >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS access_token_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_hash CHAR(64) NOT NULL,
  token_id BIGINT UNSIGNED NOT NULL,
  target_key VARCHAR(64) NOT NULL,
  scope VARCHAR(16) NOT NULL DEFAULT 'view',
  resource_type VARCHAR(64) DEFAULT NULL,
  resource_id VARCHAR(191) DEFAULT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME DEFAULT NULL,
  last_seen_at DATETIME DEFAULT NULL,
  ip_address VARCHAR(64) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_access_sessions_hash (session_hash),
  KEY idx_access_sessions_exp (expires_at),
  KEY idx_access_sessions_target (target_key, scope),
  KEY idx_access_sessions_res (resource_type, resource_id),
  CONSTRAINT fk_access_sessions_token FOREIGN KEY (token_id)
    REFERENCES access_tokens(id) ON DELETE CASCADE,
  CONSTRAINT chk_access_sessions_scope CHECK (scope IN ('view', 'edit'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migracja dla istniejacej tabeli (bezpieczna do odpalenia wielokrotnie):
-- ALTER TABLE access_tokens MODIFY max_uses SMALLINT UNSIGNED NOT NULL DEFAULT 0;
-- UPDATE access_tokens SET max_uses = 0 WHERE max_uses = 1;
-- JESLI masz stary CHECK (max_uses >= 1), zmien go:
-- MySQL 8:
-- ALTER TABLE access_tokens DROP CHECK chk_access_tokens_max_uses;
-- ALTER TABLE access_tokens ADD CONSTRAINT chk_access_tokens_max_uses CHECK (max_uses >= 0);
-- MariaDB:
-- ALTER TABLE access_tokens DROP CONSTRAINT chk_access_tokens_max_uses;
-- ALTER TABLE access_tokens ADD CONSTRAINT chk_access_tokens_max_uses CHECK (max_uses >= 0);
