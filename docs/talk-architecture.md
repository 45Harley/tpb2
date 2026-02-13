# /talk Architecture

**Living document — last updated 2026-02-13**

This document captures the full system architecture for `/talk`, TPB's collective deliberation tool. It covers what's built, what's designed, and the conceptual model that drives both.

---

## 1. What /talk Is

/talk is not a personal note-taking app. It is a **collective deliberation system** — a controlled feedback/amplification circuit where groups of people (optionally assisted by AI) transform scattered raw thoughts into concrete, actionable proposals.

### The Pipeline

| Stage | What happens | Control mechanism |
|-------|-------------|-------------------|
| **Scatter** | Many people dump raw thoughts | Quick Capture, Brainstorm chat |
| **Gather** | Related thoughts cluster by theme | AI linking, summarizing, `idea_links` |
| **Refine** | Groups discuss, react, build on clusters | Threaded replies, AI moderation |
| **Crystallize** | Refined ideas become concrete proposals | Consensus, status promotion |

### The Circuit Metaphor

A group is a feedback/amplification circuit:

```
User A thought → shared → AI links to User B's thought →
summary surfaces the connection → User C reacts →
AI-moderator keeps it focused → new thoughts generated →
loop continues → ideas amplify or fade
```

**Controls on the circuit:**
- **Shareable gate** — thoughts stay private until the user opens the valve
- **Group membership** — defines who's in the circuit
- **Status pipeline** — raw → refining → distilled → actionable (the signal path)
- **AI roles** — active components: amplifier, filter, connector, unblocker
- **Moderation** — prevents runaway feedback

---

## 2. Entity Model

### Two kinds of actors

Both **users** and **AI** are first-class entities. Both create nodes. Both can be parent or child in a thread. The difference is role, not rank.

**Users**: Identified by `user_id`. Create thoughts via Quick Capture (fire-and-forget) or Brainstorm (conversational with AI). Control sharing of their own content.

**AI**: Identified by `source` field (e.g., `'clerk-brainstorm'`, `'clerk-moderator'`) and optionally `clerk_key`. One system, multiple roles:

| AI Role | What it does | Pipeline stage |
|---------|-------------|----------------|
| **Responder** | 1:1 brainstorm partner, reacts to thoughts | Scatter |
| **Summarizer** | Synthesizes multiple thoughts into digests | Gather |
| **Linker** | Connects related thoughts across users/themes | Gather |
| **Moderator** | Keeps collective discussion constructive | Refine |
| **Resolver** | Reframes blockers, finds middle ground | Crystallize |

Each AI role creates nodes with its own identity. A moderator interjection carries different weight than a brainstorm reply.

### Groups

A group = 2+ users who may or may not have AI linked. The circuit container.

```
idea_groups
  id, name, description, created_by, created_at

idea_group_members
  group_id, user_id, role ('member', 'facilitator', 'observer'), joined_at
```

A thought enters a group's circuit when marked `shareable` and the author is a member. Groups can overlap — a thought in the "roads" circuit can also surface in the "taxes" circuit through thematic links.

---

## 3. Data Model

### Nodes: `idea_log` (exists)

Every thought, response, summary, and moderation note is a row.

```sql
idea_log
  id              INT AUTO_INCREMENT PRIMARY KEY
  user_id         INT NULL                -- NULL for anonymous, FK to users
  session_id      VARCHAR(36) NULL        -- browser session UUID
  parent_id       INT NULL                -- reply chain (tree structure), FK to self
  content         TEXT                    -- what was said
  ai_response     TEXT NULL               -- DEPRECATED: use separate AI node instead
  category        ENUM('idea','decision','todo','note','question','reaction','distilled','digest','chat')
  status          ENUM('raw','refining','distilled','actionable','archived') DEFAULT 'raw'
  tags            VARCHAR(255) NULL
  source          VARCHAR(50)             -- 'web','voice','clerk-brainstorm','clerk-moderator', etc.
  shareable       TINYINT(1) DEFAULT 0    -- gate for group visibility
  clerk_key       VARCHAR(50) NULL        -- which AI role created this (NULL for human)
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  updated_at      TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

**Key principle**: Both user and AI nodes live in the same table. The `source` and `clerk_key` fields distinguish who created the row and in what role.

### Reply chains: `parent_id` (exists)

Tree structure. "This responds to that." Each node has at most one parent, but can have many children.

```
#12 [user] "Fix the roads"
  ├─ #13 [AI-responder] "What specific roads concern you?"
  │    └─ #14 [user] "Main St has the worst potholes"
  │         └─ #15 [AI-responder] "Main St gets heavy truck traffic..."
  └─ #16 [user-B] "The bridge is the real priority"
       └─ #17 [AI-responder] "Engineering assessment says..."
