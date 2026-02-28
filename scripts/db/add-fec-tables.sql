-- ============================================================
-- FEC Race Dashboard Tables — Schema
-- Competitive race tracking with cached FEC data.
-- Run on sandge5_election database.
-- ============================================================

CREATE TABLE IF NOT EXISTS fec_races (
  race_id INT AUTO_INCREMENT PRIMARY KEY,
  cycle SMALLINT NOT NULL DEFAULT 2026,
  office ENUM('H','S') NOT NULL,
  state CHAR(2) NOT NULL,
  district CHAR(2) DEFAULT NULL,
  rating VARCHAR(20) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY idx_race (cycle, office, state, district)
);

CREATE TABLE IF NOT EXISTS fec_candidates (
  fec_candidate_id VARCHAR(20) PRIMARY KEY,
  race_id INT NOT NULL,
  official_id INT DEFAULT NULL,
  committee_id VARCHAR(12) DEFAULT NULL,
  name VARCHAR(150) NOT NULL,
  party VARCHAR(10) DEFAULT NULL,
  incumbent_challenge CHAR(1) DEFAULT NULL,
  total_receipts DECIMAL(14,2) DEFAULT 0,
  total_disbursements DECIMAL(14,2) DEFAULT 0,
  cash_on_hand DECIMAL(14,2) DEFAULT 0,
  last_filing_date DATE DEFAULT NULL,
  last_synced_at DATETIME DEFAULT NULL,
  INDEX idx_race (race_id)
);

CREATE TABLE IF NOT EXISTS fec_top_contributors (
  contributor_id INT AUTO_INCREMENT PRIMARY KEY,
  fec_candidate_id VARCHAR(20) NOT NULL,
  contributor_name VARCHAR(200) NOT NULL,
  contributor_type ENUM('individual','pac') NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL,
  employer VARCHAR(200) DEFAULT NULL,
  last_synced_at DATETIME DEFAULT NULL,
  INDEX idx_candidate (fec_candidate_id)
);
