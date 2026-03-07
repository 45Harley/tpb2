# The Civic Engine

**Purpose**: A unified architecture for deliberation, declaration, and amplification — the three forces that convert individual civic voice into collective democratic power.

**Scope**: Everything from a single citizen's AI-refined mandate to a national roll-up of jurisdictional declarations.

**Design Date**: 2026-03-06

---

## Principles

1. **The Fractal** — The same pattern repeats at every level: Deliberate → Declare → Amplify. Pair → Group → Town → State → Federal.
2. **Additive Migration** — New columns with defaults. No breaking changes. Existing polls, talk posts, and mandates continue working.
3. **Scope Normalization** — One `scope_type` + `scope_id` pattern replaces scattered scope expressions across the codebase.
4. **Feedback Loops** — Multi-round voting with options tables, merge/revise between rounds, until majority threshold is met.

---

## Components

### 1. The Pair (Citizen + AI)
**Exists**: `mandate-poc.php` + `includes/mandate-chat.php`

The atomic unit. A citizen works with an AI clerk to refine raw civic voice into a clear, actionable mandate. Voice in, structured mandate out.

- Input: free-text or voice dictation
- Process: AI conversation refining intent into mandate language
- Output: saved mandate with scope (federal/state/town) and topic classification

### 2. The Group (Pairs gathered around a civic topic)
**Exists partially**: `talk/groups.php` (50 standard civic topic groups), `talk/index.php` (stream), `talk/brainstorm.php` (AI chat)

The deliberation layer. Citizens with mandates in the same topic area meet, debate, refine, and converge. A facilitator guides the process.

- Input: individual mandates + discussion posts
- Process: structured debate, option surfacing, multi-round voting
- Output: group declaration (majority-ratified mandate)

**Missing**:
- Options table (surfacing competing proposals from posts)
- Call-to-vote mechanism (facilitator triggers ballot)
- Multi-round voting with merge/revise between rounds
- Declaration output (ratified group mandate)
- Group AI clerk (future — AI not ready for group facilitation yet)

### 3. The Ballot (Universal scoped voting instrument)
**Exists partially**: `polls` table, `poll_votes`, `poll/admin.php` (threat-sync auto-creation)

Redesigned polls become the universal voting mechanism used at every level.

**Current schema**:
```
polls: poll_id, slug, question, active, closed_at, created_by, created_at, updated_at, threat_id, poll_type enum('general','threat')
```

**Missing fields** (additive):
```sql
ALTER TABLE polls ADD COLUMN scope_type ENUM('federal','state','town','group') DEFAULT 'federal';
ALTER TABLE polls ADD COLUMN scope_id VARCHAR(50) DEFAULT NULL;
ALTER TABLE polls ADD COLUMN vote_type ENUM('yes_no','yes_no_novote','multi_choice','ranked_choice') DEFAULT 'yes_no';
ALTER TABLE polls ADD COLUMN threshold_type ENUM('plurality','majority','three_fifths','two_thirds','three_quarters','unanimous') DEFAULT 'majority';
ALTER TABLE polls ADD COLUMN quorum_type ENUM('percent','minimum','none') DEFAULT 'none';
ALTER TABLE polls ADD COLUMN quorum_value INT DEFAULT NULL;
ALTER TABLE polls ADD COLUMN round INT DEFAULT 1;
ALTER TABLE polls ADD COLUMN parent_poll_id INT DEFAULT NULL;
ALTER TABLE polls ADD COLUMN declaration_id INT DEFAULT NULL;
ALTER TABLE polls ADD COLUMN source_type ENUM('manual','threat','bill','executive_order','group') DEFAULT 'manual';
ALTER TABLE polls ADD COLUMN source_id INT DEFAULT NULL;
```

**New table — poll options** (for multi-choice/ranked-choice):
```sql
CREATE TABLE poll_options (
    option_id INT AUTO_INCREMENT PRIMARY KEY,
    poll_id INT NOT NULL,
    option_text TEXT NOT NULL,
    option_order INT DEFAULT 0,
    merged_from_option_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (poll_id) REFERENCES polls(poll_id)
);
```

