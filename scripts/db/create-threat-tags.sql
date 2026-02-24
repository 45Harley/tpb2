-- ============================================================
-- Threat Tags + Criminality Scale — TPB2
-- Geometric 0-1000 severity scale + category tagging system
-- ============================================================

-- Add severity score to existing threats table
ALTER TABLE executive_threats ADD COLUMN severity_score SMALLINT DEFAULT NULL AFTER is_active;

-- Category tag definitions
CREATE TABLE IF NOT EXISTS threat_tags (
    tag_id INT AUTO_INCREMENT PRIMARY KEY,
    tag_name VARCHAR(50) NOT NULL UNIQUE,
    tag_label VARCHAR(100) NOT NULL,
    description TEXT,
    severity_floor SMALLINT DEFAULT 0,
    severity_ceiling SMALLINT DEFAULT 1000,
    color VARCHAR(7) DEFAULT '#666666',
    sort_order SMALLINT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Many-to-many: threats can have multiple tags
CREATE TABLE IF NOT EXISTS threat_tag_map (
    threat_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY (threat_id, tag_id),
    INDEX idx_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Initial tag definitions (15 categories)
-- severity_floor/ceiling = typical range for this crime type
-- ============================================================

INSERT INTO threat_tags (tag_name, tag_label, description, severity_floor, severity_ceiling, color, sort_order) VALUES
('judicial_defiance',   'Judicial Defiance',        'Defiance of court orders, attacks on judges, contempt of judiciary',              150, 700, '#8B0000', 1),
('press_freedom',       'Press Freedom',            'Attacks on media, banning reporters, conditioning access on editorial compliance', 31, 400, '#1a5276', 2),
('civil_rights',        'Civil Rights',             'Racial targeting, civil rights rollbacks, discrimination',                        71, 800, '#7d3c98', 3),
('war_powers',          'War Powers / Military',    'Unauthorized military action, war threats, Posse Comitatus violations',          150, 900, '#c0392b', 4),
('immigration',         'Immigration Enforcement',  'ICE operations, deportations, detention expansion, refugee targeting',             31, 700, '#d35400', 5),
('corruption',          'Corruption / Ethics',      'Conflicts of interest, dark money, misuse of funds, self-dealing',                11, 300, '#7f8c8d', 6),
('separation_of_powers','Separation of Powers',     'Overriding Congress, executive overreach, defying legislative authority',          71, 500, '#2c3e50', 7),
('election_integrity',  'Election Integrity',       'Voter suppression, election interference, undermining democratic processes',      150, 700, '#27ae60', 8),
('foreign_policy',      'Foreign Policy',           'International threats, sovereignty violations, alliance damage',                   31, 600, '#2980b9', 9),
('public_health',       'Public Health / Science',  'Vaccine policy changes, agency gutting, scientific integrity attacks',             71, 700, '#16a085', 10),
('federal_workforce',   'Federal Workforce / DOGE', 'Mass firings, agency dismantling, civil service destruction',                     31, 500, '#f39c12', 11),
('first_amendment',     'First Amendment',          'Speech suppression, protest crackdowns, criminalizing dissent',                    31, 500, '#e74c3c', 12),
('due_process',         'Due Process / Detention',  'Warrantless detention, denial of legal rights, prison expansion',                 71, 800, '#8e44ad', 13),
('fiscal',              'Taxpayer Funds / Waste',   'Luxury spending, no-bid contracts, private prison profiteering',                   11, 300, '#95a5a6', 14),
('epstein',             'Epstein / Cover-Up',       'Epstein file suppression, cabinet connections, DOJ obstruction',                  31, 500, '#34495e', 15);