```

### Thematic links: `idea_links` (new — Phase 3)

Network structure. Many-to-many. "This relates to that."

```sql
idea_links
  id              INT AUTO_INCREMENT PRIMARY KEY
  idea_id_a       INT NOT NULL            -- FK to idea_log
  idea_id_b       INT NOT NULL            -- FK to idea_log
  link_type       VARCHAR(30)             -- 'related','supports','challenges','synthesizes'
  created_by      INT NULL                -- user_id or NULL for AI
  clerk_key       VARCHAR(50) NULL        -- which AI role created the link
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP

  UNIQUE(idea_id_a, idea_id_b, link_type)  -- no duplicate links
```

**Why both structures?** Reply chains capture conversation flow (who said what in response to whom). Thematic links capture conceptual connections (this tax thought relates to that roads thought even though they were never in the same conversation).

Summarizing is the operation that makes both structures work together: the AI reads scattered nodes, creates a summary node (in `idea_log`), and links it back to every source node (via `idea_links` with `link_type = 'synthesizes'`).

---

## 4. What's Built (Phase 1 + 2)

### Pages

| Page | URL | Purpose |
|------|-----|---------|
| Quick Capture | `/talk/index.php` | Fire-and-forget thought entry (voice or text) |
| Brainstorm | `/talk/brainstorm.php` | AI-assisted chat, saves distilled ideas + full conversation |
| History | `/talk/history.php` | View, filter, promote, share thoughts |

### API: `/talk/api.php`

| Action | Method | What it does |
|--------|--------|-------------|
| `save` | POST | Save a thought (with category, source, tags, parent_id, shareable) |
| `history` | GET | Read back ideas with filters |
| `promote` | POST | Advance idea status (raw → refining → distilled → actionable) |
| `link` | POST | Set parent_id on an idea (tree linking) |
| `brainstorm` | POST | AI-assisted brainstorming via clerk |
| `toggle_shareable` | POST | Flip shareable flag on an idea |

### AI Clerk Actions (brainstorm)

The brainstorm clerk responds conversationally and can embed action tags:

| Action | What it does |
|--------|-------------|
| `SAVE_IDEA` | Distills and saves a clean idea from the conversation |
| `TAG_IDEA` | Adds/changes tags on a previously saved idea |
| `READ_BACK` | Lists ideas from the session |
| `SUMMARIZE` | Creates a digest of the session (auto-shareable) |

### Conversation Logging

Every brainstorm exchange is logged to `idea_log` with `category='chat'`:
- `content` = user's raw message
- `ai_response` = AI's cleaned response (on same row — Phase 2 model)
- `source` = `'claude-web'`
- `shareable` = user's toggle state

Chat rows are excluded from the clerk's session context injection (so it only sees distilled ideas, not raw conversation noise).

### History Features

- **Category filters**: All, Ideas, Decisions, Todos, Notes, Questions, Chat
- **Status filters**: All, Raw, Refining, Distilled, Actionable
- **View modes**: Flat (chronological) and Threaded (tree view via `parent_id`)
- **Single-thread focus**: `?thread=ID` shows one root + all descendants
- **Shareable checkbox**: Per-thought toggle (owner or any logged-in user for unowned thoughts)
- **Promote button**: Advance status one step forward
- **AI response display**: Shows `ai_response` inline on chat entries
- **User scoping**: Default shows your own; "Show all" to see everyone's

### Database State

```sql
-- idea_log: main table (exists, in production)
-- Columns added in Phase 2:
--   shareable TINYINT(1) NOT NULL DEFAULT 0

