-- Add optional global video access flag for trainers.
-- Trainers with has_global_video_access = 1 can see/edit all videos
-- without becoming admins.

ALTER TABLE users
  ADD COLUMN has_global_video_access TINYINT(1) NOT NULL DEFAULT 0 AFTER role;
