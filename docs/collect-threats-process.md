# Collect Threats Process

Comprehensive guide for researching, scoring, tagging, and loading threats to constitutional order into the TPB2 accountability system. Covers all three branches of government.

---

## Overview

TPB tracks documented threats to constitutional order — actions by government officials that violate law, defy courts, attack institutions, or harm citizens. Each threat is linked to a responsible official, scored on a 0-1000 criminality scale, tagged by category, and (if scoring 300+) turned into a poll question for citizens and representatives to vote on.

### Three Branches

The system was built around executive branch threats but now covers all three:

| Branch | Scope | Examples |
|--------|-------|---------|
| **Executive** | President, VP, Cabinet, agency heads (~27 officials) | Executive orders, firings, agency dismantling, court defiance, immigration enforcement, DOGE cuts |
| **Congressional** | 100 senators + 435 house reps | Blocking oversight, enabling overreach, corruption, insider trading, obstruction of investigations |
| **Judicial** | 9 SCOTUS + ~305 circuit + ~1,248 district judges | Ethics violations, partisan rulings, undisclosed gifts, refusal to recuse, defiance of precedent |

### Gathering Start Dates

Each branch has a different backlog. The first collection for each branch must look back to its start date and gather everything forward. Once all three are caught up, they sync to the same rolling cadence.

| Branch | Gathering Start Date | Caught Up Through | Status |
|--------|---------------------|-------------------|--------|
| **Executive** | Jan 20, 2025 (inauguration) | Feb 26, 2026 | Active — 236 threats collected |
| **Congressional** | Jan 20, 2025 (118th/119th Congress session) | — | Not started — first collection must cover Jan 2025 – present |
| **Judicial** | Jan 2024 (ProPublica/ethics revelations) | Feb 26, 2026 | Active — 15 threats collected (SCOTUS ethics + Cannon) |

**Why different start dates:**
- **Executive** starts at inauguration — that's when this administration's actions began
- **Congressional** starts at the same point — the enabling/obstruction by Congress is in response to the same administration
- **Judicial** reaches back further to Jan 2024 — the SCOTUS ethics crisis (Thomas gifts, Alito flags, immunity ruling) predates the current administration and is its own ongoing pattern

**Sync target:** Once all three branches are caught up to the current date, future collections gather all three branches together in a single pass, rolling forward from the last collection date.

### Data Pipeline

```
News sources → Research → Deduplicate → INSERT SQL → Load → Score → Tag → Generate polls
                                                                              ↓
                                                        /poll/index.php (citizens & reps vote)
                                                              ↓
                                        /poll/national.php, /poll/by-state.php, /poll/by-rep.php
```

---

## Database

- **Database:** `sandge5_tpb2`
- **Table:** `executive_threats` (name is historical — holds threats from all branches)
- **Access:** Claude Code queries via SSH, or phpMyAdmin
- **Old table:** `sandge5_election.trump_threats` (migrated Feb 24, 2026 — no longer used)

### Schema: `executive_threats`

| Column | Type | Description |
|--------|------|-------------|
| `threat_id` | INT AUTO_INCREMENT PK | |
| `threat_date` | DATE NOT NULL | When the threat occurred or was announced |
| `title` | VARCHAR(200) NOT NULL | Short factual headline (see writing standards below) |
| `description` | TEXT | 2-4 sentence impact description |
| `threat_type` | ENUM('tactical','strategic') | `tactical` = specific action, `strategic` = systemic/institutional |
| `target` | VARCHAR(100) | What's being threatened (e.g., "FBI Independence", "Judicial Ethics") |
| `source_url` | VARCHAR(500) | Link to primary news source |
| `action_script` | TEXT | What citizens can do about it |
| `official_id` | INT | FK to `elected_officials` — the primary responsible official |
| `is_active` | TINYINT(1) DEFAULT 1 | 1 = ongoing, 0 = resolved |
| `severity_score` | SMALLINT DEFAULT NULL | 0-1000 criminality scale (see scoring section) |
| `branch` | ENUM('executive','congressional','judicial') | Which branch the primary official belongs to |
| `created_at` | TIMESTAMP | Auto-set on insert |

### Related Tables

