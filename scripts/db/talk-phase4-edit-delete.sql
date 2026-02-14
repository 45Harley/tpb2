-- /talk Phase 4: Edit, Soft Delete, Staleness Detection
-- Run on sandge5_tpb2
-- Date: 2026-02-14

-- Track how many times content was edited (updated_at already auto-updates)
ALTER TABLE idea_log ADD COLUMN edit_count INT NOT NULL DEFAULT 0;

-- Soft delete: NULL = active, timestamp = when deleted
ALTER TABLE idea_log ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL;

-- Index for efficient filtering of active ideas
ALTER TABLE idea_log ADD INDEX idx_deleted_at (deleted_at);
