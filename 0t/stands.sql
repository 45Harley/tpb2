-- stands table for sandge5_tpb2
-- Run once to create table

CREATE TABLE IF NOT EXISTS stands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    state_code CHAR(2),
    token VARCHAR(64) UNIQUE,
    verified_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_email (email),
    INDEX idx_verified (verified_at),
    INDEX idx_state (state_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
