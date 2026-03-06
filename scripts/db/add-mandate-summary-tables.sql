-- Mandate Summary Schema
-- Run on sandge5_tpb2 database

-- 1. Add dual-tag columns to idea_log
ALTER TABLE idea_log
  ADD COLUMN citizen_summary VARCHAR(200) DEFAULT NULL AFTER tags,
  ADD COLUMN policy_topic VARCHAR(60) DEFAULT NULL AFTER citizen_summary;

-- 2. Index for fast topic aggregation
CREATE INDEX idx_idea_log_policy_topic ON idea_log (policy_topic, category, deleted_at);

-- 3. Create mandate_summaries table (Layer 3 prep, empty for now)
CREATE TABLE IF NOT EXISTS mandate_summaries (
  summary_id        INT AUTO_INCREMENT PRIMARY KEY,
  scope_type        ENUM('federal','state','town') NOT NULL,
  scope_value       VARCHAR(50) NOT NULL,
  period_start      DATE NOT NULL,
  period_end        DATE NOT NULL,
  mandate_count     INT DEFAULT 0,
  contributor_count INT DEFAULT 0,
  topic_breakdown   JSON,
  trending_topics   JSON,
  gap_analysis      JSON,
  narrative         TEXT,
  town_hall_agenda  TEXT,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