**Vote types**:
| Type | Use Case |
|------|----------|
| yes_no | Simple approval/rejection |
| yes_no_novote | Approval with explicit abstain |
| multi_choice | Select from competing proposals |
| ranked_choice | Rank preferences among options |

**Threshold types**:
| Type | Value | Use Case |
|------|-------|----------|
| plurality | Most votes wins | Initial rounds, preference signals |
| majority | >50% | Standard declarations |
| three_fifths | >60% | Elevated decisions |
| two_thirds | >66.7% | Constitutional-weight items |
| three_quarters | >75% | Near-consensus requirements |
| unanimous | 100% | Ceremonial/symbolic |

**Quorum types**:
| Type | Meaning |
|------|---------|
| percent | X% of eligible members must vote |
| minimum | At least N votes required |
| none | Any participation counts |

### 4. The Declaration (Ratified group mandate)
**New**.

```sql
CREATE TABLE declarations (
    declaration_id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    scope_type ENUM('federal','state','town') NOT NULL,
    scope_id VARCHAR(50) DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    final_poll_id INT DEFAULT NULL,
    vote_count INT DEFAULT 0,
    yes_count INT DEFAULT 0,
    threshold_met ENUM('plurality','majority','three_fifths','two_thirds','three_quarters','unanimous') DEFAULT NULL,
    status ENUM('draft','voting','ratified','superseded') DEFAULT 'draft',
    ratified_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES idea_groups(group_id),
    FOREIGN KEY (final_poll_id) REFERENCES polls(poll_id)
);
```

A declaration is the output of successful group deliberation — a majority-ratified statement that carries the weight of the group's collective voice.

### 5. The Opinion (Public sentiment layer)
**New**.

The lightweight public counterpart to The Ballot. Any citizen (remembered+) can weigh in without joining a group. Opinions don't produce declarations — they produce sentiment data that feeds into The Pulse.

**What people opine on**:
| Target | Example |
|--------|---------|
| Group declarations | "Putnam Housing declared X — do you agree?" |
| Active civic issues | General topics not tied to threats |
| Bills & executive orders | "Do you support [bill/EO]?" |
| Other citizens' mandates | "Maria's mandate on childcare — agree/disagree?" |

**Ballot vs Opinion**:
| | Group Ballot | Public Opinion |
|---|---|---|
| **Who** | Group members (verified, level 3+) | Any citizen (remembered, level 2+) |
| **Weight** | Binding — produces declarations | Signal — shows sentiment |
| **Threshold** | Majority/supermajority required | None — just aggregate |
| **Scope** | Within group deliberation | Open on any topic/declaration |
| **Output** | Declaration | Sentiment data for the Pulse |

**Amplification feedback loop**: Declarations go public, public opinion validates or challenges them, groups see whether their declaration has broad support or is out of step. This is the bridge between the deliberation chamber and the town square.

```sql
CREATE TABLE public_opinions (
    opinion_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    target_type ENUM('declaration','mandate','issue','bill','executive_order') NOT NULL,
    target_id INT NOT NULL,
    stance ENUM('agree','disagree','mixed') NOT NULL,
    comment TEXT DEFAULT NULL,
    scope_type ENUM('federal','state','town') DEFAULT NULL,
    scope_id VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_opinion (user_id, target_type, target_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);
```

**Identity requirement**: remembered (level 2) — lower bar than voting in a ballot (verified, level 3). This maximizes public participation while still requiring email verification.

### 6. The Pulse (Amplification layer)
**Exists partially**: `mandate-summary.php`, `api/mandate-summary.php`

Currently shows individual mandates aggregated by topic. Extends to include group declarations and public opinion sentiment alongside individual mandates.

**Missing**:
- Group declarations feed
- Public opinion sentiment (agree/disagree aggregates per declaration)
- Town-level roll-up (all groups in a town)
- State-level roll-up (all towns in a state)
- Federal-level roll-up (all states)
- Representative targeting (beam to desk)

### 7. The Feed (External event triggers)
**Exists as prototype**: `poll/admin.php` threat-sync (finds threats with severity >= 300, auto-creates polls)

External events flow in and trigger auto-generated ballots for citizen response.

