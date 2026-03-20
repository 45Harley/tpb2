-- ============================================================
-- pages + nav_items tables
-- Created: 2026-03-19
-- ============================================================

-- ============================================================
-- 1. PAGES — page registry (what exists)
-- ============================================================

DROP TABLE IF EXISTS nav_items;
DROP TABLE IF EXISTS pages;

CREATE TABLE pages (
    page_id INT AUTO_INCREMENT PRIMARY KEY,
    page_key VARCHAR(80) NOT NULL UNIQUE,
    page_title VARCHAR(150) NOT NULL,
    file_path VARCHAR(200) NOT NULL,
    url_path VARCHAR(200) NOT NULL,
    section VARCHAR(50) DEFAULT NULL,
    clerk_key VARCHAR(50) DEFAULT NULL,
    readable TINYINT(1) NOT NULL DEFAULT 1,
    scope VARCHAR(20) DEFAULT NULL,
    min_identity_level TINYINT DEFAULT NULL,
    required_role_id INT DEFAULT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_section (section),
    INDEX idx_clerk (clerk_key),
    INDEX idx_url (url_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2. NAV_ITEMS — tree structure (how pages are presented)
--    Two columns do the work: parent_id + page_key
-- ============================================================

CREATE TABLE nav_items (
    nav_id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT DEFAULT NULL,
    page_key VARCHAR(80) NOT NULL,
    sort_order TINYINT NOT NULL DEFAULT 0,
    nav_label VARCHAR(50) DEFAULT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (parent_id) REFERENCES nav_items(nav_id),
    FOREIGN KEY (page_key) REFERENCES pages(page_key),
    INDEX idx_parent (parent_id),
    INDEX idx_page (page_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 3. POPULATE PAGES
-- ============================================================

INSERT INTO pages (page_key, page_title, file_path, url_path, section, readable, scope) VALUES
-- Home
('home',                    'Home',                         'index.php',                                    '/',                                    'home',         1, 'federal'),
-- Help
('help',                    'Help',                         'help/index.php',                               '/help/',                               'help',         1, NULL),
('help-guide',              'User Guide',                   'help/guide.php',                               '/help/guide.php',                      'help',         1, NULL),
('help-icons',              'Modal Icons',                  'help/icons.php',                               '/help/icons.php',                      'help',         1, NULL),
('help-getting-started',    'Getting Started',              'help/tpb-getting-started-tutorial.html',        '/help/tpb-getting-started-tutorial.html','help',        0, NULL),
-- My TPB
('profile',                 'My Profile',                   'profile.php',                                  '/profile.php',                         'mytpb',        1, NULL),
('welcome',                 'Getting Started',              'welcome.php',                                  '/welcome.php',                         'mytpb',        1, NULL),
('reps',                    'My Reps',                      'reps.php',                                     '/reps.php',                            'mytpb',        1, NULL),
('voice',                   'My Voice',                     'voice.php',                                    '/voice.php',                           'mytpb',        1, NULL),
('mandate-summary',         'My Mandate',                   'mandate-summary.php',                          '/mandate-summary.php',                 'mytpb',        1, NULL),
-- Talk
('talk',                    'USA Talk',                     'talk/index.php',                               '/talk/',                               'talk',         1, 'federal'),
('talk-brainstorm',         'Brainstorm',                   'talk/brainstorm.php',                          '/talk/brainstorm.php',                 'talk',         1, 'federal'),
('talk-groups',             'Groups',                       'talk/groups.php',                              '/talk/groups.php',                     'talk',         1, NULL),
('talk-history',            'History',                      'talk/history.php',                             '/talk/history.php',                    'talk',         1, NULL),
('talk-help',               'Talk Help',                    'talk/help.php',                                '/talk/help.php',                       'talk',         1, NULL),
-- USA
('usa',                     'USA',                          'usa/index.php',                                '/usa/',                                'usa',          1, 'federal'),
('usa-congress',            'Congressional Overview',       'usa/congressional-overview.php',                '/usa/congressional-overview.php',      'usa',          1, 'federal'),
('usa-executive',           'Executive Branch',             'usa/executive.php',                            '/usa/executive.php',                   'usa',          1, 'federal'),
('usa-executive-overview',  'Executive Overview',           'usa/executive-overview.php',                   '/usa/executive-overview.php',          'usa',          1, 'federal'),
('usa-judicial',            'Judicial Branch',              'usa/judicial.php',                             '/usa/judicial.php',                    'usa',          1, 'federal'),
('usa-judge',               'Judge Detail',                 'usa/judge.php',                                '/usa/judge.php',                       'usa',          0, 'federal'),
('usa-rep',                 'Representative Detail',        'usa/rep.php',                                  '/usa/rep.php',                         'usa',          0, 'federal'),
('usa-glossary',            'Government Glossary',          'usa/glossary.php',                             '/usa/glossary.php',                    'usa',          1, 'federal'),
('usa-digest',              'Congressional Digest',         'usa/digest.php',                               '/usa/digest.php',                      'usa',          1, 'federal'),
-- USA Docs
('usa-docs',                'Historical Documents',         'usa/docs/index.php',                           '/usa/docs/',                           'usa-docs',     1, 'federal'),
('usa-docs-constitution',   'U.S. Constitution',            'usa/docs/constitution.php',                    '/usa/docs/constitution.php',           'usa-docs',     1, 'federal'),
('usa-docs-declaration',    'Declaration of Independence',  'usa/docs/declaration.php',                     '/usa/docs/declaration.php',            'usa-docs',     1, 'federal'),
('usa-docs-gettysburg',     'Gettysburg Address',           'usa/docs/gettysburg.php',                      '/usa/docs/gettysburg.php',             'usa-docs',     1, 'federal'),
('usa-docs-federalist',     'Federalist Papers',            'usa/docs/federalist.php',                      '/usa/docs/federalist.php',             'usa-docs',     1, 'federal'),
('usa-docs-birmingham',     'Letter from Birmingham',       'usa/docs/birmingham.php',                      '/usa/docs/birmingham.php',             'usa-docs',     1, 'federal'),
('usa-docs-oath',           'Oath of Office',               'usa/docs/oath.php',                            '/usa/docs/oath.php',                   'usa-docs',     1, 'federal'),
-- Elections
('elections',               'Elections',                    'elections/index.php',                           '/elections/',                          'elections',    1, 'federal'),
('elections-the-fight',     'The Fight',                    'elections/the-fight.php',                       '/elections/the-fight.php',             'elections',    1, 'federal'),
('elections-the-amendment', 'The Amendment',                'elections/the-amendment.php',                   '/elections/the-amendment.php',         'elections',    1, 'federal'),
('elections-races',         'Race Ratings',                 'elections/races.php',                           '/elections/races.php',                 'elections',    1, 'federal'),
('elections-statements',    'Statements',                   'elections/statements.php',                      '/elections/statements.php',            'elections',    1, 'federal'),
('elections-threats',       'Threats',                      'elections/threats.php',                          '/elections/threats.php',               'elections',    1, 'federal'),
('elections-impeachment',   'Impeachment Vote',             'elections/impeachment-vote.php',                '/elections/impeachment-vote.php',      'elections',    1, 'federal'),
('28-index',                'The War (28th Amendment)',      '28/index.php',                                 '/28/',                                 'elections',    1, 'federal'),
('28-verify',               '28th Verify',                  '28/verify.php',                                '/28/verify.php',                       'elections',    0, 'federal'),
-- Polls
('poll',                    'Polls',                        'poll/index.php',                               '/poll/',                               'poll',         1, 'federal'),
('poll-national',           'National Polls',               'poll/national.php',                            '/poll/national.php',                   'poll',         1, 'federal'),
('poll-by-state',           'Polls by State',               'poll/by-state.php',                            '/poll/by-state.php',                   'poll',         1, 'state'),
('poll-by-rep',             'Polls by Rep',                 'poll/by-rep.php',                              '/poll/by-rep.php',                     'poll',         1, 'federal'),
('poll-closed',             'Closed Polls',                 'poll/closed.php',                              '/poll/closed.php',                     'poll',         1, NULL),
-- Volunteer
('volunteer',               'Volunteer Hub',                'volunteer/index.php',                          '/volunteer/',                          'volunteer',    1, NULL),
('volunteer-apply',         'Apply to Volunteer',           'volunteer/apply.php',                          '/volunteer/apply.php',                 'volunteer',    1, NULL),
('volunteer-state-builder', 'State Builder Start',          'volunteer/state-builder-start.php',             '/volunteer/state-builder-start.php',   'volunteer',    1, NULL),
('volunteer-task',          'Volunteer Task',               'volunteer/task.php',                           '/volunteer/task.php',                  'volunteer',    1, NULL),
-- Invite
('invite',                  'Invite',                       'invite/index.php',                             '/invite/',                             'invite',       1, NULL),
('invite-accept',           'Accept Invite',                'invite/accept.php',                            '/invite/accept.php',                   'invite',       0, NULL),
-- Constitution
('constitution',            'The Constitution',             'constitution/index.php',                       '/constitution/',                       'constitution', 1, 'federal'),
-- States/Towns
('state-ct',                'Connecticut',                  'z-states/ct/index.php',                        '/ct/',                                 'states',       1, 'state'),
('town-ct-putnam',          'Putnam, CT',                   'z-states/ct/putnam/index.php',                 '/ct/putnam/',                          'states',       1, 'town'),
('town-ct-putnam-calendar', 'Putnam Calendar',              'z-states/ct/putnam/calendar.php',              '/ct/putnam/calendar.php',              'states',       1, 'town'),
-- Auth
('join',                    'Create Account',               'join.php',                                     '/join.php',                            'auth',         0, NULL),
('login',                   'Log In',                       'login.php',                                    '/login.php',                           'auth',         0, NULL),
('logout',                  'Log Out',                      'logout.php',                                   '/logout.php',                          'auth',         0, NULL),
-- People Power (parked)
('0t',                      'People Power',                 '0t/index.php',                                 '/0t/',                                 '0t',           1, NULL),
('0t-stand',                'Stand',                        '0t/stand.php',                                 '/0t/stand.php',                        '0t',           1, NULL),
('0t-record',               'Record',                       '0t/record.php',                                '/0t/record.php',                       '0t',           1, NULL),
-- Misc standalone
('story',                   'Our Story',                    'story.php',                                    '/story.php',                           'misc',         1, NULL),
('thought',                 'Add Thought',                  'thought.php',                                  '/thought.php',                         'misc',         1, NULL),
('read',                    'Read Thoughts',                'read.php',                                     '/read.php',                            'misc',         1, NULL),
('map',                     'USA Map',                      'map.php',                                      '/map.php',                             'misc',         0, 'federal'),
('sitemap',                 'Sitemap',                      'sitemap.php',                                  '/sitemap.php',                         'misc',         0, NULL),
('town-fallback',           'Town Placeholder',             'town.php',                                     '/town.php',                            'misc',         1, NULL);

-- Mark People Power as disabled
UPDATE pages SET enabled = 0 WHERE section = '0t';

-- ============================================================
-- 4. POPULATE NAV_ITEMS (parent_id + page_key = the whole tree)
-- ============================================================

-- Top-level nav (parent_id = NULL)
INSERT INTO nav_items (nav_id, parent_id, page_key, sort_order, nav_label) VALUES
(1,  NULL, 'help',       1, 'Help'),
(5,  NULL, 'profile',    2, 'My TPB'),
(10, NULL, 'talk',       3, 'Talk'),
(15, NULL, 'usa',        4, 'USA'),
(31, NULL, 'elections',  5, 'Elections'),
(40, NULL, 'poll',       6, 'Polls'),
(45, NULL, 'volunteer',  7, 'Volunteer');

-- Help children
INSERT INTO nav_items (parent_id, page_key, sort_order) VALUES
(1, 'help-guide',           1),
(1, 'help-icons',           2),
(1, 'help-getting-started', 3);

-- My TPB children
INSERT INTO nav_items (parent_id, page_key, sort_order) VALUES
(5, 'profile',         1),
(5, 'welcome',         2),
(5, 'reps',            3),
(5, 'voice',           4),
(5, 'mandate-summary', 5);

-- Talk children
INSERT INTO nav_items (parent_id, page_key, sort_order) VALUES
(10, 'talk-brainstorm', 1),
(10, 'talk-groups',     2),
(10, 'talk-history',    3),
(10, 'talk-help',       4);

-- USA children
INSERT INTO nav_items (parent_id, page_key, sort_order) VALUES
(15, 'usa-congress',          1),
(15, 'usa-executive-overview',2),
(15, 'usa-executive',         3),
(15, 'usa-judicial',          4),
(15, 'usa-judge',             5),
(15, 'usa-rep',               6),
(15, 'usa-glossary',          7),
(15, 'usa-digest',            8);

-- USA > Documents (child of usa, nav_id=15)
INSERT INTO nav_items (nav_id, parent_id, page_key, sort_order) VALUES
(24, 15, 'usa-docs', 9);

-- USA > Documents children (parent_id=24)
INSERT INTO nav_items (parent_id, page_key, sort_order) VALUES
(24, 'usa-docs-constitution', 1),
(24, 'usa-docs-declaration',  2),
(24, 'usa-docs-gettysburg',   3),
(24, 'usa-docs-federalist',   4),
(24, 'usa-docs-birmingham',   5),
(24, 'usa-docs-oath',         6);

-- Elections children
INSERT INTO nav_items (parent_id, page_key, sort_order) VALUES
(31, 'elections-the-fight',     1),
(31, 'elections-the-amendment', 2),
(31, 'elections-races',         3),
(31, 'elections-statements',    4),
(31, 'elections-threats',       5),
(31, 'elections-impeachment',   6);

-- Elections > The War (child of elections, nav_id=31)
INSERT INTO nav_items (nav_id, parent_id, page_key, sort_order) VALUES
(38, 31, '28-index', 7);

-- The War children (parent_id=38)
INSERT INTO nav_items (parent_id, page_key, sort_order) VALUES
(38, '28-verify', 1);

-- Polls children
INSERT INTO nav_items (parent_id, page_key, sort_order) VALUES
(40, 'poll-national', 1),
(40, 'poll-by-state', 2),
(40, 'poll-by-rep',   3),
(40, 'poll-closed',   4);

-- Volunteer children
INSERT INTO nav_items (parent_id, page_key, sort_order) VALUES
(45, 'volunteer-apply',         1),
(45, 'volunteer-state-builder', 2),
(45, 'volunteer-task',          3);

-- States/Towns tree (use @var to capture auto-increment IDs)
INSERT INTO nav_items (parent_id, page_key, sort_order, nav_label) VALUES
(NULL, 'state-ct', 1, 'Connecticut');
SET @ct_id = LAST_INSERT_ID();

INSERT INTO nav_items (parent_id, page_key, sort_order, nav_label) VALUES
(@ct_id, 'town-ct-putnam', 1, 'Putnam');
SET @putnam_id = LAST_INSERT_ID();

INSERT INTO nav_items (parent_id, page_key, sort_order) VALUES
(@putnam_id, 'town-ct-putnam-calendar', 1);
