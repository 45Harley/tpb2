-- /talk Phase 3: Groups, Idea Links, AI-as-Node
-- Run on sandge5_tpb2
-- Date: 2026-02-13

-- 1. Add clerk_key to idea_log (identifies which AI role created a row)
ALTER TABLE idea_log ADD COLUMN clerk_key VARCHAR(50) NULL;

-- 2. Create idea_links (many-to-many thematic linking between ideas)
CREATE TABLE idea_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    idea_id_a INT NOT NULL,
    idea_id_b INT NOT NULL,
    link_type VARCHAR(30) NOT NULL DEFAULT 'related',
    created_by INT NULL,
    clerk_key VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (idea_id_a) REFERENCES idea_log(id),
    FOREIGN KEY (idea_id_b) REFERENCES idea_log(id),
    FOREIGN KEY (created_by) REFERENCES users(user_id),
    UNIQUE KEY unique_link (idea_id_a, idea_id_b, link_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Create idea_groups (deliberation circuits)
CREATE TABLE idea_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_group_id INT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    tags VARCHAR(255) NULL,
    status ENUM('forming','active','crystallizing','crystallized','archived') DEFAULT 'forming',
    access_level ENUM('open','closed','observable') DEFAULT 'observable',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_group_id) REFERENCES idea_groups(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Create idea_group_members (who's in the circuit)
CREATE TABLE idea_group_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('member','facilitator','observer') DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES idea_groups(id),
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    UNIQUE KEY unique_membership (group_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