-- ai_clerks: brainstorm clerk capabilities
--   capabilities = 'save_idea,read_back,tag_idea,summarize'
```

---

## 5. What's Designed (Phase 3)

### 5a. AI as first-class entity in the graph

**Current model (Phase 2)**: AI response stored as `ai_response` column on the user's row. AI is metadata, not a node.

**Target model (Phase 3)**: Each AI response is its own `idea_log` row, with `parent_id` pointing to what it responds to. AI is a participant.

```
Phase 2:  Row #12 { content: "Fix roads", ai_response: "Good point..." }

Phase 3:  Row #12 { content: "Fix roads", source: "web" }
          Row #13 { content: "Good point...", source: "clerk-brainstorm", parent_id: 12 }
```

This means:
- Any node can be branched from (users can reply to what the AI said)
- The `ai_response` column becomes deprecated
- `source` and `clerk_key` identify the actor and role
- Trees naturally interleave user and AI nodes

### 5b. `idea_links` table (many-to-many network)

Trees capture conversation. Links capture meaning. A thought connects to many others:

```
#20 "Property taxes too high"  ──related──→  #12 "Fix the roads"
                               ──related──→  #15 "School funding tight"
                               ──related──→  #23 "Seniors struggling"
```

Link types: `related`, `supports`, `challenges`, `synthesizes`, `builds_on`

Links can be created by:
- AI (auto-clustering shareable thoughts by theme)
- Users (manual "this connects to that")
- Facilitators (curating during Refine stage)

### 5c. Summarizing as graph operation

A summary reads N nodes and produces 1 new node linked back to all sources:

```
#12 "Fix the roads"              ─┐
#15 "School funding tight"       ─┼── synthesizes ──→ #30 [AI-summarizer] "Infrastructure
#20 "Property taxes too high"    ─┤                    & tax burden cluster"
#23 "Seniors struggling"         ─┘
```

Summaries can cascade:
- Session summary (one user's brainstorm)
- Cluster summary (related thoughts from multiple users)
- Thread summary (discussion/debate on a topic)
- Meta-summary (summary of summaries — Crystallize stage)

### 5d. Groups as circuits

A group is a feedback/amplification circuit — 2+ users whose shareable thoughts flow through the AI-powered pipeline together.

#### Formation

- **Manual**: User creates a group, names it, invites others ("Let's talk about Main St roads")
- **Topic-seeded**: Created around a question or problem — the question IS the group
- **Organic** (later): AI notices shared themes across users' shareable thoughts and suggests a group

#### Structure

Each group has:
- **Name**: "Roads & Infrastructure"
- **Description/Purpose**: Focuses the circuit; the AI moderator uses this to keep things on track
- **Creator**: Becomes default facilitator
- **Tags**: For discoverability and AI linking

#### Membership & roles

| Role | Can do |
|------|--------|
| **Facilitator** | Create, manage membership, moderate, call crystallization |
| **Member** | Contribute thoughts, participate in refinement |
| **Observer** | Read only |

Multiple facilitators allowed. Creator is first facilitator.

#### Access levels

| Level | Who sees | Who contributes |
|-------|---------|----------------|
| **Open** | Anyone | Anyone who joins |
| **Closed** | Members only | Members only |
| **Observable** | Anyone | Members only |

Civic deliberation defaults toward **observable** — transparency matters.

#### Lifecycle

```
forming → active → crystallizing → crystallized → archived
```

- **Forming**: Members joining, raw thoughts scattering
- **Active**: Gathering and refining
- **Crystallizing**: Converging on proposals
- **Crystallized**: Output produced (.md deliverable), group fulfilled its purpose
- **Archived**: Read-only historical record

Re-openable: new information or members can reactivate a crystallized group. Any facilitator can reopen.

#### How thoughts enter the group

The shareable gate is the valve:
- Your thoughts marked `shareable = 1` become visible to groups you belong to
- Existing shareable thoughts auto-surface when you join — you don't re-enter everything
- AI can suggest: "You have 3 thoughts about taxes that relate to this group — share them?"

#### Group-aware AI context

The key value: when you brainstorm inside a group, the AI sees all shareable thoughts from group members, not just your own session.

Current context injection (Phase 2):
```sql
WHERE session_id = ? AND category != 'chat'
```

Group context injection (Phase 3):
```sql
WHERE user_id IN (SELECT user_id FROM idea_group_members WHERE group_id = ?)
  AND shareable = 1 AND category != 'chat'