| Table | Purpose |
|-------|---------|
| `threat_tags` | 15+ category definitions with severity floor/ceiling and color |
| `threat_tag_map` | Many-to-many: threat_id ↔ tag_id |
| `threat_responses` | Civic actions (called, emailed, shared) per threat per user |
| `threat_ratings` | Community danger ratings (-10 to +10) per threat per user |
| `polls` | Poll row per 300+ threat (poll_type='threat', links via threat_id) |
| `poll_votes` | Citizen and rep votes (yea/nay/abstain) |

### Schema Change: `branch` Column

Before the next collection, run this migration:

```sql
ALTER TABLE executive_threats
  ADD COLUMN branch ENUM('executive','congressional','judicial')
  DEFAULT 'executive' AFTER official_id;

-- Backfill: all 227 existing threats are executive
UPDATE executive_threats SET branch = 'executive' WHERE branch IS NULL;
```

---

## Step 0: Research

### News Sources

**Tier 1 — Primary (prefer these):**
- AP News, Reuters (wire services, factual baseline)
- NPR, PBS NewsHour
- Government sites (.gov — court filings, congressional records, executive orders)
- Court dockets (CourtListener, PACER)

**Tier 2 — Investigative:**
- ProPublica, The Intercept, Axios
- NYT, Washington Post, CNN
- Al Jazeera, Democracy Now

**Tier 3 — Supplementary (verify with Tier 1/2):**
- Politico, The Hill, HuffPost
- State/local outlets for state-specific impacts

### What to Look For by Branch

**Executive:**
- Executive orders, presidential memoranda
- Firings, agency dismantling, DOGE cuts
- Court order defiance
- Immigration enforcement (ICE operations, deportations, detention)
- Attacks on press, conditioning access
- Military actions, war threats
- Political retribution, loyalty tests
- Cabinet corruption, ethics violations, undisclosed conflicts

**Congressional:**
- Votes against constitutional protections
- Blocking oversight, refusing subpoenas
- Enabling executive overreach (failing to check)
- Insider trading, corruption, campaign finance violations
- Obstructing investigations
- Spreading disinformation that undermines institutions
- Gerrymandering, voter suppression legislation

