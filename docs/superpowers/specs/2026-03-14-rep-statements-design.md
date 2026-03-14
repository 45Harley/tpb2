# Rep Statements Tracking System — Design Spec

## Overview

Track what elected officials **say** — Truth Social posts, press conferences, interviews, official statements — scored on dual scales (criminality + benefit), tagged by policy topic and tense, with citizen voting. Complements the existing executive actions (threats) system which tracks what officials **do**.

**POC scope**: President only. Schema supports any elected official via FK to `elected_officials`.

## Problem Statement

The threat collection pipeline captures presidential **actions** (executive orders, agency directives, policy changes). What's missing is presidential **statements** — the public words that signal intent, shift narratives, make promises, and claim results. Citizens need both to hold representatives accountable.

## Design Decisions (from brainstorming)

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Table scope | Generic `rep_statements` (not presidential-specific) | Congress reps are the real target; President is just first |
| Scoring | Dual scale: criminality (0-1000) + benefit (0-1000) | Mirror existing criminality scale; actions can be both harmful and helpful |
| Statement scoring | Same dual scale as actions | Statements carry real weight — market moves, incitement, policy signals |
| Tense tagging | future / present / past | Enables accountability tracking (promise → action) |
| Sources | All — Truth Social, press, interviews, WH statements | Cast wide net, let pipeline sort relevance |
| Collection | Automated via local `claude -p` pipeline | Same proven 3-step pattern as threats; no manual entry |
| Collection frequency | Twice daily (6 AM + 6 PM) | High volume source (Truth Social); daily isn't enough |
| Citizen input | Agree/disagree voting, no manual scoring | Let citizens judge; raw quotes speak for themselves |
| Benefit scale visual | Mirror image of criminality scale | Consistent UX, reversed color palette |

## Data Model

### New table: `rep_statements`

