-- Site-wide toggle (default ON for single-user stage)
INSERT INTO site_settings (setting_key, setting_value, updated_by_user_id)
SELECT 'claudia_widget_enabled', '1', 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM site_settings WHERE setting_key = 'claudia_widget_enabled'
);

-- Per-user toggle
ALTER TABLE users ADD COLUMN IF NOT EXISTS claudia_enabled TINYINT(1) NOT NULL DEFAULT 1;