**Feed sources**:
| Source | Trigger | Auto-ballot |
|--------|---------|-------------|
| Threats | severity >= threshold | "Should [representative] be held accountable for [threat]?" |
| Bills | bill introduced/voted | "Do you support [bill title]?" |
| Executive Orders | EO signed | "Do you support [EO title]?" |
| Group declarations | declaration ratified | Escalation ballot to parent jurisdiction |

**New `source_type` + `source_id`** on polls table handles this uniformly.

---

## Scope Model

Normalize all scattered scope expressions into one pattern:

| scope_type | scope_id | Example |
|------------|----------|---------|
| federal | NULL | National-level |
| state | CT | Connecticut |
| town | CT-putnam | Putnam, CT |
| group | 42 | Civic topic group #42 |

**Current scope expressions being replaced**:
- Talk: `idea_groups.scope` enum + `state_abbr` + `town_slug`
- Polls: no scope (implicit federal)
- Mandates: `user_mandates.scope` enum + `scope_detail`
- Threats: `scope` + `scope_detail` in threats table
- Elections: `level` enum in election tables

**Migration approach**: Add `scope_type` + `scope_id` columns alongside existing columns. Backfill from existing data. Existing code continues working via old columns. New code uses normalized columns. Eventually deprecate old columns.

---

## The Fractal

The same Deliberate → Declare → Amplify pattern at every jurisdictional level:

```
Citizen + AI (Pair)
    → refine raw voice into mandate
        → Group deliberation
            → multi-round voting on options
                → Declaration (majority ratified)
                    → Public Opinion (broader citizen sentiment)
                        ↩ feedback to group (validate or challenge)
                    → Town roll-up (all groups declare)
                        → State roll-up (all towns declare)
                            → Federal roll-up (all states declare)
                                → The Pulse (coherent beam to representative)
```

At each level, the same process repeats:
1. **Input**: Declarations from the level below
2. **Deliberation**: Discussion, option surfacing, debate
3. **Voting**: Multi-round with feedback loops until threshold met
4. **Output**: Declaration that feeds up to the next level

---

## Group Relations

### Vertical (same topic, different jurisdiction)
- Town Housing → State Housing → Federal Housing
- Declarations escalate upward
- Higher-level groups see lower-level declarations as input

### Horizontal (different topics, same jurisdiction)
- Town Housing + Town Budget + Town Zoning (all Putnam)
- Cross-topic awareness: housing declaration may affect budget
- Town dashboard shows all group declarations together

### Diagonal (cross-topic, cross-level)
- Town Housing ↔ State Education (housing affects school districts)
- Least structured, most emergent
- Future: AI-suggested cross-references

---

## Roles

| Role | Capabilities |
|------|-------------|
| Citizen | Vote, post, mandate, delegate |
| Facilitator | Surface options from posts, call vote, manage rounds, merge options, draft declaration |
| Group Admin | Assign facilitator, set group rules, archive declarations |
| Platform Admin | Create/archive groups, override, audit |

**Identity requirements** (from `identity_levels` table):
| Action | Minimum Level |
|--------|--------------|
| Post in group | remembered (2) |
| Public opinion | remembered (2) |
| Vote in ballot | verified (3) |
| Facilitate | verified (3) |
| Declaration weight | verified (3) |

---

## Facilitator Tools

The facilitator UI enables structured deliberation:

**Inputs**:
- Group discussion posts (existing talk stream)
- Individual mandates from group members
- External feed events (threats, bills)

**Tools**:
- **Surface option**: Select a post/mandate, promote to ballot option
- **Call vote**: Create ballot from surfaced options, set vote type + threshold
- **Manage round**: View results, identify near-consensus, suggest merges
- **Merge options**: Combine similar options between rounds
- **Revise option**: Edit option text based on discussion feedback
- **Draft declaration**: When threshold met, compose declaration from winning option
- **Ratify**: Final confirmation vote on declaration text

**Outputs**:
- Ballot (sent to group members)
- Round results (visible to group)
- Declaration (published to Pulse, escalated to parent jurisdiction)

---

## Data Changes Summary

