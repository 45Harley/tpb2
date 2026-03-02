-- Invite system: referral tracking
CREATE TABLE IF NOT EXISTS invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invitor_user_id INT NOT NULL,
    invitee_email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    status ENUM('sent','joined') DEFAULT 'sent',
    invitee_user_id INT NULL,
    points_awarded TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    joined_at DATETIME NULL,
    FOREIGN KEY (invitor_user_id) REFERENCES users(user_id),
    FOREIGN KEY (invitee_user_id) REFERENCES users(user_id),
    INDEX idx_token (token),
    INDEX idx_invitor (invitor_user_id),
    INDEX idx_invitee_email (invitee_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Point action for referral (100 pts, no cooldown, no daily limit)
INSERT IGNORE INTO point_actions (action_name, points_value, cooldown_hours, daily_limit, is_active)
VALUES ('referral_joined', 100, 0, NULL, 1);
