-- Rep Statements Tracking System
-- Run on sandge5_tpb2 database

-- 1. New table: rep_statements
CREATE TABLE IF NOT EXISTS rep_statements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    official_id INT NOT NULL,
    source VARCHAR(100) NOT NULL COMMENT 'Truth Social, Press Conference, Interview, WH Statement, etc.',
    source_url VARCHAR(500) DEFAULT NULL,
    content TEXT NOT NULL COMMENT 'Full quote or statement text',
    summary VARCHAR(500) DEFAULT NULL COMMENT 'AI-generated one-liner',
    policy_topic VARCHAR(100) DEFAULT NULL COMMENT 'One of 16 mandate policy topics',
    tense ENUM('future', 'present', 'past') DEFAULT NULL,
    severity_score SMALLINT DEFAULT NULL COMMENT 'Criminality scale 0-1000',
    benefit_score SMALLINT DEFAULT NULL COMMENT 'Benefit scale 0-1000',
    statement_date DATE NOT NULL COMMENT 'When the statement was made',
    related_threat_id INT DEFAULT NULL COMMENT 'FK to executive_threats if statement links to an action',
    agree_count INT NOT NULL DEFAULT 0,
    disagree_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (official_id) REFERENCES elected_officials(official_id),
    FOREIGN KEY (related_threat_id) REFERENCES executive_threats(threat_id),
    INDEX idx_official_date (official_id, statement_date DESC),
    INDEX idx_policy_topic (policy_topic),
    INDEX idx_tense (tense)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. New table: rep_statement_votes
CREATE TABLE IF NOT EXISTS rep_statement_votes (
    vote_id INT AUTO_INCREMENT PRIMARY KEY,
    statement_id INT NOT NULL,
    user_id INT NOT NULL,
    vote_type ENUM('agree', 'disagree') NOT NULL,
    voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_vote (statement_id, user_id),
    FOREIGN KEY (statement_id) REFERENCES rep_statements(id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Add benefit_score to executive_threats
ALTER TABLE executive_threats
    ADD COLUMN benefit_score SMALLINT DEFAULT NULL COMMENT 'Benefit scale 0-1000'
    AFTER severity_score;