### New Tables
1. `poll_options` — competing options for multi-choice/ranked-choice ballots
2. `declarations` — ratified group mandates
3. `public_opinions` — citizen sentiment on declarations, mandates, issues, bills, EOs

### Modified Tables
1. `polls` — add scope, vote type, threshold, quorum, round, parent, source columns
2. `poll_votes` — add `option_id` for multi-choice, `rank` for ranked-choice

### Backfill
- Existing polls get `scope_type='federal'`, `vote_type='yes_no'`, `threshold_type='majority'`, `source_type='manual'` (or `'threat'` where `threat_id IS NOT NULL`)
- All defaults preserve current behavior — zero breaking changes

---

## Build Sequence

### Phase 1: Scope + Ballot Foundation
1. Add scope columns to polls (with defaults)
2. Create `poll_options` table
3. Add `vote_type`, `threshold_type`, `quorum` columns to polls
4. Backfill existing polls
5. Update poll UI to support multi-choice

### Phase 2: Group Deliberation
6. Add facilitator tools to group page (surface option, call vote)
7. Embed ballot in group view
8. Multi-round voting with merge/revise
9. Create `declarations` table
10. Declaration output from successful vote

### Phase 3: Public Opinion + Pulse Extension
11. Create `public_opinions` table
12. Public opinion UI on declarations and mandates (agree/disagree/mixed + optional comment)
13. Group declarations feed in Pulse with opinion sentiment
14. Town dashboard (cross-group declaration portfolio)
15. State/federal roll-up views

### Phase 4: The Feed
16. Generalize threat-sync to universal feed
17. Bill feed → auto-ballot
18. Executive order feed → auto-ballot
19. Declaration escalation → parent jurisdiction ballot

### Phase 5: The Fractal
20. Town-level group-of-groups deliberation
21. State-level town roll-up
22. Federal-level state roll-up
23. Representative targeting (beam to desk)

---

## Page Architecture

How the Civic Engine surfaces through navigation and page structure.

### Navigation Structure

**Row 1**: Brand + status (civic points, login state) — unchanged.

**Row 2** (main nav, logged in): Help | My TPB | Groups | Ballots | My Mandate | USA | Polls

**Row 2** (main nav, logged out): Help | Login | Groups | Ballots | USA | Polls

**Row 3** (contextual sub-nav): Defined per page below. When row 3 is active, row 2 collapses.

### My TPB Dropdown

- My Profile → /profile.php
- My Town → /z-states/{state}/{town}/
- My State → /{state}/
- My Reps → /reps.php?my=1
- My Points → /profile.php#points
- *(separator)*
- My Groups → /talk/groups.php?my=1
- My Mandate → /mandate-poc.php
- Volunteer → /volunteer/
- Invite → invite flow

### USA Dropdown

**Explore**: Map, Congressional, Executive, Judicial, Documents, Glossary
**Action**: Elections, My Reps (logged-in only)

Backing pages in `usa/`:
- `usa/index.php` — map landing
- `usa/congressional-overview.php` — Congressional overview
- `usa/executive-overview.php` + `usa/executive.php` — Executive branch
- `usa/judicial.php` + `usa/judge.php` — Judicial branch + individual judge view
- `usa/rep.php` — individual representative view
- `usa/glossary.php` — civic glossary
- `usa/digest.php` — digest/summary view
- `usa/docs/` — founding documents (Declaration, Constitution, Federalist, Gettysburg, Birmingham Letter, Oath of Office)

### Elections (inside USA dropdown)

`elections/` directory pages:
- `elections/index.php` — elections landing
- `elections/the-fight.php` — The Fight (pledges/knockouts)
- `elections/the-amendment.php` — The War (Amendment 28, links to `28/index.php`)
- `elections/threats.php` — threat stream
- `elections/races.php` — race tracking
- `elections/impeachment-vote.php` — impeachment voting

`28/` directory (Amendment 28):
- `28/index.php` — amendment page
- `28/verify.php` — signature verification

Elections is accessed via USA dropdown → Action → Elections. The `28/` section is reached from within elections (The War).

### Login Dropdown (logged out)

- Login via Magic Link → `api/send-magic-link.php`
- Create Account → `join.php`

