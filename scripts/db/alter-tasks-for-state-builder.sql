-- ================================================
-- Add State Builder columns to tasks table
-- ================================================
-- Adds task_type and task_key columns needed for
-- BUILD → TEST → DEPLOY workflow
-- ================================================

USE sandge5_tpb2;

-- Add task_type column (build, test, deploy, or NULL for legacy tasks)
ALTER TABLE tasks
ADD COLUMN task_type VARCHAR(20) NULL DEFAULT NULL AFTER task_id,
ADD INDEX idx_task_type (task_type);

-- Add task_key column (unique identifier like 'build-state-ct')
ALTER TABLE tasks
ADD COLUMN task_key VARCHAR(100) NULL DEFAULT NULL AFTER task_type,
ADD UNIQUE INDEX idx_task_key (task_key);

-- Add estimated_hours column (referenced in the INSERT but might not exist)
ALTER TABLE tasks
ADD COLUMN estimated_hours DECIMAL(4,1) NULL DEFAULT NULL AFTER points;

-- Verify the changes
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'sandge5_tpb2'
AND TABLE_NAME = 'tasks'
AND COLUMN_NAME IN ('task_type', 'task_key', 'estimated_hours')
ORDER BY ORDINAL_POSITION;
