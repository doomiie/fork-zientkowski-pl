-- Upgrade site_settings table to support Mailchimp

ALTER TABLE site_settings
  ADD COLUMN mailchimp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER fb_pixel_id,
  ADD COLUMN mailchimp_url VARCHAR(255) NULL AFTER mailchimp_enabled;

