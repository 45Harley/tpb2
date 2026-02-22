-- Create committee tables for congressional committee tracking
-- Run once per environment. Safe to re-run (uses IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS committees (
    committee_id INT AUTO_INCREMENT PRIMARY KEY,
    system_code VARCHAR(20) NOT NULL,
    name VARCHAR(255) NOT NULL,
    chamber ENUM('Senate','House','Joint') NOT NULL,
    committee_type VARCHAR(50) DEFAULT NULL,
    parent_id INT DEFAULT NULL,
    congress INT NOT NULL DEFAULT 119,
    api_url VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_code_congress (system_code, congress),
    KEY idx_chamber (chamber),
    KEY idx_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS committee_memberships (
    membership_id INT AUTO_INCREMENT PRIMARY KEY,
    official_id INT NOT NULL,
    committee_id INT NOT NULL,
    role VARCHAR(50) DEFAULT 'Member',
    congress INT NOT NULL DEFAULT 119,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_official_committee (official_id, committee_id, congress),
    KEY idx_committee (committee_id),
    KEY idx_official (official_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
