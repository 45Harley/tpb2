-- ================================================
-- Add State Builder columns to tasks table (safe)
-- ================================================
-- Only adds columns if they don't exist
-- Run this to see what's missing first
-- ================================================

USE sandge5_tpb2;

-- Check which columns exist
SELECT COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'sandge5_tpb2'
  AND TABLE_NAME = 'tasks'
  AND COLUMN_NAME IN ('task_type', 'task_key', 'estimated_hours');

-- ================================================
-- Based on results above, run ONE of these:
-- ================================================

-- If task_type is MISSING (uncomment to run):
-- ALTER TABLE tasks
-- ADD COLUMN task_type VARCHAR(20) NULL DEFAULT NULL AFTER task_id,
-- ADD INDEX idx_task_type (task_type);

-- If task_key is MISSING (uncomment to run):
-- ALTER TABLE tasks
-- ADD COLUMN task_key VARCHAR(100) NULL DEFAULT NULL AFTER task_type,
-- ADD UNIQUE INDEX idx_task_key (task_key);

-- If estimated_hours is MISSING (uncomment to run):
-- ALTER TABLE tasks
-- ADD COLUMN estimated_hours DECIMAL(4,1) NULL DEFAULT NULL AFTER points;
