# FEC Race Dashboards — 2026 Competitive Races

## Date: 2026-02-28

## Problem
Citizens can't easily see who's running in competitive races, who funds them, or how much money is flowing. TPB already tracks threats and pledges — adding campaign finance data completes the picture of who holds power and who's trying to get it.

## Scope
- **Competitive 2026 federal races only** — admin-curated list of ~30-50 House and Senate races
- **Data per race**: Candidates, party, incumbent/challenger status, total raised, total spent, cash on hand, top 10 donors/PACs
- **Single page**: `elections/races.php` with expandable race cards (no individual drill-down pages)

## Design

### Architecture
Admin picks which races to track via admin.php. A nightly cron pulls FEC data from the OpenFEC API into local DB cache tables. The public races page reads from cache — never hits FEC live. This gives fast page loads, editorial control, and resilience (FEC downtime doesn't break our pages).

### Data Flow
```
Admin adds race (state/district) in admin.php
        ↓
Nightly cron (sync-fec-data.php)
        ↓
  1. For each active race in fec_races:
  2. GET /v1/elections/ → candidates + financial summaries
  3. Upsert fec_candidates (receipts, disbursements, cash on hand)
  4. Match incumbents to elected_officials by state/district/name
  5. GET /v1/schedules/schedule_a/ → top 10 donors per candidate
  6. Upsert fec_top_contributors
        ↓
elections/races.php reads from DB cache
```

### Database (sandge5_election)

```sql
-- Admin-curated list of races to track
CREATE TABLE fec_races (
  race_id INT AUTO_INCREMENT PRIMARY KEY,
  cycle SMALLINT NOT NULL DEFAULT 2026,
  office ENUM('H','S') NOT NULL,
  state CHAR(2) NOT NULL,
  district CHAR(2) DEFAULT NULL,       -- House only, NULL for Senate
  rating VARCHAR(20) DEFAULT NULL,      -- Toss-Up, Lean D, Lean R, Likely D, Likely R
  notes TEXT DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY idx_race (cycle, office, state, district)
);

-- Cached candidate data from FEC
CREATE TABLE fec_candidates (
  fec_candidate_id VARCHAR(20) PRIMARY KEY, -- FEC ID like H8NY15148
  race_id INT NOT NULL,
  official_id INT DEFAULT NULL,              -- FK to elected_officials (incumbents)
  committee_id VARCHAR(12) DEFAULT NULL,     -- Principal committee for donor lookup
  name VARCHAR(150) NOT NULL,
  party VARCHAR(10) DEFAULT NULL,
  incumbent_challenge CHAR(1) DEFAULT NULL,  -- I/C/O
  total_receipts DECIMAL(14,2) DEFAULT 0,
  total_disbursements DECIMAL(14,2) DEFAULT 0,
  cash_on_hand DECIMAL(14,2) DEFAULT 0,
  last_filing_date DATE DEFAULT NULL,
  last_synced_at DATETIME DEFAULT NULL,
  INDEX idx_race (race_id)
);

-- Top 10 donors/PACs per candidate
CREATE TABLE fec_top_contributors (
  contributor_id INT AUTO_INCREMENT PRIMARY KEY,
  fec_candidate_id VARCHAR(20) NOT NULL,
  contributor_name VARCHAR(200) NOT NULL,
  contributor_type ENUM('individual','pac') NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL,
  employer VARCHAR(200) DEFAULT NULL,
  last_synced_at DATETIME DEFAULT NULL,
  INDEX idx_candidate (fec_candidate_id)
);
```

### FEC API

**Base URL**: `https://api.open.fec.gov/v1/`

**Key stored in**: `config.php` as `$config['fec_api_key']`

**Endpoints used**:
| Need | Endpoint | Key Params |
|------|----------|------------|
| Candidates in a race | `/v1/elections/` | `cycle=2026`, `office=house/senate`, `state`, `district` |
| Candidate detail | `/v1/candidate/{id}/` | Gets `committee_id` |
| Top donors | `/v1/schedules/schedule_a/` | `committee_id`, `sort=-contribution_receipt_amount`, `per_page=10` |

**Rate limits**: 1,000 req/hr with registered key. ~150 calls per nightly sync (50 races × 3 calls). Well within limits.

**Important**: The `/v1/elections/` endpoint returns candidates with financial summaries (`total_receipts`, `total_disbursements`, `cash_on_hand_end_period`) — so we get candidates + financials in one call per race. The donor lookup is a separate call per candidate.

### Admin UI (admin.php)

New **Races** tab with:

**Race list**: Table of all tracked races — State, District, Office, Rating, # Candidates, Active toggle. "Add Race" button.

**Add Race form**: State dropdown, District input (House only), Office radio (H/S), Rating dropdown (Toss-Up/Lean D/Lean R/Likely D/Likely R). On save, race appears in list and cron syncs on next run.

**Sync Now**: Button per race to pull fresh FEC data immediately (for one-off updates outside the nightly cron).

### Public Page (elections/races.php)

Single scrollable page with all active races. Each race is a card:

**Card header**: `NY-19 House` with rating badge (color-coded: Toss-Up = purple, Lean D = light blue, Lean R = light red, etc.)

**Candidates inline**: Side-by-side. Each candidate shows:
- Name, party badge, incumbent/challenger indicator
- Horizontal bar chart: Raised (green), Spent (red), Cash on Hand (blue)
- Dollar amounts labeled

**Top donors section**: Collapsed by default, click to expand. Shows top 10 donors/PACs with amounts.

**Incumbent link**: If incumbent has threat data in TPB, show a badge linking to threats.php.

**Nav integration**: Added to elections nav: Threats | The Fight | **Races** | The Amendment

**Share buttons**: X, Bluesky, Facebook, Email (same pattern as threats.php)

**OG meta tags**: Page title + description for social sharing

### Cron Script (scripts/maintenance/sync-fec-data.php)

Runs nightly at 2 AM ET (7 AM UTC): `0 7 * * *`

```
1. Check site_settings: fec_sync_enabled = '1'
2. For each active race in fec_races:
   a. GET /v1/elections/ for that race → candidates + financials
   b. Upsert each candidate into fec_candidates
   c. Match incumbents: if incumbent_challenge='I', look up elected_officials by state/district/party
   d. For each candidate with a committee_id:
      - GET /v1/schedules/schedule_a/ → top 10 contributors
      - Delete old contributors, insert new top 10
   e. Throttle: usleep(500000) between API calls (stay well under rate limit)
3. Log results: "FEC sync complete. Races: X, Candidates: Y, Time: Zs"
```

### Files

| File | Action | Purpose |
|------|--------|---------|
| `scripts/db/add-fec-tables.sql` | Create | DB migration |
| `scripts/maintenance/sync-fec-data.php` | Create | Nightly FEC data sync cron |
| `elections/races.php` | Create | Public race dashboard page |
| `admin.php` | Modify | Add Races tab |
| `config.php` | Modify (on server) | Add `fec_api_key` |
| `includes/header.php` or elections nav | Modify | Add "Races" link |

### Security
- FEC API key never exposed to frontend (cron runs server-side)
- All user-facing data served from local DB cache
- Standard TPB auth for admin operations (CSRF, role check)
- No PII stored — FEC contributor data is already public record
