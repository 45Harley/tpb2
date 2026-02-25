-- Poll Roll Call Migration
-- Extends existing poll tables for threat-based roll call system

-- 1. polls table: add threat linkage
ALTER TABLE polls ADD COLUMN threat_id INT DEFAULT NULL;
ALTER TABLE polls ADD COLUMN poll_type ENUM('general', 'threat') DEFAULT 'general';
ALTER TABLE polls ADD INDEX idx_threat_id (threat_id);
ALTER TABLE polls ADD INDEX idx_poll_type (poll_type);

-- 2. poll_votes table: add abstain + rep vote flag
ALTER TABLE poll_votes MODIFY vote_choice ENUM('yes', 'no', 'yea', 'nay', 'abstain');
ALTER TABLE poll_votes ADD COLUMN is_rep_vote TINYINT(1) DEFAULT 0;

-- 3. users table: link user to elected_officials for rep verification
ALTER TABLE users ADD COLUMN official_id INT DEFAULT NULL;
ALTER TABLE users ADD INDEX idx_official_id (official_id);
