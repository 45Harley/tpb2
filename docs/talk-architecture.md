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

### 5d. Groups

Groups define who's in the circuit. Thoughts enter the group space when marked shareable.

New tables:
- `idea_groups` — name, description, creator
- `idea_group_members` — who's in, what role (member, facilitator, observer)

Optional: `idea_log.group_id` to directly assign a thought to a group, or derive group membership through `shareable` + author's group membership.

### 5e. AI roles via clerk system

The existing `ai_clerks` table supports multiple clerk personas. Phase 3 adds:

| Clerk key | Role | Capabilities | When active |
|-----------|------|-------------|-------------|
| `brainstorm` | Responder | save_idea, read_back, tag_idea, summarize | 1:1 brainstorm sessions |
| `moderator` | Moderator | flag, redirect, cool_down | Group discussions |
| `resolver` | Resolver | reframe, find_common_ground, propose_compromise | Blocked threads |
| `gatherer` | Linker/Summarizer | link, cluster, summarize | Background processing of shareable thoughts |

Each creates nodes with its `clerk_key` stamped on the row.

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
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
  api.php          — All API actions

docs/
  talk-architecture.md       — This document (system architecture)
  talk-app-plan.md           — Original Phase 1 technical spec (1800 lines)
  talk-brainstorm-use-case.md — Real session demonstrating 1:1 AI brainstorm
  talk-phase3-seeds.md       — Pre-brainstorm notes for Phase 3 vision
  talk-philosophical-grounding.md — Why the method works (spiritual foundation)
  talk-csps-article-draft.md — Publishable article for CSPS
```

---

## 8. Related Docs

- **[talk-app-plan.md](talk-app-plan.md)** — Full Phase 1 spec with DB schema, UI mockups, meeting framework
- **[talk-brainstorm-use-case.md](talk-brainstorm-use-case.md)** — Real 1:1 session showing distillation in action
- **[talk-phase3-seeds.md](talk-phase3-seeds.md)** — Open questions that led to this architecture
- **[talk-philosophical-grounding.md](talk-philosophical-grounding.md)** — Spiritual/philosophical foundation
- **[talk-csps-article-draft.md](talk-csps-article-draft.md)** — CSPS article framing the vision