Auth flow pages (not in nav, but part of the system):
- `login.php` — login page
- `logout.php` — logout
- `join.php` — registration
- `welcome.php` — post-registration getting started (also linked from Help)
- `api/verify-magic-link.php` — magic link verification
- `api/verify-phone-link.php` — phone verification

### Sub-Nav Specs

Every page and its row 3 links:

| Page | Row 3 Brand | Row 3 Links |
|------|-------------|-------------|
| My Mandate | My Mandate | Voice · The Pulse · Town Dashboard |
| Groups (USA) | USA Groups | Stream · Topics · My Groups · Help |
| Groups (state) | [State] Groups | Stream · Topics · My Groups · Help |
| Groups (town) | [Town] Groups | Stream · Topics · My Groups · Help |
| Ballots | Ballots | Vote · National · By State · By Rep · Closed |
| Town page | [Town], [ST] | Overview · Dashboard · Government · Schools · Budget · Calendar · Groups · Build Kit |
| State page | [State] | Overview · Dashboard · Your Reps · Government · Benefits · Economy · Elections · Towns · Groups · Build Kit |
| Elections | Elections | Landing · The Fight · The War · Threats · Races |
| Talk | Talk | Stream · Groups · Help |
| Polls | Polls | Vote · National · By State · By Rep |
| Volunteer | Volunteer | Dashboard · Tasks · Apply |
| Help | Help | Getting Started · Guides · Icons |
| Profile | *(none)* | *(no row 3)* |
| USA/Map | *(none)* | *(no row 3)* |

Pages without row 3 keep row 2 visible.

### Town Page Tabs

Existing town pages (model: Putnam, CT at `z-states/ct/putnam/index.php`) have 9 content sections. The revised sub-nav reorganizes them:

| Revised Tab | Content |
|-------------|---------|
| Overview | Quick facts + History + Living Here (merged from 3 former sections) |
| Dashboard | **New** — Civic Engine view: active groups grid, pulse meter, declarations, escalation tracking |
| Government | Boards, commissions, departments, vacancies (existing) |
| Schools | District, schools, budget (existing) |
| Budget | Town budget, tax rate, capital projects (existing) |
| Calendar | Community calendar (existing) |
| Groups | Links to `/talk/groups.php?town=[id]` — town-scoped civic groups |
| Build Kit | Links to volunteer town-builder onboarding — visible to logged-in users with volunteer status or identity level 3+ |

### State Page Tabs

Existing state pages (model: CT at `z-states/ct/index.php`) have 11 content sections. The revised sub-nav:

| Revised Tab | Content |
|-------------|---------|
| Overview | State facts, identity (existing) |
| Dashboard | **New** — Civic Engine view: state-level group activity, declaration roll-ups, pulse aggregation |
| Your Reps | Federal delegation + state legislator lookup (existing) |
| Government | Governor, officials, legislature (existing) |
| Benefits | Housing, energy, seniors, EV, education programs (existing) |
| Economy | Jobs, industries, GDP, unemployment (existing) |
| Elections | Voter registration, results, voting info (existing) |
| Towns | Interactive grid of all towns (existing) |
| Groups | Links to `/talk/groups.php?state=[id]` — state-scoped civic groups |
| Build Kit | Links to `volunteer/state-builder-start.php?state=[abbr]` — visible to volunteers/identity level 3+ |

### Dashboard Tab (Civic Engine Overlay)

The Dashboard tab on town and state pages is the primary surface for the Civic Engine. It shows:

**Town Dashboard** (see mockup: `mockups/town-dashboard.html`):
- Active civic groups grid with status badges (declared/voting/idle)
- Pulse strength meter (aggregate civic activity)
- Active declarations with public opinion buttons (agree/disagree/mixed)
- Escalation tracking ("3 other CT towns also declared on this topic")
- Convergence signals across towns

**State Dashboard**:
- State-level group activity summary
- Declaration roll-ups from towns
- State pulse aggregation
- Representative targeting data ("Beam to Desk")

Dashboard is a section within the existing page (anchor `#dashboard`), not a separate file. Existing content sections remain intact.

### Build Kit Tab

Links to existing volunteer onboarding infrastructure:

