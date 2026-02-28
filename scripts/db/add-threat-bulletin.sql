-- ============================================================
-- Threat Bulletin Email — Schema Changes
-- Run: ea-php84 with load script or via phpMyAdmin
-- ============================================================

-- User subscription preference
ALTER TABLE users ADD COLUMN notify_threat_bulletin TINYINT(1) NOT NULL DEFAULT 0;

-- Site-wide settings (reusable for future feature flags)
CREATE TABLE IF NOT EXISTS site_settings (
  setting_key VARCHAR(50) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  description VARCHAR(200),
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by_user_id INT DEFAULT NULL
);

-- Master on/off for daily threat bulletin emails (default OFF)
INSERT INTO site_settings (setting_key, setting_value, description)
VALUES ('threat_bulletin_enabled', '0', 'Master on/off for daily threat bulletin emails');
