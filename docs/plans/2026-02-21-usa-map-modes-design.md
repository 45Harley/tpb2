# USA Map Modes & National Landing Page — Design Doc

**Date:** 2026-02-21
**Status:** Proposed

---

## Vision

The homepage map is already TPB's centerpiece. Today it does one thing: show states and let you claim yours. This design transforms it into a **multi-mode civic dashboard** — the interface through which the Fourth Branch watches its government.

The map gains six modes organized in two tiers:

- **People Modes** — Who represents you
- **Action Modes** — What they're doing

---

## The Fourth Branch Sits on Top

```
┌─────────────────────────────────────────┐
│      🏛️ THE FOURTH BRANCH (TPB)        │
│      "You — The People"                 │
│                                         │
│   Philosophy · Vision · Amendments      │
│   The Fourth Branch proposes.           │
│   The three branches execute.           │
└──────────────┬──────────────────────────┘
               │ governs
    ┌──────────┼──────────┐
    ▼          ▼          ▼
┌────────┐ ┌────────┐ ┌────────┐
│LEGISL. │ │EXECUT. │ │JUDICIAL│
│        │ │        │ │        │
│Senate  │ │Presid. │ │Supreme │
│House   │ │VP      │ │Appellate│
│535     │ │Cabinet │ │13 circuits│
│members │ │Agencies│ │94 districts│
└────────┘ └────────┘ └────────┘
```

The `/usa/` landing page visualizes this hierarchy. The map modes let you drill into each branch through a geographic lens.

---

## Map Mode UI

Simple toggle bar above the map. Two rows — People and Action:

```
People:  [ State ]  [ National ]  [ Election ]
Action:  [ Bills ]  [ Orders ]    [ Courts ]
─────────────────────────────────────────────
                    MAP
         (colors change per mode)
─────────────────────────────────────────────
                   Legend
```

- Default mode: **State** (current behavior, no change)
- Mode selection saved in `localStorage('tpb_map_mode')`
- URL hash support: `/#national`, `/#election`, etc. for direct linking
- Mode toggle animates map recoloring (CSS transition on SVG fills)

---

## People Modes

### 1. State Mode (exists today)

**Map coloring:** Blue = active states (have users), dark = inactive
**Popup shows:** Population, capital, largest city, governor (party), voter registration bar
**Buttons:** "This is My State" / "View State"
**No changes needed** — this is the current implementation.

### 2. National Mode (new)

**Map coloring:** States shaded by delegation partisan balance
- Deep blue = all-Democrat delegation
- Deep red = all-Republican delegation
- Purple gradient = mixed
- Color derived from: 2 senators + house delegation majority

**Popup shows:**
```
┌─ Connecticut (Federal) ──────────────────┐
│  Electoral Votes: 7                       │
│                                           │
│  US Senators:                             │
│    • Chris Murphy (D) — term ends 2027    │
│    • Richard Blumenthal (D) — term ends 2029│
│                                           │
│  US House: 5 seats                        │
│    • District 1: John Larson (D)          │
│    • District 2: Joe Courtney (D)         │
│    • District 3: Rosa DeLauro (D)         │
│    • District 4: Jim Himes (D)            │
│    • District 5: Jahana Hayes (D)         │
│                                           │
│  Party Balance: ████████████ 7D / 0R      │
│                                           │
│  [View State Page] [Election Info →]      │
└───────────────────────────────────────────┘
```

**Data source:** Congress.gov API
- `GET /v3/member/{congress}/{stateCode}` — current members by state
- Free API key from api.data.gov
- Rate limit: 5,000 requests/hour (more than enough)
- Cache locally: members change rarely (update on election cycle)

