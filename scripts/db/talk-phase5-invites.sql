-- /talk Phase 5: Group Invites
-- Run on sandge5_tpb2
-- Date: 2026-02-14

CREATE TABLE group_invites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    invited_by INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    accept_token VARCHAR(64) NOT NULL,
    decline_token VARCHAR(64) NOT NULL,
    status ENUM('pending','accepted','declined','expired') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (group_id) REFERENCES idea_groups(id),
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    FOREIGN KEY (invited_by) REFERENCES users(user_id),
    UNIQUE KEY unique_accept_token (accept_token),
    UNIQUE KEY unique_decline_token (decline_token),
    INDEX idx_group_status (group_id, status),
    INDEX idx_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
