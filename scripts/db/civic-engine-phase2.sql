-- ============================================================================
-- Civic Engine Phase 2 Migration: Group Deliberation
-- Creates declarations table for group consensus outcomes
-- ============================================================================

-- 1. Create declarations table
CREATE TABLE IF NOT EXISTS declarations (
    declaration_id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    scope_type ENUM('federal','state','town') NOT NULL,
    scope_id VARCHAR(50) DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    final_poll_id INT DEFAULT NULL,
    vote_count INT DEFAULT 0,
    yes_count INT DEFAULT 0,
    threshold_met ENUM('plurality','majority','three_fifths','two_thirds','three_quarters','unanimous') DEFAULT NULL,
    status ENUM('draft','voting','ratified','superseded') DEFAULT 'draft',
    ratified_at TIMESTAMP NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES idea_groups(id),
    FOREIGN KEY (final_poll_id) REFERENCES polls(poll_id),
    FOREIGN KEY (created_by) REFERENCES users(user_id),
    INDEX idx_declarations_group (group_id),
    INDEX idx_declarations_scope (scope_type, scope_id),
    INDEX idx_declarations_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Verification queries (run manually)
-- ============================================================================
-- SHOW CREATE TABLE declarations;
-- DESCRIBE declarations;