**State Build Kit** (`docs/state-builder/`):
- README, Volunteer Orientation, Ethics Foundation, AI Guide, Checklist, Data Template
- Onboarding page: `volunteer/state-builder-start.php`
- 7-phase AI-guided workflow, 11 sections per state, 4-6 hours per build
- Registered in `system_documentation` with roles: volunteer, clerk:state-builder

**Town Build Kit** (`docs/town-builder/`):
- AI Guide, Town Template, Data Template
- Putnam CT as model, department mapping to 13 civic group templates
- Registered in `system_documentation` with roles: volunteer, clerk:town-builder

Build Kit tab is only visible to logged-in users who are volunteers or have identity level 3+.

### Groups as Top-Level Nav

`talk/groups.php` is promoted from Talk sub-nav to top-level main nav. It already supports scope filtering:
- `/talk/groups.php` — all USA groups
- `/talk/groups.php?state=[id]` — state-scoped groups
- `/talk/groups.php?town=[id]` — town-scoped groups
- `/talk/groups.php?my=1` — user's groups only (new parameter)

Talk stream remains accessible from Groups sub-nav ("Stream") and from town/state pages.

### My Mandate as Top-Level Nav

`mandate-poc.php` becomes the top-level "My Mandate" nav item. Sub-nav provides three views:
- **Voice** — the AI chat interface (mandate-poc.php default view)
- **The Pulse** — mandate-summary.php (aggregation dashboard, "The People's Pulse")
- **Town Dashboard** — town dashboard view filtered to user's town

### Footer

4 columns replacing "Our Story" and other items moved from main nav:

| The People's Branch | Learn | Build | Connect |
|-------|------|-------|---------|
| Our Story (`story.php`) | Getting Started (`welcome.php`) | Volunteer (`volunteer/`) | Invite a Citizen (`invite/`) |
| The Golden Rule (`goldenrule.html`) | User Guides (`help/guide.php`) | State Build Kit (`volunteer/state-builder-start.php`) | Invite a Rep |
| Constitution (`constitution/`) | The Metaphysics | Town Build Kit (needs page) | GitHub |
| Contact | | | |

### Town Fallback Page

`town.php` is the dynamic fallback for towns that don't have a built page yet. When a user visits `/ct/brooklyn/` and no `z-states/ct/brooklyn/index.php` exists, `.htaccess` routes to `town.php` which shows a vision/placeholder page. Once a volunteer builds the real page via the Town Build Kit, the static file takes priority.

Early town builds (Brooklyn, Pomfret, Woodstock CT) are static `.html` files from Phase 1 of the town-builder workflow. They'll eventually be upgraded to `.php` with database integration.

### Standalone Subsystems (not in main nav)

These systems exist independently and don't need nav entries:

