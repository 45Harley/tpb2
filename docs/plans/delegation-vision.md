# Delegation Vision — Design Document

**Date:** 2026-03-20
**Status:** Designed, foundation built, not yet wired to UI

## The Vision

Every citizen gets a personal page showing **every elected official who represents them** — from President to their town's Library Trustee — with a running track of what each official says, does, and whether they keep their word.

The platform doesn't tell you who to vote for. It gives you the scorecard and lets you decide.

## What Exists Today

### Data Layer (built 2026-03-20)

| Component | Status | Details |
|---|---|---|
| `elected_officials` table | **Live** | 8,600+ records, federal through town-level |
| `branches_departments` table | **Live** | Boards/commissions with seat counts and meeting schedules |
| `governing_organizations` table | **Live** | Town/city/state orgs with charter sources |
| `role_canonicals` table | **Live** | Cross-town title normalization (120+ mappings) |
| `data_status` column | **Live** | ai_draft / human_verified / needs_review / stale |
| `appointment_type` column | **Live** | elected vs appointed distinction |
| `charter_source` column | **Live** | Links to source charter documents |
| Town build kit docs | **Updated** | Elected Officials phase added to TOWN-BUILDER-AI-GUIDE.md |

### Towns Populated (as of 2026-03-20)

| Town | State | Form | Officials | Verified % |
|---|---|---|---|---|
| Putnam | CT | Mayor-Selectmen (Charter) | 190 | 98% |
| Pomfret | CT | Selectmen-Town Meeting (Statutory) | 75 | 99% |
| Killingly | CT | Council-Manager (Charter) | 19 | 53% |
| Brooklyn | CT | Selectmen-Town Meeting (Statutory) | 3 | 0% |
| Dudley | MA | Selectmen-Town Admin (Charter) | 15 | 53% |
| Woonsocket | RI | Strong Mayor-City Council (Charter) | 13 | 62% |

### Activity Layer (already built, separate systems)

| Pipeline | Status | What It Tracks |
|---|---|---|
| `rep_statements` table | **Live** | Official statements with source, date, policy topic |
| `executive_threats` table | **Live** | Threats to democracy linked to officials |
| Truthfulness pipeline | **Running** | Clusters statements, scores 0-1000 truth scale |
| Statements pipeline | **Running** | Daily collection via `claude -p` locally |
| Golden Rule scoring | **Designed** | 3 axes: Impact, Language, Accountability (0-1000 each) |
| Congress vote tracking | **Partial** | Roll call votes, committee memberships |

## The Six Jurisdiction Layers

A citizen's complete delegation spans:

```
Federal          President, 2 Senators, 1 Representative
State            Governor, Lt Gov, AG, SoS, Treasurer, State Senator, State Rep
County           Judge/Executive, Commissioners, Sheriff, Clerk, DA, Constables, JPs
City/Town        Mayor/Selectmen/Council, boards (education, finance, etc.)
School District  School board/committee (may cross city boundaries)
Special District Water, sewer, hospital, community college districts
```

**Two neighbors on the same street** can have different delegations if they fall in different congressional, state legislative, county, or school districts.

## How It Works — The Geo Lookup

### User Side
1. User sets address in profile → geocoded to lat/lng (already stored in `users` table)
2. Lat/lng → spatial query against jurisdiction boundaries
3. Each jurisdiction → pull officials from `elected_officials` table
4. Display: complete delegation page

### Boundary Data Sources
- **US Census TIGER/Line** — congressional, state legislative, county, city, school district boundaries (shapefiles)
- **Google Civic Information API** — address → officials lookup (good for federal/state, spotty on local)
- **Open Civic Data (OCD-ID)** — standardized jurisdiction identifiers (already on `elected_officials.ocd_id`)
- **State GIS portals** — state-specific boundary files

### Implementation Options

