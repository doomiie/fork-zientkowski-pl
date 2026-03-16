ALTER TABLE videos
  ADD COLUMN provider VARCHAR(20) NOT NULL DEFAULT 'youtube' AFTER youtube_id,
  ADD COLUMN provider_video_id VARCHAR(128) NULL AFTER provider,
  ADD COLUMN source_url VARCHAR(1024) NULL AFTER provider_video_id;

UPDATE videos
SET
  provider = CASE
    WHEN provider IS NULL OR provider = '' THEN 'youtube'
    ELSE provider
  END,
  provider_video_id = CASE
    WHEN provider_video_id IS NULL OR provider_video_id = '' THEN youtube_id
    ELSE provider_video_id
  END,
  source_url = CASE
    WHEN source_url IS NULL OR source_url = '' THEN CONCAT('https://www.youtube.com/watch?v=', youtube_id)
    ELSE source_url
  END;

ALTER TABLE videos
  ADD INDEX idx_videos_provider_video_id (provider, provider_video_id);
