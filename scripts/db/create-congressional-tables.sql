-- Congressional data tables for bill tracking, votes, and related records
-- Run once per environment. Safe to re-run (uses IF NOT EXISTS).

-- =============================================
-- BILLS
-- =============================================
CREATE TABLE IF NOT EXISTS tracked_bills (
    bill_id INT AUTO_INCREMENT PRIMARY KEY,
    congress INT NOT NULL,
    bill_type VARCHAR(10) NOT NULL,          -- hr, s, hjres, sjres, hconres, sconres, hres, sres
    bill_number INT NOT NULL,
    title VARCHAR(500) NOT NULL,
    short_title VARCHAR(255) DEFAULT NULL,
    introduced_date DATE DEFAULT NULL,
    last_action_date DATE DEFAULT NULL,
    last_action_text VARCHAR(500) DEFAULT NULL,
    status VARCHAR(100) DEFAULT NULL,        -- introduced, passed_house, passed_senate, signed, vetoed
    sponsor_bioguide VARCHAR(10) DEFAULT NULL,
    sponsor_name VARCHAR(200) DEFAULT NULL,
    sponsor_party CHAR(1) DEFAULT NULL,
    sponsor_state VARCHAR(2) DEFAULT NULL,
    origin_chamber VARCHAR(10) DEFAULT NULL,
    congress_url VARCHAR(500) DEFAULT NULL,
    is_featured TINYINT(1) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_bill (congress, bill_type, bill_number),
    KEY idx_congress (congress),
    KEY idx_type (bill_type),
    KEY idx_featured (is_featured),
    KEY idx_sponsor (sponsor_bioguide),
    KEY idx_action_date (last_action_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- ROLL CALL VOTES (one per vote event)
-- =============================================
CREATE TABLE IF NOT EXISTS roll_call_votes (
    vote_id INT AUTO_INCREMENT PRIMARY KEY,
    congress INT NOT NULL,
    session_number TINYINT NOT NULL,
    chamber ENUM('House','Senate') NOT NULL,
    roll_call_number INT NOT NULL,
    bill_type VARCHAR(10) DEFAULT NULL,
    bill_number INT DEFAULT NULL,
    vote_question VARCHAR(500) DEFAULT NULL,
    vote_result VARCHAR(100) DEFAULT NULL,
    vote_date DATE DEFAULT NULL,
    vote_time TIME DEFAULT NULL,
    yea_total SMALLINT DEFAULT 0,
    nay_total SMALLINT DEFAULT 0,
    present_total SMALLINT DEFAULT 0,
    not_voting_total SMALLINT DEFAULT 0,
    source_url VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_vote (congress, chamber, session_number, roll_call_number),
    KEY idx_bill (bill_type, bill_number),
    KEY idx_date (vote_date),
    KEY idx_chamber (chamber)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- MEMBER VOTES (one per member per roll call)
-- =============================================
CREATE TABLE IF NOT EXISTS member_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vote_id INT NOT NULL,
    bioguide_id VARCHAR(10) DEFAULT NULL,
    official_id INT DEFAULT NULL,
    member_name VARCHAR(200) DEFAULT NULL,
    party CHAR(1) DEFAULT NULL,
    state VARCHAR(2) DEFAULT NULL,
    district SMALLINT DEFAULT NULL,
    vote ENUM('Yea','Nay','Not Voting','Present') NOT NULL,
    KEY idx_vote_id (vote_id),
    KEY idx_bioguide (bioguide_id),
    KEY idx_official (official_id),
    KEY idx_vote_value (vote),
    KEY idx_official_vote (official_id, vote),
    KEY idx_party_vote (party, vote_id, vote),
    KEY idx_vote_official (vote_id, official_id, vote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- REP SCORECARD (pre-computed metrics per member)
-- =============================================
CREATE TABLE IF NOT EXISTS rep_scorecard (
    id INT AUTO_INCREMENT PRIMARY KEY,
    official_id INT NOT NULL,
    congress INT NOT NULL,
    chamber ENUM('House','Senate') NOT NULL,
    -- Vote metrics
    total_roll_calls INT DEFAULT 0,
    votes_cast INT DEFAULT 0,
    missed_votes INT DEFAULT 0,
    yea_count INT DEFAULT 0,
    nay_count INT DEFAULT 0,
    present_count INT DEFAULT 0,
    participation_pct DECIMAL(5,1) DEFAULT 0,
    -- Party metrics
    party_loyalty_pct DECIMAL(5,1) DEFAULT 0,
    bipartisan_pct DECIMAL(5,1) DEFAULT 0,
    -- Legislative activity
    bills_sponsored INT DEFAULT 0,
    bills_substantive INT DEFAULT 0,
    bills_resolutions INT DEFAULT 0,
    amendments_sponsored INT DEFAULT 0,
    -- Rankings (within chamber)
    chamber_rank_participation SMALLINT DEFAULT NULL,
    chamber_rank_loyalty SMALLINT DEFAULT NULL,
    chamber_rank_bipartisan SMALLINT DEFAULT NULL,
    chamber_rank_bills SMALLINT DEFAULT NULL,
    -- Rankings (within state)
    state_rank_participation SMALLINT DEFAULT NULL,
    -- Chamber averages (denormalized for easy comparison)
    chamber_avg_participation DECIMAL(5,1) DEFAULT 0,
    chamber_avg_loyalty DECIMAL(5,1) DEFAULT 0,
    chamber_avg_bipartisan DECIMAL(5,1) DEFAULT 0,
    -- Metadata
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_scorecard (official_id, congress),
    KEY idx_chamber (chamber),
    KEY idx_congress (congress),
    KEY idx_participation (participation_pct),
    KEY idx_bipartisan (bipartisan_pct)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- AMENDMENTS
-- =============================================
CREATE TABLE IF NOT EXISTS amendments (
    amendment_id INT AUTO_INCREMENT PRIMARY KEY,
    congress INT NOT NULL,
    amendment_type VARCHAR(10) NOT NULL,     -- samdt, hamdt
    amendment_number INT NOT NULL,
    description TEXT DEFAULT NULL,
    purpose TEXT DEFAULT NULL,
    latest_action_date DATE DEFAULT NULL,
    latest_action_text VARCHAR(500) DEFAULT NULL,
    sponsor_bioguide VARCHAR(10) DEFAULT NULL,
    sponsor_name VARCHAR(200) DEFAULT NULL,
    amended_bill_type VARCHAR(10) DEFAULT NULL,
    amended_bill_number INT DEFAULT NULL,
    congress_url VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_amendment (congress, amendment_type, amendment_number),
    KEY idx_bill (amended_bill_type, amended_bill_number),
    KEY idx_sponsor (sponsor_bioguide)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- COMMITTEE REPORTS
-- =============================================
CREATE TABLE IF NOT EXISTS committee_reports (
    report_id INT AUTO_INCREMENT PRIMARY KEY,
    congress INT NOT NULL,
    report_type VARCHAR(10) NOT NULL,        -- hrpt, srpt, erpt
    report_number INT NOT NULL,
    title TEXT DEFAULT NULL,
    chamber VARCHAR(10) DEFAULT NULL,
    report_date DATE DEFAULT NULL,
    congress_url VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_report (congress, report_type, report_number),
    KEY idx_date (report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- COMMITTEE MEETINGS
-- =============================================
CREATE TABLE IF NOT EXISTS committee_meetings (
    meeting_id INT AUTO_INCREMENT PRIMARY KEY,
    congress INT NOT NULL,
    chamber VARCHAR(10) DEFAULT NULL,
    event_id VARCHAR(50) DEFAULT NULL,
    title TEXT DEFAULT NULL,
    meeting_date DATETIME DEFAULT NULL,
    committee_code VARCHAR(20) DEFAULT NULL,
    congress_url VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_event (congress, event_id),
    KEY idx_date (meeting_date),
    KEY idx_committee (committee_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- HEARINGS
-- =============================================
CREATE TABLE IF NOT EXISTS hearings (
    hearing_id INT AUTO_INCREMENT PRIMARY KEY,
    congress INT NOT NULL,
    chamber VARCHAR(10) DEFAULT NULL,
    hearing_number VARCHAR(20) DEFAULT NULL,
    title TEXT DEFAULT NULL,
    hearing_date DATE DEFAULT NULL,
    committee_code VARCHAR(20) DEFAULT NULL,
    congress_url VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_hearing (congress, chamber, hearing_number),
    KEY idx_date (hearing_date),
    KEY idx_committee (committee_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- NOMINATIONS
-- =============================================
CREATE TABLE IF NOT EXISTS nominations (
    nomination_id INT AUTO_INCREMENT PRIMARY KEY,
    congress INT NOT NULL,
    nomination_number VARCHAR(20) NOT NULL,
    description TEXT DEFAULT NULL,
    received_date DATE DEFAULT NULL,
    latest_action_date DATE DEFAULT NULL,
    latest_action_text VARCHAR(500) DEFAULT NULL,
    organization VARCHAR(255) DEFAULT NULL,
    congress_url VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_nomination (congress, nomination_number),
    KEY idx_date (received_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- CONGRESSIONAL COMMUNICATIONS
-- =============================================
CREATE TABLE IF NOT EXISTS congressional_communications (
    comm_id INT AUTO_INCREMENT PRIMARY KEY,
    congress INT NOT NULL,
    chamber ENUM('House','Senate') NOT NULL,
    comm_type VARCHAR(50) DEFAULT NULL,      -- EC, PM, ML etc.
    comm_number INT DEFAULT NULL,
    title TEXT DEFAULT NULL,
    comm_date DATE DEFAULT NULL,
    congress_url VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_comm (congress, chamber, comm_type, comm_number),
    KEY idx_date (comm_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