**Data storage:**
```sql
CREATE TABLE federal_representatives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bioguide_id VARCHAR(20) UNIQUE,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    party CHAR(1),              -- D, R, I
    chamber ENUM('senate','house'),
    state_code CHAR(2),
    district INT NULL,          -- NULL for senators
    term_start DATE,
    term_end DATE,
    photo_url VARCHAR(255),
    congress_url VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

**Sync strategy:** Cron job or manual trigger to pull from Congress.gov API after each election. Data is very stable between elections.

### 3. Election Mode (new)

**Map coloring:** States colored by race competitiveness/activity
- Red = competitive races (Senate or Governor)
- Orange = House-only races
- Gray = no notable races this cycle

**Popup shows:**
```
┌─ Connecticut — 2026 Races ───────────────┐
│                                           │
│  🗳️ Governor: Ned Lamont (D) term-limited │
│     → Open seat race                      │
│                                           │
│  🗳️ US House: All 5 seats               │
│     CT-1: Larson (D) vs TBD              │
│     CT-2: Courtney (D) vs TBD            │
│     ...                                   │
│                                           │
│  US Senate: Not up (Murphy 2027,          │
│             Blumenthal 2029)              │
│                                           │
│  [Full Election Details →]                │
└───────────────────────────────────────────┘
```

**Data source:** Initially manual/curated in DB. Federal election cycle is predictable:
- All 435 House seats: every 2 years
- ~33 Senate seats: staggered by class (I, II, III)
- 36 governors in 2026

**Data storage:**
```sql
CREATE TABLE election_races (
    id INT AUTO_INCREMENT PRIMARY KEY,
    election_year SMALLINT,
    state_code CHAR(2),
    race_type ENUM('senate','house','governor'),
    district INT NULL,
    incumbent_name VARCHAR(200),
    incumbent_party CHAR(1),
    is_open_seat TINYINT(1) DEFAULT 0,
    competitiveness ENUM('safe','lean','tossup','likely') DEFAULT 'safe',
    notes TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

---

## Action Modes

### 4. Bills / Legislative Mode (new)

**Map coloring:** States shaded by how their delegation voted on a selected bill
- Green = majority voted YES
- Red = majority voted NO
- Gray = mixed/no data

**UI addition:** Bill selector dropdown above map (shows recent notable bills)

**Popup shows:**
```
┌─ Connecticut — HR 1234 (Border Security) ┐
│                                           │
│  State delegation vote: 5 YES / 2 NO     │
│                                           │
│  Senate:                                  │
│    Murphy (D) — YES                       │
│    Blumenthal (D) — YES                   │
│                                           │
│  House:                                   │
│    CT-1 Larson (D) — YES                  │
│    CT-2 Courtney (D) — YES               │
│    CT-3 DeLauro (D) — NO                 │
│    CT-4 Himes (D) — YES                  │
│    CT-5 Hayes (D) — YES                  │
│                                           │
│  [Full Bill Details →]                    │
└───────────────────────────────────────────┘
```

**Data source:** Congress.gov API
- `GET /v3/bill` — list bills (filter by congress, type)
- `GET /v3/house-vote/{congress}/{session}/{rollCallNumber}` — House roll call votes (beta, 2023+)
- **Limitation:** Senate votes NOT available via this API yet
- **Workaround:** senate.gov publishes XML vote data at `senate.gov/legislative/LIS/roll_call_votes/`

**Data storage:**
```sql
CREATE TABLE tracked_bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    congress INT,
    bill_type VARCHAR(10),      -- hr, s, hjres, sjres
    bill_number INT,
    title VARCHAR(500),
    summary TEXT,
    status VARCHAR(100),        -- introduced, passed_house, passed_senate, signed
    last_action_date DATE,
    is_featured TINYINT(1) DEFAULT 0,  -- curated for map display
    congress_url VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE bill_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bill_id INT,
    bioguide_id VARCHAR(20),
    chamber ENUM('senate','house'),
    vote ENUM('yea','nay','not_voting','present'),
    roll_call_number INT,
    vote_date DATE,
    FOREIGN KEY (bill_id) REFERENCES tracked_bills(id),
    FOREIGN KEY (bioguide_id) REFERENCES federal_representatives(bioguide_id)
);
```

**Sync strategy:** Cron job polls Congress.gov for featured bills. Admin curates which bills appear in the map dropdown (via `is_featured` flag). Vote data pulled when a bill gets a roll call vote.

### 5. Executive Orders Mode (new)

**Map coloring:** States highlighted by agency impact of selected order
- Gold = directly affected (named agencies with state presence)
- Light = indirectly affected
- Gray = minimal impact

**UI addition:** Executive order selector (shows recent EOs, searchable)

**Popup shows:**
```
┌─ Executive Order #14137 ─────────────────┐
│  "Protecting American Workers"            │
│  Signed: 2026-01-15                       │
│                                           │
│  Agencies: DOL, DHS, SBA                 │
│  Impact: Labor enforcement changes in     │
│  all 50 states. DOL regional offices      │
│  affected.                                │
│                                           │
│  Connecticut impact:                      │
│    • DOL Hartford office                  │
│    • SBA CT district office               │
│                                           │
│  [Read Full Text →] [Federal Register →]  │
└───────────────────────────────────────────┘
```

**Data source:** Federal Register API (federalregister.gov)
- `GET /api/v1/documents.json?conditions[presidential_document_type]=executive_order`
- **No authentication required** — fully open
- **No rate limit documented** — pagination capped at 2,000 results
- Returns: title, signing date, abstract, agencies, topics, PDF/HTML URLs
- **No state-level impact filter** — TPB would tag state impact manually or infer from agencies

**Data storage:**
```sql
CREATE TABLE executive_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    eo_number INT,
    title VARCHAR(500),
    abstract TEXT,
    president VARCHAR(100),
    signing_date DATE,
    publication_date DATE,
    agencies JSON,              -- ["DOL","DHS","SBA"]
    topics JSON,                -- ["labor","immigration"]
    federal_register_url VARCHAR(255),
    pdf_url VARCHAR(255),
    full_text_url VARCHAR(255),
    is_featured TINYINT(1) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE eo_state_impact (
    id INT AUTO_INCREMENT PRIMARY KEY,
    eo_id INT,
    state_code CHAR(2),
    impact_level ENUM('direct','indirect','minimal') DEFAULT 'indirect',
    impact_notes TEXT,
    FOREIGN KEY (eo_id) REFERENCES executive_orders(id)
);
```

**Sync strategy:** Cron job pulls new EOs from Federal Register API weekly. State impact tagging is manual/admin-curated initially. Future: AI-assisted tagging from EO text.

### 6. Courts / Judicial Mode (new)

**Map coloring:** States colored by federal circuit
- 13 distinct colors for 13 circuits
- Click a state → shows that circuit's recent rulings

**Federal Circuit Map:**
| Circuit | States |
|---------|--------|
| 1st | ME, MA, NH, RI, PR |
| 2nd | CT, NY, VT |
| 3rd | DE, NJ, PA, VI |
| 4th | MD, NC, SC, VA, WV |
| 5th | LA, MS, TX |
| 6th | KY, MI, OH, TN |
| 7th | IL, IN, WI |
| 8th | AR, IA, MN, MO, NE, ND, SD |
| 9th | AK, AZ, CA, HI, ID, MT, NV, OR, WA, GU, MP |
| 10th | CO, KS, NM, OK, UT, WY |
| 11th | AL, FL, GA |
| DC | Washington DC |
| Federal | Nationwide (patents, trade, etc.) |

**Popup shows:**
```
┌─ Connecticut — 2nd Circuit ──────────────┐
│  (CT, NY, VT)                            │
│                                           │
│  Recent Rulings:                          │
│    • Smith v. State of NY (2026-02-10)   │
│      Re: First Amendment, social media    │
│    • EPA v. Hartford (2026-01-28)        │
│      Re: Clean Water Act enforcement      │
│    • US v. Doe (2026-01-15)              │
│      Re: Immigration detention            │
│                                           │
│  Supreme Court Cases from 2nd Circuit:    │
│    • Pending: Jones v. Connecticut        │
│                                           │
│  [View Full Docket →]                     │
└───────────────────────────────────────────┘
```

**Data source:** CourtListener API (Free Law Project)
- `GET /api/rest/v4/clusters/?court__id=ca2` — 2nd Circuit opinions
- `GET /api/rest/v4/clusters/?court__id=scotus` — Supreme Court
- `GET /api/rest/v4/dockets/?court__id=ca2` — dockets
- **Auth required:** Free account, token-based
- **Rate limit:** ~5,000 requests/day
- Court IDs: `ca1` through `ca11`, `cadc`, `cafc`, `scotus`
- District courts: `ctd` (CT District), `nyed` (NY Eastern District), etc.

**Data storage:**
```sql
CREATE TABLE court_opinions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    courtlistener_id INT,
    court_id VARCHAR(20),       -- ca2, scotus, ctd
    case_name VARCHAR(500),
    date_filed DATE,
    date_decided DATE,
    docket_number VARCHAR(100),
    summary TEXT,
    topics JSON,
    opinion_url VARCHAR(255),
    is_featured TINYINT(1) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Maps circuits to states (static reference)
CREATE TABLE circuit_states (
    circuit_id VARCHAR(10),     -- ca1, ca2, ...
    state_code CHAR(2),
    PRIMARY KEY (circuit_id, state_code)
);
```

**Sync strategy:** Cron job pulls recent opinions from CourtListener weekly. Admin curates featured cases. Circuit-state mapping is static (loaded once).

---

## /usa/ Landing Page

The landing page at `/usa/` serves as the national-level equivalent of state pages (`/ct/`) and town pages (`/ct/putnam/`). It completes the pyramid.

### Layout

```
┌─────────────────────────────────────────────┐
│  🏛️ THE FOURTH BRANCH                      │
│  "You — The People"                         │
│                                             │
│  [Philosophy] [Vision] [Proposed Amendments]│
│                                             │
│  TPB proposes. Government executes.         │
│  The people are not spectators.             │
│  The people are a branch of government.     │
└─────────────────────────┬───────────────────┘
                          │
        ┌─────────────────┼─────────────────┐
        ▼                 ▼                 ▼
┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│  LEGISLATIVE │ │  EXECUTIVE   │ │   JUDICIAL   │
│              │ │              │ │              │
│  Senate      │ │  President   │ │  Supreme Ct  │
│  100 members │ │  VP          │ │  9 justices  │
│              │ │              │ │              │
│  House       │ │  Cabinet     │ │  13 Circuits │
│  435 members │ │  15 depts    │ │  Appellate   │
│              │ │              │ │              │
│  [Bills Mode]│ │  [EO Mode]   │ │  [Courts Mode│
│  on Map  →   │ │  on Map  →   │ │  on Map  →]  │
└──────────────┘ └──────────────┘ └──────────────┘
```

### Fourth Branch Section (top)

- **Philosophy**: Why the Fourth Branch exists. Link to existing `/story.php` and philosophical grounding docs.
- **Vision**: Short-term (2026: 50 states active, every citizen can find their reps) and long-term (permanent civic infrastructure).
- **Proposed Amendments**: The constitutional case for formalizing citizen oversight. Links to Amendment 28 (`/28/`) and future proposed amendments empowering the Fourth Branch.

### Three Branches Section

Each branch card shows:
- Name and description
- Key numbers (members, justices, departments)
- Current leadership
- Link to activate that branch's map mode
- Link to deeper content (e.g., how a bill becomes law, how courts work)

### Secondary Nav

The `/usa/` page uses `$secondaryNav` for its sub-sections:
```php
$secondaryNav = [
    ['label' => 'Fourth Branch', 'anchor' => 'fourth-branch', 'active' => true],
    ['label' => 'Legislative', 'anchor' => 'legislative'],
    ['label' => 'Executive', 'anchor' => 'executive'],
    ['label' => 'Judicial', 'anchor' => 'judicial'],
];
```

---

## API Summary

| API | Auth | Cost | Rate Limit | What TPB Gets |
|-----|------|------|------------|---------------|
| **Congress.gov** | API key (free signup) | Free | 5,000/hour | Members by state, bills, House votes |
| **Federal Register** | None | Free | Unlimited* | Executive orders, signing dates, agencies |
| **CourtListener** | Token (free account) | Free | ~5,000/day | Court opinions by circuit, SCOTUS rulings |

*Federal Register pagination capped at 2,000 results per query.

### Limitations

- **Senate votes**: Not available via Congress.gov API. Workaround: scrape senate.gov XML roll call data.
- **State impact of EOs**: No API filter. TPB must tag manually or infer from agencies.
- **State impact of court rulings**: Filter by circuit (maps to states), not by individual state impact.
- **Election data**: No free comprehensive API. TPB curates manually or uses FEC API for campaign finance data.

---

## Implementation Phases

### Phase 1: Foundation (National Mode)
- Create `federal_representatives` table
- Pull current Congress members from Congress.gov API
- Add National mode to map — partisan delegation coloring + rep popup
- Build `/usa/` landing page with three-branch layout
- **Estimated effort:** Medium. API integration + new map mode + new page.

### Phase 2: Election Mode
- Create `election_races` table
- Manually populate 2026 races (predictable: all House + known Senate/Governor)
- Add Election mode to map — race coloring + ballot popup
- **Estimated effort:** Small. Mostly manual data entry + new map mode.

### Phase 3: Bills / Legislative Mode
- Create `tracked_bills` and `bill_votes` tables
- Build admin curation UI (pick featured bills)
- Integrate Congress.gov API for bill data + House votes
- Add Bills mode with bill selector + vote-by-state coloring
- **Estimated effort:** Large. Admin UI + API sync + complex map coloring.

### Phase 4: Executive Orders Mode
- Create `executive_orders` and `eo_state_impact` tables
- Integrate Federal Register API
- Build admin curation for state impact tagging
- Add EO mode with order selector + impact coloring
- **Estimated effort:** Medium. Clean API + manual tagging.

### Phase 5: Courts / Judicial Mode
- Create `court_opinions` and `circuit_states` tables
- Integrate CourtListener API
- Add circuit coloring to map + opinion popup
- **Estimated effort:** Medium. API integration + static circuit mapping.

---

## Database Schema Summary

New tables needed: **9**

| Table | Purpose | Rows (est.) |
|-------|---------|-------------|
| `federal_representatives` | Current Congress members | ~540 |
| `election_races` | 2026 races by state | ~500 |
| `tracked_bills` | Curated notable bills | ~50-100/session |
| `bill_votes` | How each rep voted | ~50K/session |
| `executive_orders` | Presidential EOs | ~100-300/term |
| `eo_state_impact` | EO impact by state | ~500-1000 |
| `court_opinions` | Notable rulings | ~100-500 curated |
| `circuit_states` | Circuit-to-state mapping | 56 (static) |
| `congressional_digest` | Daily digest from Congressional Record | ~250/year |

---

## Daily Digest — "What Happened Today in Congress"

The Congress.gov API provides the **Congressional Record** and **Daily Digest** — a daily summary of everything Congress did. TPB can pull this automatically to give the Fourth Branch a daily briefing.

### Congressional Record Endpoints

| Endpoint | What it contains |
|----------|-----------------|
| `GET /v3/congressional-record` | Full record by issue — Daily Digest, House Section, Senate Section, Extensions of Remarks |
| `GET /v3/daily-congressional-record` | Same data indexed by issue number |
| `GET /v3/summaries` | Plain-English bill summaries (written by CRS) |
| `GET /v3/committee-report` | Committee reports on bills |
| `GET /v3/committee-meeting` | Upcoming/past committee meetings |
| `GET /v3/hearing` | Committee hearing transcripts |
| `GET /v3/house-communication` | Messages from the President, executive reports to House |
| `GET /v3/senate-communication` | Same for Senate |

### Daily Digest Contains

- What the **Senate** did today (bills passed, votes taken, nominations confirmed)
- What the **House** did today (bills passed, votes taken, resolutions)
- **Committee meetings** held (hearings, markups, votes)
- **Bills introduced** that day
- **Floor schedule** for next session

### How TPB Uses It

**On the `/usa/` landing page:**
- "Today in Congress" feed — auto-updated daily
- Summarized in plain English (not legalese)
- Links to full bill details for anything mentioned

**In the Bills map mode:**
- When a vote happens, it appears in the digest first
- User clicks → map colors by how each state's delegation voted

**Data storage:**
```sql
CREATE TABLE congressional_digest (
    id INT AUTO_INCREMENT PRIMARY KEY,
    issue_date DATE UNIQUE,
    congress INT,
    session INT,
    volume INT,
    issue_number INT,
    senate_summary TEXT,
    house_summary TEXT,
    committees_summary TEXT,
    bills_introduced JSON,          -- ["HR 1234","S 567"]
    votes_taken JSON,               -- [{bill, result, roll_call}]
    pdf_url VARCHAR(255),
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Sync strategy:** Cron job runs each morning, pulls previous day's digest from Congressional Record API. AI clerk summarizes into plain English for the feed. Raw PDFs linked for full text.

**5,757 issues** already in the API database — full historical archive back decades.

---

## Fourth Branch Votes — Citizen Opinion on Real Bills

The existing poll system (`/poll/`) becomes the Fourth Branch's voting mechanism on real legislation. When Congress votes on a bill, TPB citizens get to vote too. The gap between the two is the most powerful data on the platform.

### How It Works

1. A featured bill gets a roll call vote in Congress
2. TPB auto-creates a poll linked to that bill
3. Citizens cast their vote via My Opinion (`/poll/`)
4. Bills map mode shows **both votes side by side** per state

### Bill Popup With Citizen Vote

```
┌─ HR 390 — ACERO Act (Drone Wildfire Response) ──────┐
│                                                       │
│  Congress voted:  House 387-42  ✓ Passed             │
│                                                       │
│  Fourth Branch voted:                                 │
│  ████████████████░░░░  82% YES  (1,247 citizens)     │
│                                                       │
│  Your state (CT):                                     │
│    Congress: 5 YES / 0 NO                            │
│    Citizens: 89% YES (43 voters)                     │
│                                                       │
│  [Cast Your Vote →]  [Full Bill Details →]           │
└───────────────────────────────────────────────────────┘
```

### Map Color Layer: Alignment

The Bills map mode gains a third view toggle — not just "how Congress voted" or "how citizens voted" but **alignment**:

- **Green** = reps voted the same way as their constituents
- **Red** = reps diverged from citizen opinion
- **Gray** = not enough citizen votes to compare

This is the accountability layer. *"Your rep voted NO, but 78% of your district said YES."*

### Data Changes

The existing `polls` table gets an optional foreign key to `tracked_bills`:

```sql
ALTER TABLE polls ADD COLUMN bill_id INT NULL;
ALTER TABLE polls ADD FOREIGN KEY (bill_id) REFERENCES tracked_bills(id);
```

When `bill_id` is set:
- Poll question auto-generated from bill title + CRS summary
- Poll results aggregate by state (using voter's `current_state_id`)
- Bills map mode queries both `bill_votes` (Congress) and `poll_responses` (citizens)

### Auto-Creation Flow

1. Cron detects a roll call vote on a featured bill (via Congress.gov API)
2. Creates a poll: *"Should [bill title] become law? [Yes / No / Unsure]"*
3. Poll appears in My Opinion and in the bill's map popup
4. As citizens vote, the alignment map updates in real time

### Why This Matters

Every other civic platform shows you what government did. TPB is the only one that asks: **"What do YOU think?"** — and then puts the answer next to the official vote for everyone to see.

The Fourth Branch doesn't just watch. It votes.

---

## Representative Outreach — The 8th Growth Vector

The seven growth vectors in TPB's expansion plan target citizens. This is the eighth: **elected officials themselves**.

### The Pitch

Every representative has the same problem: *"What do my constituents actually think?"*

Their current tools:
- **Town halls** — 50 people show up, the loudest ones dominate
- **Phone calls** — staffers tally for/against, tiny sample size
- **Lobbyists** — tell them what donors want, not what voters want
- **Polls** — expensive, slow, conducted by third parties with agendas

TPB offers something none of them can: **verified, real-time constituent sentiment on actual bills, broken down by district.**

### Sample Outreach

```
Senator Murphy,

Before you vote on S.1234, would you like to know
what 3,400 verified Connecticut residents think?

  YES: 71%  (2,414 constituents)
  NO:  22%  (748 constituents)
  UNSURE: 7% (238 constituents)

  By district:
    CT-1 (Hartford):   78% YES
    CT-2 (Eastern):    69% YES
    CT-3 (New Haven):  74% YES
    CT-4 (Fairfield):  62% YES
    CT-5 (NW CT):      73% YES

This data is from verified TPB citizens in your state.
View full breakdown: https://4tpb.org/poll/s1234

The People's Branch
```

### Why Representatives Care

| What they get now | What TPB offers |
|-------------------|-----------------|
| 50 people at a town hall | Thousands of verified voters |
| Lobbyist spin | Raw constituent data, no agenda |
| Expensive polls (weeks) | Real-time, always on |
| National polls (not their state) | Broken down by their districts |
| Anonymous online petitions | Identity-verified citizens |

TPB's identity levels matter here — a rep trusts data from verified (level 3-4) constituents more than anonymous clicks. The verification system that seemed like overhead becomes the **credibility layer**.

### The Adoption Cascade

1. One senator's office uses TPB data for a vote → it's legitimized
2. That office tells other offices → "Have you seen this?"
3. Reps link to TPB polls from their own sites → citizens flood in to vote
4. Now every rep wants their state active on TPB
5. Reps' constituents join → those citizens explore the rest of the platform
6. TPB grows from the top down AND bottom up simultaneously

### Representative Dashboard (Future)

A private dashboard for verified elected officials:

```
┌─ Sen. Murphy — Constituent Dashboard ─────────┐
│                                                 │
│  Active Bills With Constituent Opinion:         │
│                                                 │
│  S.1234 Border Security    71% YES  (3,400)    │
│  HR.567 Clean Energy       84% YES  (2,100)    │
│  S.890 Healthcare          52% YES  (4,700)    │
│                                                 │
│  Trending Topics (from /talk):                  │
│    • Infrastructure spending (89 threads)       │
│    • School safety (64 threads)                 │
│    • Housing costs (51 threads)                 │
│                                                 │
│  CT Civic Engagement:                           │
│    • 12,400 verified citizens                   │
│    • 3,200 active this month                    │
│    • Top concern: economy (34%)                 │
└─────────────────────────────────────────────────┘
```

This dashboard pulls from:
- **Polls** → bill-linked citizen votes
- **/talk** → trending deliberation topics by state
- **User profiles** → engagement metrics by state/district

### Non-Partisan Positioning

This works because TPB doesn't advocate. It reports.

- TPB never tells a rep HOW to vote
- TPB shows what constituents think — that's it
- Both parties benefit equally
- The data is the same regardless of who looks at it
- No editorializing, no scoring, no "report cards"

The moment TPB starts grading representatives, it becomes advocacy and loses trust from half the aisle. **Stay neutral. Serve data. Let democracy work.**

### Implementation

- **Phase 1**: Email outreach to CT delegation with sample poll data (manual)
- **Phase 2**: API endpoint for rep offices to pull constituent data (`/api/rep-dashboard.php`)
- **Phase 3**: Verified "Elected Official" role with private dashboard
- **Phase 4**: Automated pre-vote briefings sent to rep offices

New role needed:
```sql
INSERT INTO user_roles (role_name, description)
VALUES ('Elected Official', 'Verified elected representative with access to constituent sentiment dashboard.');
```

---

## Non-Partisan Commitment

Per TPB's CLAUDE.md: "Non-partisan — Serve ALL citizens (describe, don't editorialize)."

All map modes must:
- Show data without commentary
- Use neutral colors (avoid red=bad, green=good for votes — use party colors consistently)
- Present both sides of any vote equally
- Never label a ruling or order as "good" or "bad"
- Let citizens form their own opinions from facts

The Fourth Branch watches. It does not judge. It empowers.