**Option A: API-based (simpler, less control)**
- On profile load, call Google Civic API with user's address
- Merge federal/state results with local data from our DB
- Cache results (jurisdiction doesn't change unless user moves)

**Option B: Spatial DB (harder, full control)**
- Import TIGER/Line boundaries into MySQL/PostGIS
- Point-in-polygon query: `ST_Contains(boundary.geom, POINT(user.lng, user.lat))`
- Full offline capability, no API dependency

**Option C: Hybrid (recommended)**
- Use Google Civic API for federal/state (reliable, always current)
- Use our DB for local/town level (where Google has gaps)
- Cache everything per user in a `user_delegation` table
- Refresh on address change or after elections

## The Delegation Page — Per Official

Each official in the delegation shows:

### Basic Profile
- Name, title, party, photo
- Body they serve on (Board of Selectmen, City Council, etc.)
- Term dates (when their seat is up)
- Elected vs appointed
- Contact info

### Activity Feed (from existing pipelines)
- **Statements** — what they've said publicly, tagged by policy topic
- **Votes** — how they voted on legislation affecting the citizen
- **Threats** — executive actions or threats linked to this official
- **Truthfulness** — score tracking over time (promise → action alignment)

### Scorecard (Golden Rule Axes)
- **Impact** (0-1000): criminality ↔ benefit
- **Language** (0-1000): rhetoric ↔ precision
- **Accountability** (0-1000): promises broken ↔ promises kept

Each axis is a Golden Rule derivative:
- Impact → equity, empathy
- Language → honesty, transparency
- Accountability → humility, accountability

## Liquid Democracy Extension

Once citizens can SEE their full delegation and track each official's record, delegation becomes natural:

1. **Trust profiles** — see each other's civic participation (can't delegate blind)
2. **Topic tagging** — votes, ideas, mandates tagged by domain (education, housing, etc.)
3. **Delegation mechanism** — "On education ballots, vote like Sarah"
4. **Transparency** — "Sarah voted X on your behalf" + one-tap revoke
5. **Chain visibility** — delegation chains visible end-to-end

The facilitator role (already built in Groups) is a proto-delegate. Liquid democracy formalizes it.

## Schema — What's Already There

```
elected_officials
  ├── official_id, full_name, title, party
  ├── org_id → governing_organizations (town/city/state)
  ├── branch_id → branches_departments (which board)
  ├── appointment_type (elected/appointed)
  ├── term_start, term_end, seat_name, is_vacant
  ├── ocd_id (Open Civic Data jurisdiction ID)
  ├── data_status (ai_draft/human_verified/needs_review/stale)
  ├── data_note, verified_at, verified_by
  └── state_code, phone, email, website, photo_url

governing_organizations
  ├── org_id, org_name, org_type (Federal/State/Town)
  ├── town_id, state_id
  ├── charter_source, charter_last_revised
  └── website, description

branches_departments
  ├── branch_id, branch_name, branch_type
  ├── org_id, total_seats, vacancies
  ├── meeting_schedule, contact info
  └── data_status, data_note

role_canonicals
  ├── canonical_role (chief_executive, governing_board_member, etc.)
  ├── local_title (Mayor, First Selectman, Council Member, etc.)
  ├── org_id, branch_id (scoped to town + board)
  └── scope (town/state/national)

rep_statements
  ├── official_id → elected_officials
  ├── content, summary, source, source_url
  ├── policy_topic, statement_date
  ├── severity_score, benefit_score
  └── agree_count, disagree_count

executive_threats
  ├── official_id → elected_officials
  ├── title, description, threat_type
  └── severity_score, benefit_score
```

## How Towns Get Populated

### The Build Kit Workflow
1. Volunteer identifies their town
2. AI searches for the elected officials PDF (Town Clerk page, annual reports)
3. Volunteer provides the PDF → AI reads it
4. AI populates `governing_organizations`, `branches_departments`, `elected_officials`
5. Each record gets `data_status` = 'human_verified' (from PDF) or 'ai_draft' (from web search)
6. `role_canonicals` auto-populated for cross-town querying
7. Volunteer reviews gaps → fills in missing parties, appointed boards, staff

### Coverage Result (from testing)
- **With PDF**: 75-190 officials, 95-99% verified (Putnam, Pomfret)
- **Without PDF**: 3-19 officials, 0-53% verified (Brooklyn, Killingly)
- The PDF is the difference between 53% and 99%

### Cross-State Validation
Schema tested across 3 states (CT, MA, RI), 4 government forms (Mayor-Selectmen, Town Meeting, Council-Manager, Strong Mayor-Council), towns and cities. Works for all.

## What's Not Built Yet

| Component | Needed For | Complexity |
|---|---|---|
| Delegation page UI | Showing the citizen their officials | Medium |
| Geo lookup (lat/lng → jurisdictions) | Connecting user to correct officials | Medium-High |
| County-level officials | Complete delegation (TX, South, Midwest) | Medium (data) |
| School district boundaries | Matching user to correct school board | Medium |
| `user_delegation` cache table | Performance | Low |
| Golden Rule scoring engine | Scorecard display | Medium |
| Liquid democracy delegation | Topic-based vote delegation | High |

## The Sequence

1. **Foundation** — schema + town data (DONE)
2. **Delegation page** — show citizen their officials (NEXT)
3. **Activity feed** — connect statements/threats/votes to each official
4. **Scorecard** — Golden Rule scoring displayed per official
5. **Trust profiles** — citizens see each other's civic engagement
6. **Liquid democracy** — topic-based delegation with transparency