```

This enables the circuit: you say "I'm worried about property taxes" and the AI says "Tom shared something similar — he found a senior relief program. Here's how your thought builds on his."

#### AI as group participant

AI roles are assigned per group, not global. A small brainstorm might only need the responder. A 50-person deliberation needs moderator + gatherer + summarizer.

| AI Role | Group function | Creates nodes as |
|---------|---------------|-----------------|
| Responder | Reacts to individual thoughts | `clerk-brainstorm` |
| Gatherer | Cross-links thoughts, suggests clusters | `clerk-gatherer` |
| Summarizer | Digests clusters/threads | `clerk-summarizer` |
| Moderator | Keeps discussion constructive | `clerk-moderator` |
| Resolver | Reframes blockers | `clerk-resolver` |

AI creates nodes in `idea_log` with `source` and `clerk_key` identifying the role. Its contributions appear in the group feed alongside user thoughts.

New tables:
- `idea_groups` — name, description, status, access level, creator
- `idea_group_members` — who's in, what role

### 5e. Crystallization output (.md documents)

The pipeline's end product is a **deliverable**, not just a database row. When a group is ready, the AI produces a structured markdown document:

```markdown
# Proposal: Main Street Infrastructure Plan

## Summary
Three community members identified overlapping concerns...

## Key Findings
- Property tax revenue funds both roads and schools (Tom, Maria)
- Main St bridge needs engineering assessment (Jamal)

## Proposed Actions
1. Apply for federal infrastructure grant (IIJA)
2. Commission bridge assessment — est. $15k

## Contributing Thoughts
- #12 Tom: "Property taxes are too high"
- #15 Maria: "School funding is tight"
- #20 Jamal: "Main St bridge is the priority"

## Sources
- CT DOT bridge inspection database (.gov)
```

**Storage**: Both DB and file.
- **DB**: Saved as `category='digest'` in `idea_log`, linked to source thoughts via `idea_links` (`link_type='synthesizes'`)
- **File**: Written to `talk/output/group-{id}-{slug}.md` for download/sharing

**On demand**: Group asks for it ("summarize this into a proposal"), the AI produces it, the group reviews. Not automatic.

**Provenance**: The "Contributing Thoughts" section traces every claim back to the person who said it. Maria's $9,600 doesn't get lost in abstraction.

### 5f. Recursive groups (groups of groups)

A group's crystallized .md output can be the **input** to a higher-level group. Same circuit, recursive:

```
Group A (Putnam): 3 people → pipeline → proposal-putnam.md
Group B (Bridgeport): 4 people → pipeline → proposal-bridgeport.md
    ↓                                                    ↓
Group C (CT Roads): Takes A.md + B.md as INPUT
    → scatter (the proposals are the raw thoughts now)
    → gather (AI cross-links the proposals)
    → crystallize → ct-roads-proposal.md
        ↓
Group D (Northeast): Takes CT.md + MA.md + ...
    → same pipeline → regional-proposal.md
```

Each level uses the exact same mechanism:
- Same `idea_log` nodes (the .md content becomes a thought)
- Same `idea_links` (AI connects proposals across sub-groups)
- Same AI roles (gatherer, summarizer, moderator)
- Same pipeline (Scatter → Gather → Refine → Crystallize)

The .md is the **portable unit** that flows between levels. It carries provenance — original contributors are cited all the way up.

This supports:
- `idea_groups.parent_group_id` — optional FK to self, for group hierarchy
- A crystallized group's .md auto-enters the parent group as a shareable thought

The "thousandfold expansion" is literally this: same circuit, from kitchen table to 1,900 towns to 50 states.

### 5g. AI roles via clerk system

The existing `ai_clerks` table supports multiple clerk personas. Phase 3 adds:

| Clerk key | Role | Capabilities | When active | Status |
|-----------|------|-------------|-------------|--------|
| `brainstorm` | Responder | save_idea, read_back, tag_idea, summarize | 1:1 brainstorm sessions | **Built (Phase 3)** |
| `gatherer` | Linker/Summarizer | link, cluster, summarize | Background processing of shareable thoughts | **Built (Phase 3)** |
| `moderator` | Moderator | flag, redirect, cool_down | Group discussions | Future |
| `resolver` | Resolver | reframe, find_common_ground, propose_compromise | Blocked threads | Future |

Each creates nodes with its `clerk_key` stamped on the row.

### 5h. Builder kits as group use case

TPB has two volunteer-driven builder kits — **state pages** (11 sections, benefits-heavy) and **town pages** (8 sections, local government focus). Both currently use a solo workflow: one volunteer downloads the kit, works with Claude on claude.ai, packages a ZIP, uploads it through the volunteer dashboard.

`/talk` groups replace the solo workflow with collaborative building:

```
Current (solo):
  Volunteer → claude.ai (external) → ZIP → upload → review

