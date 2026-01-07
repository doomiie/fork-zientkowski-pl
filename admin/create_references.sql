-- References (testimonials) imported from assets/txt/referencje.csv

CREATE TABLE IF NOT EXISTS references_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(255) NOT NULL,
  role VARCHAR(255) NULL,
  event_name VARCHAR(255) NULL,
  opinion TEXT NOT NULL,
  opinion_date_raw VARCHAR(64) NULL,
  opinion_date DATETIME NULL,
  source VARCHAR(255) NULL,
  profile_image_url VARCHAR(512) NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY references_entries_event_name (event_name),
  KEY references_entries_opinion_date (opinion_date),
  KEY references_entries_is_enabled (is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
