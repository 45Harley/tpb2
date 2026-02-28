-- ============================================================
-- Bulletin Auth Tokens — Schema
-- Auto-authenticates users clicking links in bulletin emails.
-- Run: ea-php84 with load script or via phpMyAdmin
-- ============================================================

CREATE TABLE IF NOT EXISTS bulletin_tokens (
  token_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token VARCHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_token (token),
  INDEX idx_user (user_id)
);