**Judicial:**
- Ethics violations (undisclosed gifts, luxury travel, financial conflicts)
- Refusal to recuse despite clear conflicts
- Partisan rulings that defy established precedent
- Shadow docket abuse (SCOTUS)
- Enabling executive lawlessness through rulings
- Attacks on judicial independence (threats against other judges)
- Specific focus: Alito (undisclosed gifts, partisan signaling), Thomas (Harlan Crow gifts, wife's Jan 6 involvement, refusal to recuse), Kavanaugh (enabling executive overreach)

---

## Step 1: Get Existing Threats for Dedup

Query the database before researching to avoid duplicates:

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/q.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$p = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$r = \$p->query('SELECT threat_id, threat_date, title, target, official_id, branch FROM executive_threats ORDER BY threat_date DESC');
while(\$row=\$r->fetch(PDO::FETCH_ASSOC)) echo implode(' | ', \$row).PHP_EOL;
SCRIPT
php /tmp/q.php && rm /tmp/q.php"
```

### Filter by branch for targeted dedup:

```sql
-- Just judicial threats
SELECT threat_id, threat_date, title, official_id
FROM executive_threats WHERE branch = 'judicial' ORDER BY threat_date DESC;

-- Just congressional threats
SELECT threat_id, threat_date, title, official_id
FROM executive_threats WHERE branch = 'congressional' ORDER BY threat_date DESC;
```

---

## Step 2: Deduplicate

Compare new threats against existing by:

1. **Title similarity** — fuzzy match, same event described differently
2. **Date + target combo** — same date, same target = likely duplicate
3. **Source URL match** — same article already captured
4. **Official + action combo** — same person doing same thing on different dates may be an escalation (new threat) or a follow-up (update existing)

**Cross-branch dedup:** A single event can involve multiple branches. For example, a SCOTUS ruling (judicial threat) that the president then defies (executive threat) and senators refuse to investigate (congressional threat). These are **separate threats** linked to different officials — not duplicates. The descriptions should reference the connected threats.

Skip anything already captured. When in doubt, include it — it's easier to merge duplicates later than to miss a threat.

---

## Step 3: Build INSERT SQL File

Save to `scripts/db/threats-YYYY-MM-DD.sql`.

### INSERT Format

```sql
INSERT INTO executive_threats
  (threat_date, title, description, threat_type, target,
   source_url, action_script, official_id, is_active, branch)
VALUES

('YYYY-MM-DD', 'Title: Factual, 200 chars max, no editorializing',
 'Description: 2-4 sentences. What happened, who it affects, what the impact is. Factual and specific.',
 'tactical', 'What Is Threatened',
 'https://source-url.com/article',
 'Call your [senators/representatives]. Ask: "Specific question." Support [specific org] challenging this in court.',
 OFFICIAL_ID, 1, 'branch');
```

### Writing Standards

**Title (varchar 200):**
- Factual headline, not editorial
- Name the actor and the action
- Good: `Thomas Accepted $4.2M in Undisclosed Gifts From Harlan Crow Over Two Decades`
- Bad: `Corrupt Justice Thomas Takes Bribes`
- Good: `Senate Judiciary Committee Blocks Subpoena for Alito Ethics Records`
- Bad: `Senators Cover for Alito Again`

**Description (text):**
- 2-4 sentences
- What happened, who did it, who it affects, what the measurable impact is
- Include numbers, dates, dollar amounts when available
- Factual and specific — describe, don't editorialize
- Reference connected threats from other branches when relevant

**Threat type:**
- `tactical` — specific, discrete action (a vote, a ruling, a firing, an order)
- `strategic` — systemic pattern or institutional damage (ongoing campaign, structural dismantling)

**Target (varchar 100):**
- What institution, right, or population is being threatened
- Examples: "Judicial Ethics", "First Amendment", "FBI Independence", "Congressional Oversight Authority", "Voting Rights"

**Action script (text):**
- Concrete citizen actions, not vague platitudes
- Pattern: `Contact your [who]. Ask: "[specific question]." Support [specific org/effort].`
- Include committee names when relevant
- Include specific questions citizens should ask

**Branch:**
- `executive` — President, VP, Cabinet, agency heads, appointees
- `congressional` — Senators, House members
- `judicial` — Federal judges at any tier

### Example: Executive Threat

```sql
('2026-02-20', 'Administration Defies Federal Court Order on USAID Funding for Third Time',
 'Despite three separate federal court orders requiring restoration of USAID funding, the administration has refused to comply. Judge Jackson held the administration in contempt. Over $3 billion in congressionally appropriated foreign aid remains frozen, affecting 120+ countries.',
 'tactical', 'Rule of Law, Congressional Appropriations, Foreign Aid',
 'https://www.reuters.com/world/us/judge-holds-admin-contempt-usaid-2026-02-20/',
 'Call your senators on the Foreign Relations Committee. Ask: "What are you doing about the administration''s repeated defiance of court orders on USAID funding?" Support the ACLU''s emergency court filings.',
 326, 1, 'executive'),
```

### Example: Judicial Threat

```sql
('2025-08-15', 'Thomas Accepted $4.2M in Undisclosed Gifts From Harlan Crow Over Two Decades',
 'ProPublica investigation revealed Justice Clarence Thomas accepted luxury travel, private jet flights, yacht trips, a $267,000 real estate transaction, and private school tuition for a family member from billionaire Harlan Crow — totaling over $4.2 million in undisclosed gifts. Thomas failed to report these on mandatory financial disclosure forms for over 20 years.',
 'strategic', 'Judicial Ethics, Financial Disclosure, Supreme Court Integrity',
 'https://www.propublica.org/article/clarence-thomas-harlan-crow-undisclosed-gifts',
 'Contact your senators on the Judiciary Committee. Ask: "Will you support enforceable ethics rules for the Supreme Court?" Support Fix the Court and Accountable.US campaigns for judicial ethics reform.',
 328, 1, 'judicial'),
```

### Example: Congressional Threat

```sql
('2026-01-15', 'Senate Judiciary Republicans Block Subpoena for Supreme Court Ethics Investigation',
 'Republican members of the Senate Judiciary Committee voted as a block to defeat a subpoena for financial records related to undisclosed gifts to Supreme Court justices. The vote was 11-10 along party lines. Committee Democrats argued the records are necessary for pending ethics legislation.',
 'tactical', 'Congressional Oversight, Judicial Ethics, Separation of Powers',
 'https://www.washingtonpost.com/politics/senate-judiciary-blocks-scotus-subpoena/',
 'Call your senators, especially those on the Judiciary Committee. Ask: "Why did you vote to block oversight of Supreme Court ethics violations?" Demand a floor vote on the Supreme Court Ethics Act.',
 SENATOR_OFFICIAL_ID, 1, 'congressional'),
```

### Section Headers in SQL Files

Group threats by branch and topic with comment headers:

```sql
-- ============================================================
-- JUDICIAL — SCOTUS Ethics (Jan 2025 - Feb 2026)
-- ============================================================

-- ============================================================
-- CONGRESSIONAL — Oversight Obstruction
-- ============================================================

-- ============================================================
-- EXECUTIVE — Court Defiance (Feb 2026)
-- ============================================================
```

---

## Step 4: Upload and Load

```bash
# Upload SQL file
scp -P 2222 scripts/db/threats-YYYY-MM-DD.sql \
  sandge5@ecngx308.inmotionhosting.com:/tmp/threats.sql

# Load via PHP
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "cat > /tmp/load.php << 'SCRIPT'
<?php
\$c = require '/home/sandge5/tpb2.sandgems.net/config.php';
\$pdo = new PDO('mysql:host='.\$c['host'].';dbname=sandge5_tpb2', \$c['username'], \$c['password']);
\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
\$before = \$pdo->query('SELECT COUNT(*) FROM executive_threats')->fetchColumn();
\$sql = file_get_contents('/tmp/threats.sql');
\$sql = preg_replace('/^--.*$/m', '', \$sql);
\$pdo->exec(\$sql);
\$after = \$pdo->query('SELECT COUNT(*) FROM executive_threats')->fetchColumn();
echo \"Before: \$before, After: \$after, Added: \" . (\$after - \$before) . \"\\n\";
SCRIPT
ea-php84 /tmp/load.php && rm /tmp/load.php /tmp/threats.sql"
```

### Verify after loading:

```sql
-- Count by branch
SELECT branch, COUNT(*) as cnt FROM executive_threats GROUP BY branch;

-- Newest threats
SELECT threat_id, threat_date, title, branch, official_id
FROM executive_threats ORDER BY threat_id DESC LIMIT 20;
```

---

## Step 5: Score Severity

### The Criminality Scale (0-1000, Geometric)

Rates the **act**, not the actor. Each zone roughly represents a 2-3x increase in severity.

| Score | Zone | Color | Meaning | Examples |
|-------|------|-------|---------|----------|
| 0 | Clean | #4caf50 | No issue | — |
| 1-10 | Questionable | #8bc34a | Gray area, poor judgment | IndyCar race near the Mall |
| 11-30 | Misconduct | #cddc39 | Minor abuse of position | Undisclosed minor conflicts |
| **31-70** | **Misdemeanor** | **#ffeb3b** | **"High Crimes and Misdemeanors" threshold starts at 31** | Review NGO funding, hiring freeze |
| 71-150 | Felony | #ff9800 | Single serious violation | Strip union rights, $1.5T military budget |
| 151-300 | Serious Felony | #ff5722 | Multiple victims or sustained pattern | Revoked TPS, shut down oversight, EOs targeting law firms |
| **301-500** | **High Crime** | **#f44336** | **Poll threshold: 300+ becomes a poll question** | Mass pardons (400), troops to Chicago (400), USAID cancelled (400) |
| 501-700 | Atrocity | #d32f2f | Institutional destruction | Alien Enemies Act (500), denaturalization program (550) |
| 701-900 | Crime Against Humanity | #b71c1c | Mass harm, deaths | DOGE cuts → 720K projected deaths (800), Southern Spear 148 killed (800) |
| 901-1000 | Genocide | #000 | Existential threat to population | — |

### Key Thresholds

- **31** = Constitutional "High Crimes and Misdemeanors" — the impeachment bar is this low
- **300** = Becomes a poll question — citizens and reps vote on it
- The scale is **geometric**, not linear — the distance from 300 to 500 represents a much larger escalation than 100 to 300

### Scoring Guidelines

1. **Rate the act, not the person** — a firing is a firing whether Trump or Biden does it
2. **Consider measurable impact** — how many people affected, dollars, lives, institutions damaged
3. **Consider reversibility** — can this be undone? Court orders defied are worse than bad policy
4. **Consider precedent** — first-time violations of norms score higher than repeated types
5. **Consider intent** — accidental harm vs. deliberate targeting matters
6. **When uncertain, score conservatively** — it's easier to raise a score than to explain why you lowered it

### Scoring Format

Scores are applied via UPDATE after loading threats:

```sql
UPDATE executive_threats SET severity_score = CASE threat_id
  WHEN 228 THEN 450  -- Thomas Undisclosed Gifts
  WHEN 229 THEN 350  -- Senate Blocks SCOTUS Subpoena
  WHEN 230 THEN 500  -- Admin Defies Court Third Time
  -- ... more ...
END
WHERE threat_id IN (228, 229, 230);
```

---

## Step 6: Assign Tags

### The 15 Tag Categories

| tag_id | tag_name | Label | Severity Range | Color |
|--------|----------|-------|---------------|-------|
| 1 | judicial_defiance | Judicial Defiance | 150-700 | #8B0000 |
| 2 | press_freedom | Press Freedom | 31-400 | #1a5276 |
| 3 | civil_rights | Civil Rights | 71-800 | #7d3c98 |
| 4 | war_powers | War Powers / Military | 150-900 | #c0392b |
| 5 | immigration | Immigration Enforcement | 31-700 | #d35400 |
| 6 | corruption | Corruption / Ethics | 11-300 | #7f8c8d |
| 7 | separation_of_powers | Separation of Powers | 71-500 | #2c3e50 |
| 8 | election_integrity | Election Integrity | 150-700 | #27ae60 |
| 9 | foreign_policy | Foreign Policy | 31-600 | #2980b9 |
| 10 | public_health | Public Health / Science | 71-700 | #16a085 |
| 11 | federal_workforce | Federal Workforce / DOGE | 31-500 | #f39c12 |
| 12 | first_amendment | First Amendment | 31-500 | #e74c3c |
| 13 | due_process | Due Process / Detention | 71-800 | #8e44ad |
| 14 | fiscal | Taxpayer Funds / Waste | 11-300 | #95a5a6 |
| 15 | epstein | Epstein / Cover-Up | 31-500 | #34495e |

### Tagging Guidelines

- Each threat gets **1-3 tags** (primary category + secondary where applicable)
- Choose the tag that best describes the *type of harm*, not just the topic area
- Cross-branch threats often carry tags like `separation_of_powers` (7) or `judicial_defiance` (1)
- Ethics violations by judges → `corruption` (6) + `judicial_defiance` (1) if it involves defying norms
- Congressional enabling of executive overreach → `separation_of_powers` (7)

### New Tags to Consider Adding

As judicial and congressional threats grow, these categories may be needed:

- `judicial_ethics` — distinct from judicial_defiance (gift disclosure failures vs. defying courts)
- `congressional_obstruction` — blocking oversight, refusing subpoenas, stonewalling investigations
- `insider_trading` — congressional financial corruption specifically

Add new tags via:

```sql
INSERT INTO threat_tags (tag_name, tag_label, description, severity_floor, severity_ceiling, color, sort_order)
VALUES ('judicial_ethics', 'Judicial Ethics', 'Undisclosed gifts, financial conflicts, recusal failures', 31, 500, '#B8860B', 16);
```

### Tagging Format

```sql
INSERT INTO threat_tag_map (threat_id, tag_id) VALUES
-- 228: Thomas Undisclosed Gifts
(228,6),(228,1),
-- 229: Senate Blocks SCOTUS Subpoena
(229,7),(229,6),
-- 230: Admin Defies Court Third Time
(230,1),(230,7);
```

---

## Step 7: Generate Polls

After loading, scoring, and tagging, generate poll rows for all threats scoring 300+:

```bash
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 \
  "cd /home/sandge5/tpb2.sandgems.net && ea-php84 scripts/db/generate-threat-polls.php"
```

This script is **safe to re-run** — it skips threats that already have polls. It creates one poll per 300+ threat with `poll_type='threat'`, `active=1`, and `slug='threat-{threat_id}'`.

Alternatively, use the admin panel at `/poll/admin.php` → "Sync New Threats" button.

---

## Key Official IDs

### Executive Branch

| ID | Name | Role |
|----|------|------|
| 326 | Trump | President |
| 9112 | Vance | Vice President |
| 3000 | Noem | DHS Secretary |
| 9390 | Bondi | Attorney General |
| 9393 | Stephen Miller | Deputy Chief of Staff for Policy |
| 9395 | Musk | DOGE Co-Chair |
| 9397 | Zeldin | EPA Administrator |
| 9398 | Kash Patel | FBI Director |
| 9399 | Russell Vought | OMB Director |
| 9401 | Howard Lutnick | Commerce Secretary |
| 9402 | Hegseth | Defense Secretary |
| 9403 | Linda McMahon | Education Secretary |
| 9405 | RFK Jr. | HHS Secretary |
| 9408 | Rubio | State Secretary |
| 9410 | Scott Bessent | Treasury Secretary |

### Judicial Branch (Priority Targets)

| ID | Name | Court | Priority Focus |
|----|------|-------|---------------|
| 328 | Clarence Thomas | SCOTUS | Harlan Crow gifts, wife's Jan 6 involvement, recusal refusals |
| 329 | Samuel A. Alito jr | SCOTUS | Undisclosed gifts, partisan signaling (flags), recusal refusals |
| 333 | Brett M. Kavanaugh | SCOTUS | Enabling executive overreach, immunity ruling alignment |
| 349 | John Glover Roberts jr | SCOTUS (Chief) | Institutional response to ethics crisis, shadow docket |

*Look up other judicial IDs via:*
```sql
SELECT official_id, full_name, court_name FROM elected_officials
WHERE court_type IS NOT NULL AND full_name LIKE '%lastname%';
```

### Congressional (Look Up as Needed)

Congressional official IDs are in `elected_officials` with `office_name` like 'U.S. Senator' or 'U.S. Representative'. Look up specific members:

```sql
SELECT official_id, full_name, state_code, office_name FROM elected_officials
WHERE office_name LIKE '%Senator%' AND full_name LIKE '%lastname%';
```

---

## Complete Collection Workflow

### Quick Checklist

1. **Query existing threats** for dedup (Step 1)
2. **Research** new threats across all three branches (Step 0)
3. **Deduplicate** against existing threats (Step 2)
4. **Write INSERT SQL** with proper formatting, branch tags, and section headers (Step 3)
5. **Human reviews** the SQL file
6. **Upload and load** threats into DB (Step 4)
7. **Score severity** (0-1000) for each new threat (Step 5)
8. **Assign tags** (1-3 per threat) (Step 6)
9. **Generate polls** for all 300+ threats (Step 7)
10. **Commit** the SQL file to git: `scripts/db/threats-YYYY-MM-DD.sql`
11. **Update collection history** in this document

---

## Current Statistics (as of Feb 26, 2026)

### Totals

| Metric | Count |
|--------|-------|
| **Total threats** | 251 |
| Active | 250 |
| Resolved | 1 |
| Tactical (specific actions) | 107 |
| Strategic (systemic/institutional) | 144 |
| Date range | Dec 24, 2012 – Feb 26, 2026 |

### By Branch

| Branch | Threats | Notes |
|--------|---------|-------|
| **Executive** | 236 | Caught up through Feb 26, 2026 |
| Congressional | 0 | Pending first collection |
| **Judicial** | 15 | First collection: Thomas, Alito, Kavanaugh, Roberts, Cannon (Apr 2024 – Feb 2026) |

### Severity Distribution

| Zone | Score | Count | % |
|------|-------|-------|---|
| Misconduct | 11-30 | 2 | 1% |
| Misdemeanor | 31-70 | 5 | 2% |
| Felony | 71-150 | 29 | 13% |
| Serious Felony | 151-300 | 78 | 34% |
| **High Crime** | **301-500** | **96** | **42%** |
| Atrocity | 501-700 | 12 | 5% |
| Crime Against Humanity | 701-900 | 5 | 2% |

- **161 threats score 300+** (polled) — 64% of all threats
- **90 threats score below 300** (not polled)
- Severity range: 15 – 800
- Average severity: **325** (all) / **416** (polled only)

### By Official (Top 10)

| Official | Threats | Avg Severity | Max Severity |
|----------|---------|-------------|-------------|
| Donald Trump | 93 | 302 | 800 |
| Kristi Noem | 35 | 330 | 750 |
| Stephen Miller | 28 | 416 | 800 |
| Pam Bondi | 21 | 362 | 550 |
| Pete Hegseth | 12 | 250 | 600 |
| Marco Rubio | 12 | 263 | 400 |
| Elon Musk | 4 | 488 | 800 |
| Robert F. Kennedy Jr. | 4 | 363 | 400 |
| Linda McMahon | 3 | 283 | 300 |
| Russell Vought | 3 | 283 | 350 |

**Note:** Miller has the highest average severity (416) among officials with 10+ threats. Musk has the highest average (488) overall but only 4 threats.

### By Tag Category

| Tag | Threats Tagged | % of 227 |
|-----|---------------|----------|
| Immigration Enforcement | 64 | 28% |
| Separation of Powers | 62 | 27% |
| Civil Rights | 55 | 24% |
| Judicial Defiance | 48 | 21% |
| Due Process / Detention | 44 | 19% |
| Foreign Policy | 42 | 19% |
| War Powers / Military | 42 | 19% |
| Corruption / Ethics | 41 | 18% |
| First Amendment | 36 | 16% |
| Federal Workforce / DOGE | 21 | 9% |
| Taxpayer Funds / Waste | 17 | 7% |
| Public Health / Science | 8 | 4% |
| Epstein / Cover-Up | 8 | 4% |
| Press Freedom | 7 | 3% |
| Election Integrity | 3 | 1% |

(Threats can have multiple tags, so percentages exceed 100%.)

### Polls & Engagement

| Metric | Count |
|--------|-------|
| Active threat polls | 160 |
| Citizen votes cast | 4 |
| Rep votes cast | 0 |
| Civic actions (called/emailed/shared) | 0 |
| Community danger ratings | 0 |

**Engagement is near zero** — the accountability infrastructure is built but needs users. The 140 active polls are waiting for citizens and reps to vote.

---

## Collection History

| Date | Branch | Threats Added | Total After | Notes |
|------|--------|--------------|-------------|-------|
| 2025-01-25 | Executive | 107 | 107 | Initial collection (in sandge5_election) |
| 2026-02-24 | Executive | 139 | 139 | Migrated to sandge5_tpb2 via migrate-election-data.php |
| 2026-02-24 | Executive | 88 | 227 | Batch 1 (59): Feb 12-24 + Miller historical. Batch 2 (29): Epstein, deportation prisons, Noem jets, intl threats |
| 2026-02-26 | — | 0 | 227 | Added `branch` column. All 227 existing threats tagged `executive`. Ready for congressional + judicial collections |
| 2026-02-26 | Executive | 9 | 236 | Feb 25-26: State of the Union aftermath. Medicaid freeze, Epstein file suppression, FBI retaliation firings, Gabbard whistleblower block, Hegseth vs Sen Kelly, bank citizenship EO, NJ ICE suit, courthouse DOGE cuts, Tommy Robinson |
| 2026-02-26 | Judicial | 15 | 251 | First judicial collection. Thomas (4): gifts, recusal, special counsel roadmap, tariff dissent. Alito (4): flags, godliness recording, Singer conflict, tariff dissent. Kavanaugh (1): tariff dissent. Roberts/SCOTUS (4): immunity ruling, shadow docket, universal injunctions, toothless ethics code. Cannon (2): dismissed docs case, blocked Smith report |

---

## Files Reference

| File | Purpose |
|------|---------|
| `docs/collect-threats-process.md` | This document |
| `scripts/db/create-executive-tables.sql` | Core schema: executive_threats, threat_responses, threat_ratings |
| `scripts/db/create-threat-tags.sql` | Tags schema + 15 initial tag definitions + severity_score column |
| `scripts/db/generate-threat-polls.php` | Auto-create polls for 300+ threats (safe to re-run) |
| `scripts/db/threats-YYYY-MM-DD.sql` | Threat data files (one per collection run) |
| `scripts/db/score-threats-2026-02-24.sql` | Scoring + tagging for first 227 threats |
| `scripts/db/migrate-election-data.php` | One-time migration from sandge5_election |
| `includes/severity.php` | `getSeverityZone($score)` — shared helper for severity badge rendering |
| `includes/criminality-scale.php` | Embeddable HTML/CSS scale explainer for poll pages |
| `poll/admin.php` | Admin UI for poll management + "Sync New Threats" |
| `poll/index.php` | Citizen/rep voting interface |
| `poll/national.php` | National aggregate vote results |
| `poll/by-state.php` | State-level vote comparison |
| `poll/by-rep.php` | Representative roll call + silence rates |
| `usa/executive.php` | Executive threat detail page with civic actions |
