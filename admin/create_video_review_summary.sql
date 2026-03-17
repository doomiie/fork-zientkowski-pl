-- Video review summaries for trainer assessment popup
-- Run once on the same database used by admin/db.php

CREATE TABLE IF NOT EXISTS video_review_summaries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  video_id BIGINT UNSIGNED NOT NULL,
  reviewer_user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  version_no INT UNSIGNED NOT NULL DEFAULT 1,
  published_at DATETIME NULL,
  overall_note TEXT NULL,
  total_score INT UNSIGNED NOT NULL DEFAULT 0,
  max_score INT UNSIGNED NOT NULL DEFAULT 0,
  archived_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_video_review_video_status_pub (video_id, status, published_at),
  KEY idx_video_review_video_reviewer_status (video_id, reviewer_user_id, status),
  CONSTRAINT fk_video_review_summary_video FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE,
  CONSTRAINT fk_video_review_summary_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS video_review_scores (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  summary_id BIGINT UNSIGNED NOT NULL,
  item_key VARCHAR(64) NOT NULL,
  category_key VARCHAR(64) NOT NULL,
  score TINYINT UNSIGNED NOT NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_video_review_scores_summary_item (summary_id, item_key),
  KEY idx_video_review_scores_summary_position (summary_id, position),
  CONSTRAINT fk_video_review_scores_summary FOREIGN KEY (summary_id) REFERENCES video_review_summaries(id) ON DELETE CASCADE,
  CONSTRAINT chk_video_review_score_range CHECK (score BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