With /talk groups:
  Group "Build: Fort Mill SC"
    → Kit template defines WHAT to research (JSON sections)
    → Group members scatter research across sections
    → /talk AI replaces the external Claude session
    → AI cross-links findings across members
    → Crystallize → structured .md that maps to template sections
    → Output feeds the actual page build
```

#### How kit sections map to the group

The kit templates (town-data-template.json, state-data-template.json) define the sections to research — overview, government, budget, schools, benefits, etc. Each section is a natural topic within the group. Volunteers can divide labor ("I'll research schools, you take budget") and the AI cross-links their work.

For larger builds, sections become sub-groups:

```
Group: "Build CT State Page" (parent)
  ├── Group: "CT Benefits Research"     → crystallizes benefits section
  ├── Group: "CT Government & Budget"   → crystallizes gov/budget sections
  ├── Group: "CT Towns Grid"            → crystallizes towns listing
  └── ...each sub-group's .md maps to a template section
```

#### Connection to volunteer task system

The volunteer task chain (Build → Test → Deploy, documented in `docs/TPB-Volunteer-Task-Workflow.md`) connects to groups:

- **Claiming a build task** auto-creates or links to a `/talk` group for that town/state
- **Group membership** = the volunteers working on that task
- **Crystallized output** = the deliverable that gets tested and deployed
- **Group lifecycle** mirrors task status: forming (claimed) → active (in_progress) → crystallized (review) → archived (completed)

#### Groups keep the kit generic

Groups don't hardcode "state build" or "town build" structure. The kit docs guide the human on what to research; the group is just the circuit where the research happens collaboratively. A `tags` column on `idea_groups` (e.g., `state-build, CT` or `town-build, fort-mill-sc`) provides discoverability without imposing structure.

This means the same group mechanism works for:
- Building a town page (8 sections, 30-60 min)
- Building a state page (11 sections, 4-6 hours)
- Deliberating on local issues (open-ended)
- Any future use case that follows the Scatter → Gather → Refine → Crystallize pipeline

#### Builder kit docs

```
docs/
  state-builder/              — State page building kit
    STATE-BUILDER-AI-GUIDE.md     Guide for working with AI
    state-data-template.json      JSON schema (CT as example)
    state-build-checklist.md      QA checklist before submission
    ETHICS-FOUNDATION.md          Golden Rule, selfless service
    VOLUNTEER-ORIENTATION.md      Maria, Tom, Jamal personas
    README.md                     Kit overview

  town-builder/               — Town page building kit
    TOWN-BUILDER-AI-GUIDE.md      Guide for working with AI
    town-data-template.json       JSON schema (blank template)
    TOWN-TEMPLATE.md              PHP/HTML template guide

  TPB-Volunteer-Task-Workflow.md — Build→Test→Deploy task chain
