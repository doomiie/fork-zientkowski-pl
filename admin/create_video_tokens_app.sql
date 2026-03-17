-- Video app: token catalog, orders, entitlements, trainer defaults
-- Run once on the same database used by admin/db.php

CREATE TABLE IF NOT EXISTS token_types (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  price_gross_pln DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'PLN',
  max_upload_links INT UNSIGNED NOT NULL DEFAULT 0,
  can_choose_trainer TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT UNSIGNED NOT NULL DEFAULT 100,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token_types_code (code),
  KEY idx_token_types_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS token_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_uuid CHAR(36) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  token_type_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','paid','failed','cancelled') NOT NULL DEFAULT 'pending',
  amount_gross_pln DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'PLN',
  payment_provider VARCHAR(32) NOT NULL DEFAULT 'p24',
  provider_order_id VARCHAR(120) NULL,
  provider_session_id VARCHAR(120) NULL,
  provider_payload LONGTEXT NULL,
  note VARCHAR(255) NULL,
  paid_at DATETIME NULL,
  entitlements_granted_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token_orders_uuid (order_uuid),
  KEY idx_token_orders_user_created (user_id, created_at),
  KEY idx_token_orders_status (status),
  KEY idx_token_orders_provider_order (payment_provider, provider_order_id),
  CONSTRAINT fk_token_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_token_orders_type FOREIGN KEY (token_type_id) REFERENCES token_types(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_token_entitlements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_type_id BIGINT UNSIGNED NOT NULL,
  source_order_id BIGINT UNSIGNED NULL,
  total_upload_links INT UNSIGNED NOT NULL DEFAULT 0,
  remaining_upload_links INT UNSIGNED NOT NULL DEFAULT 0,
  total_trainer_choices INT UNSIGNED NOT NULL DEFAULT 0,
  remaining_trainer_choices INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_entitlements_user (user_id),
  KEY idx_entitlements_user_remaining (user_id, remaining_upload_links, remaining_trainer_choices),
  CONSTRAINT fk_entitlements_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_entitlements_type FOREIGN KEY (token_type_id) REFERENCES token_types(id) ON DELETE RESTRICT,
  CONSTRAINT fk_entitlements_order FOREIGN KEY (source_order_id) REFERENCES token_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_trainer_rel (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  trainer_user_id BIGINT UNSIGNED NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_trainer_pair (user_id, trainer_user_id),
  KEY idx_user_trainer_default (user_id, is_default),
  CONSTRAINT fk_user_trainer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_trainer_trainer FOREIGN KEY (trainer_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE videos
  ADD COLUMN owner_user_id BIGINT UNSIGNED NULL AFTER publiczny,
  ADD COLUMN assigned_trainer_user_id BIGINT UNSIGNED NULL AFTER owner_user_id,
  ADD COLUMN created_via_token_order_id BIGINT UNSIGNED NULL AFTER assigned_trainer_user_id;

ALTER TABLE videos
  ADD KEY idx_videos_owner (owner_user_id),
  ADD KEY idx_videos_trainer (assigned_trainer_user_id),
  ADD KEY idx_videos_order (created_via_token_order_id),
  ADD CONSTRAINT fk_videos_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_videos_trainer FOREIGN KEY (assigned_trainer_user_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_videos_token_order FOREIGN KEY (created_via_token_order_id) REFERENCES token_orders(id) ON DELETE SET NULL;

INSERT INTO token_types (code, title, description, price_gross_pln, currency, max_upload_links, can_choose_trainer, is_active, sort_order)
SELECT * FROM (
  SELECT 'START_1', 'Start: 1 film', '1 link YouTube, trener automatyczny', 99.00, 'PLN', 1, 0, 1, 10
) AS x
WHERE NOT EXISTS (SELECT 1 FROM token_types WHERE code = 'START_1');

INSERT INTO token_types (code, title, description, price_gross_pln, currency, max_upload_links, can_choose_trainer, is_active, sort_order)
SELECT * FROM (
  SELECT 'PRO_3', 'Pro: 3 filmy', '3 linki YouTube, mozliwosc wyboru trenera', 249.00, 'PLN', 3, 1, 1, 20
) AS x
WHERE NOT EXISTS (SELECT 1 FROM token_types WHERE code = 'PRO_3');
