-- Upgrade site_settings table to support Facebook Pixel

ALTER TABLE site_settings
  ADD COLUMN fb_pixel_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER hotjar_site_id,
  ADD COLUMN fb_pixel_id VARCHAR(64) NULL AFTER fb_pixel_enabled;