```sql
CREATE TABLE rep_statements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    official_id INT NOT NULL,
    source VARCHAR(100) NOT NULL COMMENT 'Truth Social, Press Conference, Interview, WH Statement, etc.',
    source_url VARCHAR(500) DEFAULT NULL,
    content TEXT NOT NULL COMMENT 'Full quote or statement text',
    summary VARCHAR(500) DEFAULT NULL COMMENT 'AI-generated one-liner',
    policy_topic VARCHAR(100) DEFAULT NULL COMMENT 'One of 16 mandate policy topics',
    tense ENUM('future', 'present', 'past') DEFAULT NULL,
    severity_score SMALLINT DEFAULT NULL COMMENT 'Criminality scale 0-1000',
    benefit_score SMALLINT DEFAULT NULL COMMENT 'Benefit scale 0-1000',
    statement_date DATE NOT NULL COMMENT 'When the statement was made',
    related_threat_id INT DEFAULT NULL COMMENT 'FK to executive_threats if statement links to an action',
    agree_count INT NOT NULL DEFAULT 0,
    disagree_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (official_id) REFERENCES elected_officials(official_id),
    FOREIGN KEY (related_threat_id) REFERENCES executive_threats(threat_id),
    INDEX idx_official_date (official_id, statement_date DESC),
    INDEX idx_policy_topic (policy_topic),
    INDEX idx_tense (tense)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### New table: `rep_statement_votes`

```sql
CREATE TABLE rep_statement_votes (
    vote_id INT AUTO_INCREMENT PRIMARY KEY,
    statement_id INT NOT NULL,
    user_id INT NOT NULL,
    vote_type ENUM('agree', 'disagree') NOT NULL,
    voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_vote (statement_id, user_id),
    FOREIGN KEY (statement_id) REFERENCES rep_statements(id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Existing table change: `executive_threats`

```sql
ALTER TABLE executive_threats
    ADD COLUMN benefit_score SMALLINT DEFAULT NULL COMMENT 'Benefit scale 0-1000'
    AFTER severity_score;
```

## Benefit Scale (0-1000)

Mirrors the criminality scale with reversed color palette. Same geometric progression.

| Score | Zone | Color | Meaning |
|-------|------|-------|---------|
| 0 | Neutral | #9e9e9e | No measurable citizen benefit |
| 1-10 | Minor Positive | #c8e6c9 | Small symbolic gesture |
| 11-30 | Helpful | #a5d6a7 | Tangible benefit, limited scope |
| 31-70 | Significant | #81c784 | Meaningful improvement for a group |
| 71-150 | Major Benefit | #66bb6a | Broad impact, lasting improvement |
| 151-300 | Transformative | #4caf50 | Structural positive change |
| 301-500 | Historic | #43a047 | Generational-level benefit |
| 501-700 | Landmark | #388e3c | Reshapes institutions for the better |
| 701-900 | Epochal | #2e7d32 | Massive, irreversible positive change |
| 901-1000 | Civilizational | #1b5e20 | Existential-level improvement |

**Note**: The existing criminality scale has minor color mismatches between `severity.php` and `criminality-scale.php`. The benefit scale should use `benefit-severity.php` as the single source of truth for colors, and `benefit-scale.php` must pull from it (not define its own colors).

### Benefit scale component

**`includes/benefit-scale.php`** — embeddable color bar + dot legend, same layout as `includes/criminality-scale.php`.

**`includes/benefit-severity.php`** — `getBenefitZone($score)` returns `['label', 'color', 'class']`, mirrors `getSeverityZone()` in `includes/severity.php`.

## Collection Pipeline

### Architecture

Same 3-step local pattern as threat collection:

```
Windows Task Scheduler (6 AM + 6 PM)
  → collect-statements-local.bat
    → collect-statements-local.sh
      → Step 1 (SSH to server): Gather context
        - Last collected date from site_settings
        - Official info from elected_officials (President for POC)
      → Step 2 (local): claude -p with web search
        - Search Truth Social, press pool, WH.gov, news interviews
        - Extract quotes with source URLs
        - Tag each: policy_topic, tense, severity_score, benefit_score
        - Output structured JSON
      → Step 3 (SSH to server): Insert into rep_statements
        - Deduplicate: exact match on (official_id, statement_date, LEFT(content, 200))
        - Near-duplicates (same quote rephrased) handled by Claude in Step 2 (merge before output)
        - Update site_settings last_collected timestamp
```

### Files

| File | Purpose |
|------|---------|
| `scripts/maintenance/collect-statements-local.bat` | Windows Task Scheduler entry point |
| `scripts/maintenance/collect-statements-local.sh` | 3-step orchestrator |
| `scripts/maintenance/logs/statements-local.log` | Collection log |

### Admin toggles (site_settings)

| Key | Purpose |
|-----|---------|
| `statement_collect_local_enabled` | Enable/disable pipeline |
| `statement_collect_last_success` | Timestamp of last successful run |
| `statement_collect_last_result` | Success/failure message |

## Display Page

### `elections/statements.php`

Simple list view, filtered to President for POC.

**Each statement card shows:**
- Quote text (full content)
- Source badge (Truth Social, Press Conference, etc.)
- Date
- Tense badge (Future / Present / Past)
- Policy topic tag
- Dual scorecards: criminality bar + benefit bar (side by side)
- Agree/disagree vote buttons with counts

**Filters:**
- By policy topic (dropdown)
- By tense (future / present / past)
- By source

**No pagination for POC** — reverse chronological, all statements loaded.

## Vote API

### `api/vote-statement.php`

POST endpoint, same toggle/flip pattern as `talk/api.php?action=vote`:

```
POST /api/vote-statement.php
Body: { statement_id, vote_type: "agree"|"disagree" }
Auth: getUser($pdo), email_verified required
CORS: Access-Control-Allow-Origin: *, OPTIONS preflight handling
```

Toggle behavior:
- Same vote again → remove vote
- Different vote → flip vote
- New vote → insert (awards civic points via `PointLogger::award($userId, 'vote_cast', 'vote', $statementId)`)

Updates `rep_statements.agree_count` / `disagree_count` inline.

## UI Rebrand: Threats → Actions

Existing threat display pages update labels:
- "Executive Threats" → "Executive Actions" in headings
- Threat cards show both criminality + benefit scorecards when `benefit_score` is set
- No structural changes to threat pages, just label text

## 16 Policy Topics (shared taxonomy)

Same as mandates — used for `rep_statements.policy_topic`:

1. Economy & Jobs
2. Healthcare
3. Education
4. Environment & Climate
5. Immigration
6. National Security
7. Criminal Justice
8. Housing
9. Infrastructure
10. Social Services
11. Tax Policy
12. Civil Rights
13. Technology & Privacy
14. Foreign Policy
15. Agriculture
16. Government Reform

## What's NOT in the POC

- Congress rep statements (schema supports it, not collected yet)
- Mandate vs statement alignment dashboard
- Automated linking of statements to actions
- Statement-to-poll generation threshold
- Search/full-text search across statements
- Statement deduplication UI
- Notification/digest for new statements

## Future: Accountability Tracking

The `tense` field enables a future feature: tracking whether promises become actions.

- Future statement ("We're going to...") → watch for matching action in `executive_threats`
- Past claim ("We saved $2B...") → fact-checkable against data
- Link via `related_threat_id` when a statement materializes as an action
