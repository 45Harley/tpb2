# Poll System Redesign: Threat Roll Call + Congressional Accountability

**Date:** 2026-02-24
**Status:** Design approved, pending implementation plan

## Concept

Executive threats scoring 300+ on the criminality scale (0-1000) automatically become poll questions. Two audiences vote on the same threats with different questions:

- **Citizens:** "Is this acceptable?" — moral judgment on the act
- **Congress Members:** "Will you act on this?" — accountability for their response

Three public report views expose the gap between citizen opinion and congressional action.

## Decisions

| Decision | Answer |
|----------|--------|
| Threshold | 300+ severity only (113 threats currently) |
| Citizen question | "Is this acceptable?" |
| Rep question | "Will you act on this?" |
| Vote choices | Yea / Nay / Abstain |
| DB approach | Extend existing polls/poll_votes tables |
| Report views | 3 separate pages (national, by-state, by-rep) |
| Rep input | Verified rep accounts matched against elected_officials via bioguide_id |
| Anonymous votes | Not allowed — magic link verification required |
| Nav change | "Polls" replaces "Amendment 28" in main nav |
| Severity badges | Shown on each poll question |
| Auto-generation | Threats 300+ automatically become polls (no manual creation) |

## Voting Friction — Three Paths

| Visitor type | What they see | To vote |
|---|---|---|
| **Remembered citizen** (has session cookie) | Threats + "Is this acceptable?" + vote buttons | Click. Done. |
| **New visitor** (no session) | Threats + severity badges (read-only) + "Vote" prompt | Enter email → magic link → click → vote |
| **U.S. Representative/Senator** | "I am a U.S. Representative/Senator" checkbox → verify form | Bioguide ID + last name + state → match against `elected_officials` → sees "Will you act on this?" → vote |

All three paths on the same page (poll/index.php). Experience adapts based on who you are.

### Rep Verification

Rep enters three fields: bioguide_id, last name, state. All three must match a record in `elected_officials` table (535+ records). If match → instant verification, account linked to `official_id`. No admin review needed.

## DB Schema Changes

Extend existing tables (no new tables):

### 1. `polls` table — add columns
```sql
ALTER TABLE polls ADD COLUMN threat_id INT DEFAULT NULL;
ALTER TABLE polls ADD COLUMN poll_type ENUM('general', 'threat') DEFAULT 'general';
```
- `threat_id`: when set, poll is auto-generated from a 300+ threat
- `poll_type`: distinguishes citizen polls from threat roll calls
- Existing general polls untouched

### 2. `poll_votes` table — add abstain + rep flag
```sql
ALTER TABLE poll_votes MODIFY vote_choice ENUM('yes', 'no', 'yea', 'nay', 'abstain');
ALTER TABLE poll_votes ADD COLUMN is_rep_vote TINYINT(1) DEFAULT 0;
```
- `yea/nay/abstain` for threat polls, `yes/no` for general polls
- `is_rep_vote`: flags votes from verified congressional accounts

### 3. `users` table — rep link
```sql
ALTER TABLE users ADD COLUMN official_id INT DEFAULT NULL;
```
- Links a user account to `elected_officials` record
- When set, this user IS that rep — their votes show on by-rep view

## Pages

### Modified
| File | Change |
|------|--------|
| `poll/index.php` | Full redesign: threat polls with severity badges, three auth paths, yea/nay/abstain voting |
| `poll/admin.php` | Add threat-based poll management, auto-generation controls |
| `includes/nav.php` | Replace "Amendment 28" with "Polls" → `/poll/` |

### New
| File | Purpose |
|------|---------|
| `poll/national.php` | National aggregate — all citizen votes across all states |
| `poll/by-state.php` | State breakdown — per-state citizen votes, drill into specific state |
| `poll/by-rep.php` | Rep roll call — each rep's yea/nay/abstain record, gap vs citizens |

