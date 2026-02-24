-- ============================================================
-- Executive Branch Tables — TPB2
-- Threats, responses, and ratings for accountability tracking
-- ============================================================

-- Threats to democracy linked to executive officials
CREATE TABLE IF NOT EXISTS executive_threats (
    threat_id INT AUTO_INCREMENT PRIMARY KEY,
    threat_date DATE NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    threat_type ENUM('tactical','strategic') DEFAULT 'tactical',
    target VARCHAR(100),
    source_url VARCHAR(500),
    action_script TEXT,
    official_id INT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_official (official_id),
    INDEX idx_active_date (is_active, threat_date DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User civic actions per threat (called rep, emailed, shared)
CREATE TABLE IF NOT EXISTS threat_responses (
    response_id INT AUTO_INCREMENT PRIMARY KEY,
    threat_id INT NOT NULL,
    user_id INT NOT NULL,
    action_type ENUM('called','emailed','shared') NOT NULL,
    rep_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_threat_user (threat_id, user_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Community danger ratings per threat (-10 to +10)
CREATE TABLE IF NOT EXISTS threat_ratings (
    rating_id INT AUTO_INCREMENT PRIMARY KEY,
    threat_id INT NOT NULL,
    user_id INT NOT NULL,
    rating TINYINT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_threat_user (threat_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
