-- ============================================================
-- Add branch column to executive_threats
-- Tags each threat by government branch: executive, congressional, judicial
-- ============================================================

ALTER TABLE executive_threats
  ADD COLUMN branch ENUM('executive','congressional','judicial')
  DEFAULT 'executive' AFTER official_id;

-- Backfill: all 227 existing threats are executive branch
UPDATE executive_threats SET branch = 'executive' WHERE branch IS NULL;

-- Index for branch filtering
ALTER TABLE executive_threats ADD INDEX idx_branch (branch);