### Unchanged
| File | Notes |
|------|-------|
| `poll/closed.php` | Stays as-is for general polls |

### URL Structure
| URL | Page |
|-----|------|
| `/poll/` | Main voting page |
| `/poll/national/` | National view |
| `/poll/by-state/` | 50-state breakdown |
| `/poll/by-state/ct/` | Connecticut detail |
| `/poll/by-rep/` | All reps |
| `/poll/by-rep/B001277/` | Specific rep by bioguide |
| `/poll/closed/` | Closed general polls |

## Display — poll/index.php

### Top of page
- Title: "Polls" with subtitle "Hold power accountable."
- Three view links: National | By State | By Rep

### Poll cards (each 300+ threat)
- Severity badge (color-coded, e.g. "450 — High Crime")
- Threat title
- Question (varies by audience)
- Three buttons: Yea | Nay | Abstain
- Results bar after voting

### Auth states
- **Remembered citizen:** Vote buttons active immediately
- **New visitor:** "Vote — verify your email" prompt → magic link flow
- **Verified rep:** Question reads "Will you act on this?", badge shows their office

### "I am a U.S. Representative/Senator" checkbox
- Bottom of auth prompt area (citizens are primary audience)
- Reveals: Bioguide ID input, Last Name input, State dropdown
- Submit → match against `elected_officials` → if match, rep account created

### Sort
- Default: severity descending (worst crimes first)
- Options: date, tag/category

## Report Views

### National (poll/national.php)
- Aggregate citizen votes across all states
- Each threat: severity badge + title + results bar (Yea % | Nay % | Abstain %)
- Total votes cast per threat
- Summary stats at top: total threats polled, total votes, overall ratio
- Sort: severity (default), most votes, by tag

### By State (poll/by-state.php)
- Landing: 50-state table with per-state vote counts
- Drill into state (e.g. `/poll/by-state/ct/`): each threat with that state's citizen votes
- Comparison column: "Your state" vs "National" — alignment or divergence
- Sort: severity descending

### By Rep (poll/by-rep.php)
- Landing: all reps listed with vote record summary
  - Name, state, party, chamber
  - Threats responded to: X of 113
  - Yea % | Nay % | Abstain %
  - Silence rate — threats with no response
- Drill into rep (e.g. `/poll/by-rep/B001277/`): full roll call
  - Every 300+ threat with their position: Yea / Nay / Abstain / No Response
  - Severity badge on each
  - Side-by-side: rep vote vs state citizen vote on same threat
  - Gap highlighted when divergent
- Filter: state, chamber, party
- Sort: silence rate, yea %, state alignment

## The Accountability Gap

The by-rep page completes the feedback loop:

| Threat | Severity | State Citizens | Rep Position | Gap |
|--------|----------|---------------|--------------|-----|
| Defied 150+ Court Orders | 450 High Crime | 82% Not Acceptable | Nay | **82% gap** |
| ICE Kills 2 US Citizens | 750 Crime Against Humanity | 91% Not Acceptable | No Response | **91% silence** |

The gap feeds into `rep_scorecard` — a rep with high gap scores across multiple 300+ threats gets a very different scorecard than one aligned with their constituents.

## Feedback Loop (Complete)

```
Executive acts → Criminality score (0-1000) → Threats 300+ become polls
    → Citizens vote: "Is this acceptable?"
    → Reps vote: "Will you act on this?"
        → National view (country pulse)
        → State view (citizen pressure)
        → Rep view (accountability gap)
            → rep_scorecard (permanent record)
                → Voter decisions (elections)
```

## Connection to Existing Systems

- `executive_threats` — source of poll questions (300+ severity_score)
- `threat_tags` / `threat_tag_map` — category filtering on poll pages
- `elected_officials` — rep verification (bioguide_id, full_name, state_code)
- `rep_scorecard` — gap data feeds into congressional scoring
- `includes/get-user.php` — auth for all three voting paths
- Civic points — awarded on first threat vote (via PointLogger)