- **0t/ (People Power)** — `0t/index.php`, `0t/stand.php`, `0t/record.php`. Separate app for civic stands. Revisit when feature matures.
- **tpb-claude/** — `tpb-claude/api/claude-chat.php`. AI subsystem with separate auth. Internal only.
- **Modal system** — `api/modal/` (admin, get-modal, get-page-modals, log-click) + `config/modal_config.php`. Admin-managed modals that overlay on pages. No nav entry needed.
- **Aspirations** — `aspirations.php`. Standalone page, no current nav home. Candidate for My TPB dropdown or town pages.
- **C Widget (Claudia)** — `includes/c-widget.php`, tested via `index2.php`. Civic guide overlay, not a nav destination.

### What Moved Where

| Old Location | New Location | Reason |
|-------------|-------------|--------|
| Our Story (row 2) | Footer | Low-frequency, not a daily action |
| Volunteer (row 2) | My TPB dropdown + Footer | Contextual access, not top-level |
| Elections (row 2) | USA dropdown → Action | Grouped with civic action tools |
| Talk (row 2) | Groups sub-nav | Groups is the primary surface; Talk stream is secondary |
| Getting Started (My TPB) | Help / Footer | Onboarding, not daily use |
| My Opinion (My TPB) | Polls (main nav) | Already exists as Polls |
| My Power (My TPB) | Dropped | 0t/ (People Power) — revisit when feature matures |
| Amendment 28 (28/) | Elections → The War | Already linked from elections/the-amendment.php |
| Constitution | Footer | Footer column 1 |
| Golden Rule | Footer | Footer column 1 |
| Welcome / Getting Started | Footer + Help | Footer "Learn" column |
| Invite | My TPB dropdown | Was standalone, now in dropdown |

### Fully Accounted File Inventory

Every PHP/HTML file in the project mapped to its navigation path:

**Top-level pages with nav paths:**
- `index.php` — home (brand logo)
- `profile.php` — My TPB → My Profile
- `reps.php` — My TPB → My Reps / USA → My Reps
- `mandate-poc.php` — My Mandate (top-level)
- `mandate-summary.php` — My Mandate → The Pulse
- `map.php` — USA → Map
- `story.php` — Footer → Our Story
- `goldenrule.html` — Footer → The Golden Rule
- `welcome.php` — Footer → Getting Started / Help
- `join.php` — Login → Create Account
- `login.php` — Login dropdown
- `logout.php` — My TPB dropdown action
- `town.php` — dynamic fallback (not nav, routed by .htaccess)
- `thought.php` — accessed from talk/group pages (not direct nav)
- `read.php` — reading view (linked from content, not nav)
- `voice.php` — orphaned (no current use)

**Directory sections with nav paths:**
- `usa/` — USA dropdown (Map, Congressional, Executive, Judicial, Documents, Glossary)
- `elections/` — USA → Elections
- `28/` — Elections → The War → Amendment 28
- `poll/` — Polls (top-level)
- `talk/` — Groups sub-nav → Stream / Talk
- `volunteer/` — My TPB → Volunteer
- `help/` — Help dropdown
- `invite/` — My TPB → Invite
- `constitution/` — Footer → Constitution
- `z-states/` — My TPB → My Town / My State
- `admin.php` + `admin-user-detail.php` — admin only (not public nav)

**Internal/infrastructure (no nav needed):**
- `api/` — API endpoints (called by JS, not navigated)
- `includes/` — shared components
- `config/` — configuration
- `tpb-claude/` — AI subsystem
- `scripts/`, `sql/`, `tests/` — development tools

**Truly orphaned (no nav path, candidates for cleanup):**
- `voice.php` — unknown purpose, no references found
- `aspirations.php` — standalone, needs a home or deletion
- `thought-no-ai.php` — alternate thought form, may be obsolete
- `c-guide.php` — old civic guide page, replaced by C Widget
- `probe_openstates.php` — dev tool
- `0t/` — People Power, intentionally parked

**Old/backup (candidates for deletion):**
- `index-old.php`, `index-old2.php`, `index2-old.php`
- `story-old.php`, `town-old.php`
- `includes/nav-old.php`
- `db-integrity-check_1.php`
- `0t/index-old.php`
- `z-states/ct/putnam/index-old.html`, `index-old2.php`
- Various test files: `test-claude.php`, `test-location.php`, `test-level-manager.php`, `test-mail.php`, `test_openstates.php`, `map-test.php`
- HTML one-offs: `MODAL_ICON_DEMO.html`, `poc-demo.html`, `map-color-picker.html`, `putnam-links-poc.html`, `putnam-vision.html`, `import_runner.html`, `git-help.html`, `clear-session.html`

---

## Mockups

Standalone HTML files for presentation (open via `file:///` protocol):

| # | File | Phase | Shows |
|---|------|-------|-------|
| 1 | `mockups/ballot-multi-option.html` | Phase 1 | Yes/no, multi-choice, ranked-choice ballots |
| 2 | `mockups/group-deliberation.html` | Phase 2 | Full group page with discussion, ballots, opinions |
| 3 | `mockups/town-dashboard.html` | Phase 3 | Town civic dashboard, pulse meter, escalation |
| 4 | `mockups/pulse-extended.html` | Phase 3 | State roll-ups, rep targeting ("Beam to Desk") |
| 5 | `mockups/feed-management.html` | Phase 4 | Admin feed pipeline, auto-generated ballots |
| 6 | `mockups/nav-revised.html` | Nav | 6 nav scenarios + footer redesign |
