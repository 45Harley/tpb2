-- ============================================================================
-- Civic Engine Phase 1 Migration
-- Adds scope, ballot columns to polls; creates poll_options; extends poll_votes
-- ============================================================================

-- ============================================================================
-- 1. Add scope columns to polls
-- ============================================================================
ALTER TABLE polls
  ADD COLUMN scope_type ENUM('federal','state','town','group') DEFAULT 'federal' AFTER poll_id,
  ADD COLUMN scope_id VARCHAR(50) DEFAULT NULL AFTER scope_type;

ALTER TABLE polls
  ADD INDEX idx_polls_scope (scope_type, scope_id);

-- ============================================================================
-- 2. Add ballot columns to polls
-- ============================================================================
ALTER TABLE polls
  ADD COLUMN vote_type ENUM('yes_no','yes_no_novote','multi_choice','ranked_choice') DEFAULT 'yes_no',
  ADD COLUMN threshold_type ENUM('plurality','majority','three_fifths','two_thirds','three_quarters','unanimous') DEFAULT 'majority',
  ADD COLUMN quorum_type ENUM('percent','minimum','none') DEFAULT 'none',
  ADD COLUMN quorum_value INT DEFAULT NULL,
  ADD COLUMN round INT DEFAULT 1,
  ADD COLUMN parent_poll_id INT DEFAULT NULL,
  ADD COLUMN declaration_id INT DEFAULT NULL,
  ADD COLUMN source_type ENUM('manual','threat','bill','executive_order','group') DEFAULT 'manual',
  ADD COLUMN source_id INT DEFAULT NULL;

ALTER TABLE polls
  ADD INDEX idx_polls_parent (parent_poll_id),
  ADD INDEX idx_polls_declaration (declaration_id),
  ADD INDEX idx_polls_source (source_type, source_id);

-- ============================================================================
-- 3. Create poll_options table
-- ============================================================================
CREATE TABLE IF NOT EXISTS poll_options (
  option_id INT AUTO_INCREMENT PRIMARY KEY,
  poll_id INT NOT NULL,
  option_text TEXT NOT NULL,
  option_order INT DEFAULT 0,
  merged_from_option_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_poll_options_poll (poll_id),
  CONSTRAINT fk_poll_options_poll FOREIGN KEY (poll_id) REFERENCES polls(poll_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- 4. Add columns to poll_votes
-- ============================================================================
ALTER TABLE poll_votes
  ADD COLUMN option_id INT DEFAULT NULL,
  ADD COLUMN rank_position INT DEFAULT NULL;

ALTER TABLE poll_votes
  ADD INDEX idx_poll_votes_option (option_id);

-- ============================================================================
-- 5. Backfill existing polls
-- ============================================================================
UPDATE polls SET
  scope_type = 'federal',
  vote_type = 'yes_no',
  threshold_type = 'majority',
  quorum_type = 'none',
  round = 1;

UPDATE polls SET
  source_type = 'threat',
  source_id = threat_id
WHERE threat_id IS NOT NULL;

-- ============================================================================
-- Verification queries (run manually to confirm migration)
-- ============================================================================

-- Verify scope columns exist
-- SELECT scope_type, scope_id, COUNT(*) FROM polls GROUP BY scope_type, scope_id;

-- Verify ballot columns exist and backfill applied
-- SELECT vote_type, threshold_type, quorum_type, round, COUNT(*) FROM polls GROUP BY vote_type, threshold_type, quorum_type, round;

-- Verify source_type backfill for threat-linked polls
-- SELECT source_type, source_id, threat_id FROM polls WHERE threat_id IS NOT NULL LIMIT 10;

-- Verify poll_options table created
-- SHOW CREATE TABLE poll_options;

-- Verify poll_votes new columns
-- DESCRIBE poll_votes;

-- Verify indexes
-- SHOW INDEX FROM polls WHERE Key_name IN ('idx_polls_scope','idx_polls_parent','idx_polls_declaration','idx_polls_source');
-- SHOW INDEX FROM poll_options WHERE Key_name = 'idx_poll_options_poll';
-- SHOW INDEX FROM poll_votes WHERE Key_name = 'idx_poll_votes_option';