```

Both kits will evolve to reference `/talk` groups as the collaboration layer, replacing the solo "download kit + use claude.ai" pattern with in-platform group brainstorming.

### 5i. Thought form as gateway into /talk

The existing thought submission form (`includes/thought-form.php`) on town and state pages is the **on-ramp** into `/talk`. It captures the moment someone cares enough to type — and turns it into an invitation to go deeper.

#### The engagement gradient

There is no wall between "citizen" and "volunteer." Civic engagement is a gradient:

```
Submit a thought (thought form)     → you showed up
Brainstorm with AI (/talk)          → you went deeper
Join a group                        → you're collaborating
Crystallize a proposal              → you're building
```

The thought form is the first rung. `/talk` is the ladder.

#### How the gateway works

After submitting a thought, the confirmation can invite the person into `/talk`:

```
Person submits "childcare is too expensive" on Putnam page
  → category: Housing
  ↓
Confirmation: "Thought shared! ✓"
  + "3 other Putnam residents are discussing Housing. Join the conversation?"
  ↓
Click → /talk group "Putnam Housing"
  → They see others' shareable thoughts
  → AI connects: "Sarah said the same — and found Care4Kids covers $9,600/year"
  → Now they're in the circuit
```

#### Civic categories as seed groups

The `thought_categories` table already defines what people care about:

| Category | Maps to /talk group |
|----------|-------------------|
| Infrastructure | "[Town] Infrastructure" |
| Education | "[Town] Education" |
| Housing | "[Town] Housing" |
| Healthcare | "[Town] Healthcare" |
| Environment | "[Town] Environment" |
| Transportation | "[Town] Transportation" |
| Public Safety | "[Town] Public Safety" |
| Economy | "[Town] Economy" |
| Government | "[Town] Government" |
| Community | "[Town] Community" |

These are the natural seed groups for every town. They don't need to be pre-created — when the first person submits a Housing thought and clicks through, the group forms organically around them.

#### Two systems, one pipeline

The thought form and `/talk` are not parallel systems to merge — they're **two stages** of the same pipeline:

| | Thought form | /talk |
|--|-------------|-------|
| **Stage** | Scatter | Gather → Refine → Crystallize |
| **Table** | `thoughts` | `idea_log` |
| **Metadata** | Jurisdiction, branch, category | Status, tags, shareable, parent_id, groups |
| **AI** | None | Brainstorm, summarize, cross-link, moderate |
| **Groups** | None (but category = implicit topic) | Explicit groups with membership |
| **Who** | Anyone who cares enough to type | Anyone who cares enough to stay |

The `thoughts` table captures the scatter. The `idea_log` table powers the pipeline. The gateway invitation is the bridge between them — the moment a raw civic thought enters the circuit.

#### Volunteer-only categories stay operational

The `is_volunteer_only` categories (Open Task, TPB Build, Task Update, Task Completed) are operational — they belong in the task system, not the civic pipeline. These may evolve separately or fold into `/talk` as task-linked groups (see 5h).

### 5j. Future: Batch processing ideas

Three batch job concepts that extend the `/talk` pipeline using the Anthropic Batch API (50% cost reduction for async jobs):

#### 1. Thought responder

After new civic thoughts are submitted via the thought form, a batch job processes them overnight:

- Pull unresponded `thoughts` rows since last run
- For each, generate a personalized response acknowledging their concern
- Include relevant data ("CT has a childcare subsidy — Care4Kids covers up to $9,600/year")
- End with a `/talk` group invitation ("3 others in Putnam are discussing Housing — join?")
- Store response for delivery (email or in-app notification)

This turns a one-way submission into the start of a conversation, without real-time API cost.

#### 2. Threat collector

Automates the existing manual process documented in `docs/collect-threats-process.md`:

- Batch job searches news sources (NPR, CNN, AP, Reuters, .gov sites)
- Compares findings against existing `trump_threats` table for deduplication
- Generates INSERT SQL with proper `official_id` mapping
- Outputs a review file for human approval before loading

Currently manual (last run: January 2026, 107 threats). Batch API makes this runnable weekly at ~50% cost.

#### 3. Monthly town digest

A synthesis job that pulls from multiple TPB data sources to create a local news update:

```
Inputs:
  - Recent thoughts from thought form (anonymized/aggregated)
  - Business listings from directory_listings (map.php ?mode=directory, future)
  - /talk group activity and crystallized proposals
  - Community calendar events (calendar.php, future)
  - .gov announcements relevant to the town

Output:
  - Structured markdown digest: "What happened in Putnam this month"
  - Publishable to town page, email newsletter, or /talk
