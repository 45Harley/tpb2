-- Standard Group Templates: scope-aware civic groups with SIC code mapping
-- Replaces flat 28-per-level SIC groups with merged, scoped templates (22 total)
-- Run after: talk-phase8-geo-streams.sql

-- 1. Create template table
CREATE TABLE IF NOT EXISTS standard_group_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sic_codes VARCHAR(50) NOT NULL,
    min_scope ENUM('town','state','national') NOT NULL DEFAULT 'town',
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Create local department mapping table
CREATE TABLE IF NOT EXISTS town_department_map (
    id INT AUTO_INCREMENT PRIMARY KEY,
    town_id INT NOT NULL,
    template_id INT NOT NULL,
    local_name VARCHAR(200) NOT NULL,
    contact_url VARCHAR(500) DEFAULT NULL,
    UNIQUE KEY uq_town_template_name (town_id, template_id, local_name),
    KEY idx_town (town_id),
    KEY idx_template (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Seed 22 templates
INSERT INTO standard_group_templates (name, sic_codes, min_scope, sort_order) VALUES
-- Town level (13)
('Police & Public Safety',     '9221,9229', 'town', 1),
('Fire Protection',            '9224',      'town', 2),
('Courts & Legal',             '9211,9222', 'town', 3),
('Schools & Education',        '9411',      'town', 4),
('Public Health',              '9431',      'town', 5),
('Social Services',            '9441',      'town', 6),
('Roads & Transportation',     '9621',      'town', 7),
('Water, Sewer & Waste',       '9511',      'town', 8),
('Parks, Land & Conservation', '9512',      'town', 9),
('Housing',                    '9531',      'town', 10),
('Zoning & Planning',          '9532',      'town', 11),
('Budget & Taxes',             '9311',      'town', 12),
('General Government',         '9111,9121,9131,9199', 'town', 13),
-- State adds (5)
('Utilities Regulation',       '9631',      'state', 14),
('Agriculture',                '9641',      'state', 15),
('Commercial Licensing',       '9651',      'state', 16),
('Veterans\' Affairs',         '9451',      'state', 17),
('Corrections',                '9223',      'state', 18),
-- National adds (4)
('National Security',          '9711',      'national', 19),
('International Affairs',      '9721',      'national', 20),
('Space, Research & Technology','9661',      'national', 21),
('Economic Programs',          '9611',      'national', 22);

-- 4. Delete old flat standard groups (confirmed empty: 0 ideas, 0 members)
DELETE FROM idea_groups WHERE is_standard = 1;

-- 5. Add template_id column to idea_groups for linking
ALTER TABLE idea_groups ADD COLUMN template_id INT DEFAULT NULL AFTER is_standard;
ALTER TABLE idea_groups ADD INDEX idx_template_id (template_id);