```

This is the "local newspaper" function — synthesizing what's happening in town from multiple civic data streams. The batch API makes it affordable to run monthly per town.

#### Implementation notes

- All three use the Anthropic [Message Batches API](https://docs.anthropic.com/en/docs/build-with-claude/batch-processing) — 50% cost, 24-hour turnaround
- Could be triggered by cron, manual script, or volunteer dashboard button
- Each produces output for human review before any public action
- None of these are built yet — they're ideas for when the core `/talk` pipeline is solid

---

## 6. Schema Evolution Path

### Phase 2 → Phase 3 Migration

```sql
-- 1. Add clerk_key to idea_log
ALTER TABLE idea_log ADD COLUMN clerk_key VARCHAR(50) NULL;

-- 2. Create idea_links
CREATE TABLE idea_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    idea_id_a INT NOT NULL,
    idea_id_b INT NOT NULL,
    link_type VARCHAR(30) NOT NULL DEFAULT 'related',
    created_by INT NULL,
    clerk_key VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (idea_id_a) REFERENCES idea_log(id),
    FOREIGN KEY (idea_id_b) REFERENCES idea_log(id),
    FOREIGN KEY (created_by) REFERENCES users(user_id),
    UNIQUE KEY unique_link (idea_id_a, idea_id_b, link_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Create idea_groups
CREATE TABLE idea_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_group_id INT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    tags VARCHAR(255) NULL,
    status ENUM('forming','active','crystallizing','crystallized','archived') DEFAULT 'forming',
    access_level ENUM('open','closed','observable') DEFAULT 'observable',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_group_id) REFERENCES idea_groups(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Create idea_group_members
CREATE TABLE idea_group_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('member','facilitator','observer') DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES idea_groups(id),
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    UNIQUE KEY unique_membership (group_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Backfill: convert ai_response rows to separate AI nodes
-- (migration script — run once, then deprecate ai_response column)
```

### Backward Compatibility

- `ai_response` column stays but is no longer written to for new entries
- Existing chat rows with `ai_response` continue to display in history
- History rendering checks both: if row has `ai_response`, show inline; if row has AI child nodes, show threaded

---

## 7. File Map

```
talk/
  index.php        — Quick Capture (fire-and-forget)
  brainstorm.php   — AI brainstorm chat
  history.php      — View/filter/promote/thread thoughts
  groups.php       — Create/list/manage groups (Phase 3)
  api.php          — All API actions
  output/          — Crystallized .md deliverables (Phase 3)

docs/
  talk-architecture.md       — This document (system architecture)
  talk-app-plan.md           — Original Phase 1 technical spec (1800 lines)
  talk-brainstorm-use-case.md — Real session demonstrating 1:1 AI brainstorm
  talk-phase3-seeds.md       — Pre-brainstorm notes for Phase 3 vision
  talk-philosophical-grounding.md — Why the method works (spiritual foundation)
  talk-csps-article-draft.md — Publishable article for CSPS
  TPB-Volunteer-Task-Workflow.md — Build→Test→Deploy chain, task system

  state-builder/             — State page builder kit (11 sections)
  town-builder/              — Town page builder kit (8 sections)
```

---

## 8. Related Docs

- **[talk-app-plan.md](talk-app-plan.md)** — Full Phase 1 spec with DB schema, UI mockups, meeting framework
- **[talk-brainstorm-use-case.md](talk-brainstorm-use-case.md)** — Real 1:1 session showing distillation in action
- **[talk-phase3-seeds.md](talk-phase3-seeds.md)** — Open questions that led to this architecture
- **[talk-philosophical-grounding.md](talk-philosophical-grounding.md)** — Spiritual/philosophical foundation
- **[talk-csps-article-draft.md](talk-csps-article-draft.md)** — CSPS article framing the vision
- **[TPB-Volunteer-Task-Workflow.md](TPB-Volunteer-Task-Workflow.md)** — Volunteer system, task chaining, mentorship
- **[state-builder/README.md](state-builder/README.md)** — State page builder kit
- **[town-builder/TOWN-BUILDER-AI-GUIDE.md](town-builder/TOWN-BUILDER-AI-GUIDE.md)** — Town page builder kit
