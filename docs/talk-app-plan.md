# /talk — Phone-Based Brainstorming App Plan

> From raw dictation to concrete ideas. One person or many. AI-assisted. Ethics-grounded.
>
> **See also:** [talk-architecture.md](talk-architecture.md) for the current system architecture, entity model, and Phase 3 design.

---

## Vision

A phone-first app where anyone can dictate ideas, read them back, react, and distill — with AI as a brainstorming partner that follows the rules and serves the people (Maria, Tom, Jamal). Works solo (1:1 with AI) and collaboratively (many contributors building on each other's ideas).

---

## What Exists Today

*Updated 2026-02-13*

| Component | Status | Location |
|-----------|--------|----------|
| Quick Capture (mic + text) | Working | `talk/index.php` |
| AI Brainstorm chat | Working | `talk/brainstorm.php` |
| History (flat + threaded) | Working | `talk/history.php` |
| API (save, history, promote, link, brainstorm, toggle_shareable) | Working | `talk/api.php` |
| `idea_log` table | Full schema (user, session, parent, status, tags, shareable) | DB: `sandge5_tpb2` |
| Brainstorm clerk | Working (save_idea, read_back, tag_idea, summarize) | `ai_clerks` table + `talk/api.php` |
| Conversation logging | Working (category='chat', ai_response on same row) | `talk/api.php` |
| Shareable toggle | Working (per-thought, in brainstorm + history) | `talk/brainstorm.php`, `talk/history.php` |
| Threaded tree view | Working (parent_id tree + single-thread focus) | `talk/history.php` |
| AI clerk system | Working (guide + brainstorm clerks) | `api/claude-chat.php`, `config-claude.php` |
| Brainstorming rules | Hardcoded in Putnam page | `z-states/ct/putnam/index.php:622` |
| Ethics framework | Complete docs | `docs/state-builder/ETHICS-FOUNDATION.md` |
| Use case narrative | Written | `docs/talk-brainstorm-use-case.md` |
| Architecture doc | Written | `docs/talk-architecture.md` |

---

## Database: `idea_log` Schema

*All columns below are live in production as of 2026-02-13.*

```sql
id          INT AUTO_INCREMENT PRIMARY KEY
user_id     INT NULL                -- FK to users, nullable for anonymous
session_id  VARCHAR(36) NULL        -- browser session UUID
parent_id   INT NULL                -- FK to self (tree structure)
content     TEXT NOT NULL
ai_response TEXT NULL               -- AI reply (Phase 2 model; deprecated in Phase 3 — see architecture doc)
category    VARCHAR(50) DEFAULT 'idea'  -- idea, decision, todo, note, question, reaction, distilled, digest, chat
status      ENUM('raw','refining','distilled','actionable','archived') DEFAULT 'raw'
tags        VARCHAR(500) NULL
source      VARCHAR(50) DEFAULT 'web'   -- web, voice, claude-web, claude-desktop, api
shareable   TINYINT(1) NOT NULL DEFAULT 0  -- gate for group visibility
created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at  TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
```

### Column Purposes

| Column | Purpose |
|--------|---------|
| `user_id` | Who captured this (nullable for anonymous/API) |
| `session_id` | UUID grouping a brainstorm session (one sitting) |
| `parent_id` | Self-referencing FK — reply chains, distillation chains, tree structure |
| `ai_response` | AI's reply (Phase 2: stored on same row as user message; Phase 3: will be separate nodes) |
| `category` | Type of entry. `chat` = raw brainstorm exchange. `digest` = AI summary. See full list above. |
| `status` | Pipeline position: raw → refining → distilled → actionable → archived |
| `tags` | Comma-separated tags for cross-idea discovery |
| `source` | How it was created: `web`, `voice`, `claude-web` (AI brainstorm) |
| `shareable` | Whether this thought is visible to the group circuit (Phase 3 collective) |
| `updated_at` | Track when ideas get refined/promoted |

### The Distillation Chain

```
idea #1 (raw)       "thoughts.php has categories"
  └─ idea #5 (refining)  "that's an example of brainstorm input, not a task"
       └─ idea #8 (refining)  "iterative reading and talking — distillation process"
            └─ idea #12 (distilled)  "apply ethics + phone = collaborative development"
                 └─ idea #15 (actionable)  "build the /talk brainstorm app with AI clerk"
```

Each row's `parent_id` points to the idea it built on. A distillation chain is a linked list you can walk to see how a raw thought became concrete.

### Categories

- **idea** — raw creative input (default)
- **decision** — a choice that was made
- **todo** — something that needs doing
- **note** — reference information
- **question** — something to explore
- **reaction** — response to another idea (always has parent_id)
- **distilled** — a refined summary of a chain
- **digest** — AI-generated session summary (auto-shareable)
- **chat** — raw brainstorm exchange (user message + AI response)

---

## AI Clerk: `brainstorm`

### Registered in `ai_clerks` Table

*Live in production. Capabilities updated 2026-02-13.*

```sql
-- Current state (already in DB):
clerk_key = 'brainstorm'
clerk_name = 'Brainstorm Partner'
model = 'claude-sonnet-4-5-20250929'
capabilities = 'save_idea,read_back,tag_idea,summarize'
is_active = 1
```

### System Prompt (Core)

```
You are a brainstorming partner for The People's Branch (TPB).

## Your Rules (non-negotiable)
1. No criticism — every idea is valid
2. Build on ideas — "Yes, and..." not "No, but..."
3. Quantity over quality — help get everything out
4. Wild ideas welcome — refine later
5. Stay on topic — the user sets the topic

## Your Ethics (always active)
You build for real people:
- Maria, 34 — single mom, needs childcare. Your accuracy = her $9,600/year.
- Tom, 67 — retired, fixed income. Your clarity = his $4,200/year savings.
- Jamal, 22 — first home. Your thoroughness = his $20k down payment.

The Golden Rule: treat every idea as you'd want yours treated.
Test every suggestion: "Does this benefit ALL, or just some?"

## Your Behavior
- REFLECT: Mirror back what you heard, organized
- BUILD: Add "yes, and..." connections to existing ideas
- CONNECT: Surface related ideas from the user's history
- DISTILL: When asked, help tighten language — but never kill the idea
- NEVER: Shut down, rank, criticize, scope down, or say "that's too vague"

## Your Actions
When appropriate, use action tags to save state:
[ACTION: SAVE_IDEA] — capture a new idea to idea_log
[ACTION: LINK_IDEAS] — connect two ideas (set parent_id)
[ACTION: PROMOTE] — advance status (raw → refining → distilled → actionable)
[ACTION: TAG] — add tags to an idea for discovery
[ACTION: READ_BACK] — retrieve and present recent ideas for the user

## Session Awareness
You know the current session_id. All ideas in this session are part of one brainstorm.
You can see the user's idea history and suggest connections across sessions.
```

### Clerk Actions (implemented)

| Action | What It Does | Parameters |
|--------|-------------|------------|
| `SAVE_IDEA` | Distill and save a clean idea from conversation | content, category, tags (optional) |
| `TAG_IDEA` | Add/update tags on a saved idea | idea_id, tags |
| `READ_BACK` | List ideas from the session | category (optional), status (optional) |
| `SUMMARIZE` | Create a session digest (auto-shareable) | content, tags (optional) |

Actions are parsed from `[ACTION: TAG]` blocks in the AI's response by `parseBrainstormActions()` in `talk/api.php`.

**Not yet implemented** (designed for Phase 3):

| Action | What It Does |
|--------|-------------|
| `LINK` | Connect two ideas via `idea_links` (many-to-many) |
| `CLUSTER` | Group related ideas by theme |

---

## API: `/talk/api.php` v2

### Expanded Endpoints

**POST `/talk/api.php`** — Save an idea (existing, enhanced)
```json
{
    "content": "what if we added a childcare finder",
    "category": "idea",
    "source": "voice",
    "session_id": "uuid-here",
    "parent_id": null,
    "tags": "benefits,childcare"
}
```

Response:
```json
{
    "success": true,
    "id": 42,
    "session_id": "uuid-here",
    "status": "raw",
    "message": "Idea logged"
}
```

**GET `/talk/api.php?action=history`** — Read back ideas
```
?action=history
&user_id=5
&session_id=uuid (optional — filter to one session)
&category=idea (optional)
&status=raw (optional)
&limit=20
&include_ai=1 (include ai_response)
```

Response:
```json
{
    "success": true,
    "ideas": [
        {
            "id": 42,
            "content": "what if we added a childcare finder",
            "category": "idea",
            "status": "raw",
            "ai_response": "Yes — and that connects to the CT benefits page...",
            "parent_id": null,
            "children_count": 2,
            "tags": "benefits,childcare",
            "source": "voice",
            "created_at": "2026-02-12 14:30:00"
        }
    ]
}
```

**POST `/talk/api.php?action=brainstorm`** — AI brainstorm interaction
```json
{
    "message": "what if we made the brainstorming rules reusable",
    "session_id": "uuid-here",
    "history": [...],
    "user_id": 5
}
```

This routes to `claude-chat.php` with `clerk=brainstorm`, adding session context.

**POST `/talk/api.php?action=promote`** — Advance an idea's status
```json
{
    "idea_id": 42,
    "status": "refining"
}
```

**POST `/talk/api.php?action=link`** — Link ideas (set parent)
```json
{
    "idea_id": 45,
    "parent_id": 42
}
```

---

## Phone UI: `/talk/index.php` v2

### Three Modes (tab bar at bottom)

```
┌─────────────────────────────────┐
│         💡 Quick Thought         │
│                                  │
│          [  🎤  ]               │  ← Big mic button (capture mode)
│                                  │
│  ┌─────────────────────────┐    │
│  │ What's on your mind?    │    │  ← Text area (fills from voice)
│  └─────────────────────────┘    │
│                                  │
│  💡idea  📋todo  📝note  ❓q    │  ← Category chips
│                                  │
│  [ Save Thought ]                │
│                                  │
├──────────┬──────────┬────────────┤
│ 🎤 Talk  │ 📖 Read  │ 🤖 AI     │  ← Bottom tab bar
└──────────┴──────────┴────────────┘
```

### Tab 1: Talk (Capture)
What exists today, enhanced:
- Big mic button — tap to dictate
- Text area — fills from voice or type
- Category chips — expanded set
- Session awareness — all captures in one sitting grouped
- **New:** After saving, brief AI acknowledgment ("Got it — that connects to your idea about...")

### Tab 2: Read (Read Back)
The "idea reader" — the distillation engine:
- Shows recent ideas, newest first
- Filter by category, status, session
- **Thread view** — tap an idea to see its distillation chain (parent → children)
- **TTS read-back** — speaker button reads ideas aloud (browser speechSynthesis)
- **React button** — opens capture mode with `parent_id` pre-set (you're building on this idea)
- **Promote button** — swipe or tap to advance status (raw → refining → distilled → actionable)

```
┌─────────────────────────────────┐
│  📖 Your Ideas                   │
│  [All] [💡] [📋] [📝] [✅done] │
│                                  │
│  ┌─ 💡 raw ─────────────────┐   │
│  │ "thoughts.php has cats"   │   │
│  │ 2 min ago · voice         │   │
│  │ [🔊 Read] [↩️ React] [⬆️]│   │
│  └───────────────────────────┘   │
│                                  │
│  ┌─ 💡 refining ────────────┐   │
│  │ "that's brainstorm input" │   │
│  │  ↳ builds on #1           │   │
│  │ AI: "Right — this is the  │   │
│  │ capture concept..."       │   │
│  │ [🔊 Read] [↩️ React] [⬆️]│   │
│  └───────────────────────────┘   │
│                                  │
├──────────┬──────────┬────────────┤
│ 🎤 Talk  │ 📖 Read  │ 🤖 AI     │
└──────────┴──────────┴────────────┘
```

### Tab 3: AI (Brainstorm Session)
Full chat with the brainstorm clerk:
- Conversational AI with session context
- AI can see all ideas in this session
- AI follows the 5 rules and ethics
- AI can save ideas, link them, suggest connections
- "Read my ideas back to me" → AI summarizes and TTS plays

```
┌─────────────────────────────────┐
│  🤖 Brainstorm Session           │
│                                  │
│  ┌ AI ──────────────────────┐   │
│  │ I see 3 ideas in this    │   │
│  │ session. Your "childcare │   │
│  │ finder" connects to the  │   │
│  │ CT benefits page. Want   │   │
│  │ to build on that?        │   │
│  └──────────────────────────┘   │
│                                  │
│  ┌ You ─────────────────────┐   │
│  │ yes and what if it also  │   │
│  │ showed eligibility       │   │
│  └──────────────────────────┘   │
│                                  │
│  ┌ AI ──────────────────────┐   │
│  │ Yes — eligibility check  │   │
│  │ for Maria: income under  │   │
│  │ X, age of children...    │   │
│  │ That's her $9,600/year.  │   │
│  │ Saved as idea #47.       │   │
│  └──────────────────────────┘   │
│                                  │
│  ┌──────────────────┐ [Send]    │
│  │ 🎤 type or speak │           │
│  └──────────────────┘           │
│                                  │
├──────────┬──────────┬────────────┤
│ 🎤 Talk  │ 📖 Read  │ 🤖 AI     │
└──────────┴──────────┴────────────┘
```

---

## The Distillation Flow (End to End)

### Solo (1 person + AI)

```
1. CAPTURE     → Dictate "thoughts.php has categories"
                  Saved as idea #1, status: raw, session: abc-123

2. AI ACK      → "Got it. That connects to the thought_categories
                   table (10 civic + volunteer categories).
                   Want to explore?"

3. READ BACK   → Open Read tab, see idea #1
                  Tap 🔊 — phone reads it aloud
                  Think about it...

4. REACT       → Tap ↩️ React on idea #1
                  Dictate "that's an example of brainstorm input"
                  Saved as idea #2, parent_id: 1, status: raw

5. AI BUILD    → "Yes, and — so /talk is the intake funnel.
                   The brainstorming rules protect the input.
                   Categories organize. Want to keep going?"

6. PROMOTE     → Swipe idea #1 → status: refining
                  Swipe idea #2 → status: refining

7. REPEAT      → More dictation, more AI building
                  Ideas chain: #1 → #2 → #5 → #8

8. DISTILL     → In AI tab: "summarize this session"
                  AI produces distilled summary
                  Saved as idea #12, status: distilled
                  Links to all source ideas

9. ACTIONABLE  → "This is ready. Make it actionable."
                  AI suggests concrete next steps
                  Idea #15 created, status: actionable
                  → Could become a volunteer task, a feature spec,
                    or a brainstorm thought in the civic system
```

### Collaborative (multiple people)

See **Multi-Person Brainstorming Framework** below for the full design.

---

## Multi-Person Brainstorming Framework

### The Problem

Solo brainstorming (1:1 with AI) is powerful, but the real vision is collaborative:
volunteers across the country building on each other's ideas. That requires structure —
not a free-for-all chat room, but a facilitated brainstorming meeting with rules, roles,
time boundaries, and both real-time and asynchronous participation options.

### Core Concept: Brainstorm Meetings

An admin creates a **brainstorm meeting** — a bounded space with a topic, time window,
and rules. Participants join and contribute ideas. The AI facilitates. The output is
distilled ideas, not just a pile of text.

---

### Database: `brainstorm_meetings`

```sql
CREATE TABLE brainstorm_meetings (
    meeting_id      INT AUTO_INCREMENT PRIMARY KEY,
    created_by      INT NOT NULL,                       -- admin user_id
    title           VARCHAR(200) NOT NULL,              -- "Childcare Resources for CT"
    description     TEXT NULL,                          -- fuller context, goals, scope
    topic_tags      VARCHAR(500) NULL,                  -- "childcare,benefits,ct" for discovery

    -- Timing
    mode            ENUM('sync','async','hybrid') DEFAULT 'async',
    scheduled_start DATETIME NULL,                      -- when it opens (NULL = open now)
    scheduled_end   DATETIME NULL,                      -- when it closes (NULL = no deadline)
    status          ENUM('draft','scheduled','open','active','closing','closed','archived')
                    DEFAULT 'draft',

    -- Access
    join_code       VARCHAR(12) UNIQUE NOT NULL,        -- short code: "CARE-2026" or "XK7M"
    visibility      ENUM('public','invite','private') DEFAULT 'invite',
    max_participants INT DEFAULT 25,

    -- Facilitation
    ai_clerk_key    VARCHAR(50) DEFAULT 'brainstorm',   -- which clerk facilitates
    custom_rules    TEXT NULL,                           -- JSON override of brainstorm rules (optional)
    focus_prompt    TEXT NULL,                           -- extra AI context for this meeting's topic

    -- Sync mode settings
    round_duration_sec  INT DEFAULT 120,                -- time per round (sync mode)
    phases          TEXT NULL,                           -- JSON: ordered phases, e.g.
                                                        -- [{"name":"diverge","min":10},{"name":"converge","min":5}]

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (created_by) REFERENCES users(user_id),
    INDEX idx_status (status),
    INDEX idx_join_code (join_code),
    INDEX idx_scheduled (scheduled_start, scheduled_end)
);
```

### Database: `brainstorm_participants`

```sql
CREATE TABLE brainstorm_participants (
    participant_id  INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id      INT NOT NULL,
    user_id         INT NOT NULL,
    role            ENUM('admin','facilitator','contributor','observer') DEFAULT 'contributor',
    status          ENUM('invited','joined','active','left','removed') DEFAULT 'invited',
    joined_at       DATETIME NULL,
    last_active_at  DATETIME NULL,
    ideas_count     INT DEFAULT 0,                      -- denormalized for quick display
    reactions_count INT DEFAULT 0,

    FOREIGN KEY (meeting_id) REFERENCES brainstorm_meetings(meeting_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id),
    UNIQUE KEY uniq_meeting_user (meeting_id, user_id),
    INDEX idx_meeting (meeting_id),
    INDEX idx_user (user_id)
);
```

### Database: `idea_log` — Add Meeting Link

```sql
ALTER TABLE idea_log ADD COLUMN meeting_id INT NULL AFTER session_id;
ALTER TABLE idea_log ADD INDEX idx_meeting (meeting_id);
ALTER TABLE idea_log ADD FOREIGN KEY (meeting_id) REFERENCES brainstorm_meetings(meeting_id) ON DELETE SET NULL;
```

Ideas with `meeting_id` belong to a meeting. Ideas without are personal (solo brainstorm).
The `session_id` still groups ideas within a single sitting — a participant may join a meeting
across multiple sessions (async).

---

### Dialogue Protocols

#### Async Protocol

**When:** Participants contribute on their own schedule within the meeting's time window.
This is the default and the most phone-friendly mode.

```
Meeting: "Childcare Resources for CT"
Window:  Feb 12 9am — Feb 14 5pm (3 days)
Mode:    async

Timeline:
─────────────────────────────────────────────────
Day 1, 9am    Admin creates meeting, shares join code "CARE-2026"

Day 1, 10am   Sarah joins, dictates 2 ideas from her phone
              AI: "Got it. These connect to the CT benefits page."

Day 1, 3pm    Marcus joins, reads Sarah's ideas
              Taps React on Sarah's #2: "yes, and link to WIC too"
              AI: "Good build — WIC is $125/mo for Maria's family."

Day 1, 9pm    Sarah checks back, sees Marcus's build
              Dictates a reaction: "WIC plus childcare = $12k/year total"
              AI: "That's a strong distilled number. Promote?"

Day 2, 8am    Two more participants join, read the thread
              Add 3 new ideas branching from the chain

Day 2, 6pm    AI posts a digest: "5 participants, 11 ideas,
              3 distillation chains. Top themes: childcare,
              WIC, benefits bundling."

Day 3, 5pm    Meeting closes. AI produces final summary.
              Actionable ideas get promoted to the task system.
─────────────────────────────────────────────────
```

**Async mechanics:**
- Participants get a notification (email or in-app) when new ideas are added
- AI posts periodic digests (configurable: every N ideas, daily, or manual)
- Read tab shows all meeting ideas with attribution and threading
- No pressure to be online at the same time
- Time window creates gentle urgency without excluding people with busy schedules

#### Sync Protocol

**When:** Everyone online at the same time. Real-time brainstorming like a meeting room.
Best for focused sessions with smaller groups.

```
Meeting: "Fort Mill Town Page Sprint"
Window:  Feb 15, 7pm — 8pm (1 hour)
Mode:    sync

Flow:
─────────────────────────────────────────────────
7:00  GATHER (5 min)
      Participants join. AI welcomes, states topic and rules.
      "Tonight: Fort Mill town page. 8 of you here.
       Remember: no criticism, wild ideas welcome."

7:05  DIVERGE (20 min)
      Free-form capture. Everyone dictates/types simultaneously.
      Ideas stream into a shared feed, real-time.
      AI acknowledges each: "Got it, Sarah." "Nice build, Marcus."
      AI connects: "Marcus, your idea echoes Sarah's #3."
      No discussion — just capture. Quantity over quality.

7:25  READ BACK (5 min)
      AI reads the combined ideas back (TTS or text).
      Groups by theme. "I see 3 clusters:
       benefits (7 ideas), infrastructure (4), community (3)."

7:30  CONVERGE (15 min)
      Structured building. AI presents one cluster at a time.
      Participants react, build, refine. "Yes, and..." only.
      AI tracks the distillation in real time.
      Promotes ideas as they sharpen.

7:45  DISTILL (10 min)
      AI presents draft summary of each cluster.
      Participants vote: thumbs up/down on distilled ideas.
      Top ideas get promoted to "actionable."

7:55  CLOSE (5 min)
      AI posts final summary. Actionable items listed.
      "Great session — 34 raw ideas became 6 actionable items.
       These will show up in the task system."
      Meeting status → closed.
─────────────────────────────────────────────────
```

**Sync mechanics:**
- Real-time feed via polling (every 2-3 seconds) or Server-Sent Events (SSE)
- Phase timer visible to all (admin can extend)
- AI facilitator drives transitions between phases
- Participants see each other's ideas as they appear
- Mic input goes directly to the shared feed (with attribution)
- Optional: raise-hand for turn-taking in converge phase

#### Hybrid Protocol

**When:** A meeting has both a scheduled live session AND an async window.

```
Meeting: "CT Benefits Overhaul"
Async window:  Feb 12 — Feb 18
Live session:  Feb 15, 7pm — 8pm

Flow:
- Feb 12-14: Async diverge. Participants add ideas on their schedule.
- Feb 15 7pm: Live sync session. AI summarizes async ideas first,
  then runs converge + distill phases with whoever is present.
- Feb 16-18: Async follow-up. Participants who missed live session
  can still react and build on the distilled ideas.
- Feb 18: Meeting closes. Final AI summary.
```

**Hybrid is the recommended default** — it doesn't exclude anyone (async),
but still gets the energy of real-time collaboration (sync).

---

### Joining a Meeting

#### Join by Code

Every meeting gets a `join_code` — short, memorable, easy to dictate:
- Admin-chosen: `CARE-2026`, `FTMILL-01`, `CT-BENEFITS`
- Auto-generated fallback: `XK7M`, `R3PQ` (4 chars, no ambiguous characters)

```
┌──────────────────────────────────┐
│  🤝 Join a Brainstorm             │
│                                   │
│  Enter meeting code:              │
│  ┌────────────────────────────┐  │
│  │  CARE-2026                 │  │
│  └────────────────────────────┘  │
│                                   │
│  [ Join ]                         │
│                                   │
│  Or say: "Join CARE 2026" 🎤     │
│                                   │
│  ─── Your Active Meetings ───    │
│  📍 CT Benefits (3 new ideas)    │
│  📍 Fort Mill Sprint (starts 7pm)│
└──────────────────────────────────┘
```

#### Join Methods

| Method | How | Best For |
|--------|-----|----------|
| **Code** | Type or dictate join code | Shared in person, on a call, in chat |
| **Link** | `https://4tpb.org/talk/join/CARE-2026` | Shared via text, email, social |
| **QR code** | Scan opens join link | In-person events, printed flyers |
| **Invite** | Admin adds user_id, sends notification | Known volunteers |
| **Public** | Meeting listed in `/talk/meetings.php` | Open community brainstorms |

#### Join Flow

```
1. User opens /talk/join.php?code=CARE-2026  (or types code on Join screen)
2. Server validates code → finds meeting
3. Check: is meeting open? Is user already a participant? Room for more?
4. If public/invite and user is logged in → auto-join as contributor
5. If private → check if user was pre-invited
6. Insert into brainstorm_participants (status: joined, joined_at: now)
7. Redirect to /talk/index.php?meeting=CARE-2026
8. UI switches to meeting mode: shows meeting title, participant count,
   shared idea feed, meeting timer (if sync)
```

---

### Participant Roles

| Role | Can | Can't |
|------|-----|-------|
| **Admin** | Create meeting, set rules, manage participants, close meeting, promote ideas, moderate | — |
| **Facilitator** | Promote ideas, guide conversation, moderate | Create/close meeting |
| **Contributor** | Add ideas, react, build, vote | Promote others' ideas, moderate |
| **Observer** | Read all ideas, listen to TTS | Add ideas, react, vote |

Admin assigns roles. Default for joining is `contributor`.
Observer mode is useful for stakeholders who want to watch but not influence.

---

### AI Facilitator in Multi-Person Mode

The brainstorm clerk's system prompt gets a meeting context block:

```
## Meeting Context
Meeting: "Childcare Resources for CT"
Mode: async
Phase: diverge
Participants: 5 active (Sarah, Marcus, Keisha, David, Lin)
Ideas so far: 11 (3 clusters identified)
Time remaining: 2 days, 3 hours

## Your Multi-Person Behavior
- ATTRIBUTE: Always name who said what. "Marcus, your idea..."
- CONNECT PEOPLE: "Sarah, Marcus's WIC idea builds on your #3."
- NO FAVORITES: Equal attention across participants.
- ENCOURAGE QUIET VOICES: If someone hasn't contributed recently,
  gently invite (but never pressure).
- DIGEST: When asked (or on schedule), summarize themes and clusters.
- MODERATE: If someone violates brainstorming rules (criticism,
  off-topic), gently redirect. "Let's stay in 'yes, and' mode."
- PHASE TRANSITIONS (sync): Announce phase changes, recap, guide.
```

### AI Actions (Multi-Person Additions)

| Action | What It Does | Parameters |
|--------|-------------|------------|
| `DIGEST` | Summarize meeting ideas into themes/clusters | meeting_id |
| `NOTIFY` | Send notification to participants | meeting_id, message, target (all/specific user) |
| `MODERATE` | Flag a contribution that may violate rules | idea_id, reason |
| `PHASE_CHANGE` | Transition to next phase (sync mode) | meeting_id, new_phase |
| `CLOSE_MEETING` | Finalize meeting, generate summary | meeting_id |

---

### API: Meeting Endpoints

**POST `/talk/api.php?action=create_meeting`** — Admin creates a meeting
```json
{
    "title": "Childcare Resources for CT",
    "description": "Brainstorm ideas for a childcare resource finder...",
    "mode": "hybrid",
    "scheduled_start": "2026-02-15T19:00:00",
    "scheduled_end": "2026-02-18T17:00:00",
    "visibility": "invite",
    "join_code": "CARE-2026",
    "topic_tags": "childcare,benefits,ct",
    "focus_prompt": "Focus on programs available to families earning under $50k..."
}
```

Response:
```json
{
    "success": true,
    "meeting_id": 7,
    "join_code": "CARE-2026",
    "join_url": "https://4tpb.org/talk/join/CARE-2026"
}
```

**POST `/talk/api.php?action=join_meeting`** — Join by code
```json
{
    "join_code": "CARE-2026"
}
```

**GET `/talk/api.php?action=meeting_feed`** — Real-time idea feed
```
?action=meeting_feed
&meeting_id=7
&since=2026-02-12T14:30:00  (for polling: only new since last check)
&include_ai=1
```

Response:
```json
{
    "success": true,
    "meeting": {
        "title": "Childcare Resources for CT",
        "mode": "async",
        "status": "open",
        "participant_count": 5,
        "time_remaining": "2d 3h",
        "phase": "diverge"
    },
    "new_ideas": [
        {
            "id": 55,
            "content": "link to WIC too",
            "user_display": "Marcus",
            "category": "reaction",
            "parent_id": 53,
            "ai_response": "Good build — WIC is $125/mo...",
            "created_at": "2026-02-12T15:05:00"
        }
    ],
    "stats": {
        "total_ideas": 11,
        "clusters": 3,
        "most_active": "Sarah (4 ideas)"
    }
}
```

**POST `/talk/api.php?action=meeting_digest`** — Request AI summary
```json
{
    "meeting_id": 7
}
```

**POST `/talk/api.php?action=close_meeting`** — Admin closes meeting
```json
{
    "meeting_id": 7,
    "generate_summary": true
}
```

---

### Phone UI: Meeting Mode

When a user is in a meeting, the Talk tab adapts:

```
┌──────────────────────────────────┐
│  🤝 Childcare Resources for CT   │
│  5 people · async · 2d left      │
│                                   │
│          [  🎤  ]                │
│                                   │
│  ┌─────────────────────────┐     │
│  │ What's your idea?       │     │
│  └─────────────────────────┘     │
│                                   │
│  💡idea  ↩️react  ❓question     │
│                                   │
│  [ Add to Brainstorm ]            │
│                                   │
├──────────┬──────────┬────────────┤
│ 🎤 Talk  │ 📖 Feed  │ 🤖 AI     │
└──────────┴──────────┴────────────┘
```

The Read tab becomes **Feed** — showing all participants' ideas in real time:

```
┌──────────────────────────────────┐
│  📖 Meeting Feed                  │
│  11 ideas · 3 clusters            │
│                                   │
│  ┌─ Sarah · 💡 ─────────────┐   │
│  │ "what about a childcare   │   │
│  │  cost calculator?"        │   │
│  │ 10:15am · [↩️] [🔊] [👍] │   │
│  └───────────────────────────┘   │
│                                   │
│  ┌─ Marcus · ↩️ → Sarah #3 ─┐   │
│  │ "yes and link to WIC —    │   │
│  │  that's $125/mo extra"    │   │
│  │ 3:05pm · [↩️] [🔊] [👍]  │   │
│  └───────────────────────────┘   │
│                                   │
│  ┌─ AI Digest ──────────────┐   │
│  │ 3 clusters emerging:      │   │
│  │ • Benefits bundling (5)   │   │
│  │ • Cost tools (3)          │   │
│  │ • Outreach (3)            │   │
│  └───────────────────────────┘   │
│                                   │
├──────────┬──────────┬────────────┤
│ 🎤 Talk  │ 📖 Feed  │ 🤖 AI     │
└──────────┴──────────┴────────────┘
```

Sync mode adds a phase banner and timer:

```
┌──────────────────────────────────┐
│  ⚡ DIVERGE  ████████░░  15:32   │
│  Fort Mill Sprint · 8 people     │
│                                   │
│  Live feed...                     │
│  (ideas appear as people talk)    │
└──────────────────────────────────┘
```

---

### Meeting Tiers: Open vs. TPB Volunteer

`/talk` serves two audiences with the same infrastructure but different access rules.

#### Open Meetings (anyone)

For personal, household, community, or any-topic brainstorming.
The snow plow session is a perfect example — nothing to do with TPB, and that's fine.

- **Who can create:** Any verified user (email verified)
- **Who can join:** Anyone with the code (or public listing)
- **AI clerk:** `brainstorm` — general brainstorming rules, no TPB-specific context
- **Categories:** `idea`, `decision`, `todo`, `note`, `question`, `reaction`
- **Output:** Transcript, distilled ideas, personal todos
- **Visibility options:** public, invite, private

#### TPB Volunteer Meetings (gated)

For platform development: building states, town pages, features, task planning,
civic education content, volunteer coordination.

- **Who can create:** Approved volunteers with `volunteer_status = 'active'`
- **Who can join:** Only verified volunteers (checked against `volunteer_roles` or similar)
- **AI clerk:** `brainstorm-tpb` — same rules, PLUS:
  - Full ethics context (Golden Rule, Maria/Tom/Jamal personas)
  - State-builder knowledge (from `system_documentation`)
  - Access to existing town/state data for context
  - Understands volunteer task system
- **Categories:** Civic topics + volunteer-only categories (already in DB):
  - `thought_categories` where `is_volunteer_only = 1`:
    Open Task (12), TPB Build (13), Task Update (14), Task Completed (15),
    Idea (16), Bug (17), Question (18), Discussion (19)
- **Output:** Transcript + actionable items promote directly into volunteer tasks
- **Visibility:** Always `private` or `invite` — never public-listed

#### How It Works in the Database

```sql
ALTER TABLE brainstorm_meetings ADD COLUMN tier ENUM('open','volunteer') DEFAULT 'open';
```

One column. The rest flows from it:

| | Open | Volunteer |
|--|------|-----------|
| `tier` | `open` | `volunteer` |
| Create check | `email_verified = 1` | `volunteer_status = 'active'` |
| Join check | Has code or public | `volunteer_status = 'active'` + has code |
| AI clerk | `brainstorm` | `brainstorm-tpb` |
| Categories | General | General + volunteer-only |
| Task integration | No | Yes — actionable → volunteer task |
| Ethics in AI prompt | Brainstorming rules only | Rules + Golden Rule + personas |
| Transcript access | Participants only | Participants + TPB admin |

#### The `brainstorm-tpb` Clerk

A second clerk row in `ai_clerks`, inheriting from `brainstorm` but adding:

```sql
INSERT INTO ai_clerks (clerk_key, clerk_name, model, capabilities, is_active)
VALUES (
    'brainstorm-tpb',
    'TPB Volunteer Brainstorm Partner',
    'claude-sonnet-4-5-20250929',
    'save_idea,link_ideas,read_back,distill,tag,digest,create_task,lookup_town,lookup_state',
    1
);
```

Extra system prompt block for `brainstorm-tpb`:

```
## TPB Volunteer Context
You are facilitating a brainstorm for TPB platform development.
These volunteers build civic infrastructure — state pages, town pages,
benefits directories, education content — for real citizens.

## Who You're Building For (always in mind)
- Maria, 34 — single mom, Hartford. Your accuracy = her $9,600/year in benefits.
- Tom, 67 — retired, Bridgeport. Your clarity = his $4,200/year savings.
- Jamal, 22 — recent grad, New Haven. Your thoroughness = his $20k down payment.

## The Test (apply to every idea)
"Does this benefit ALL citizens, or just some?"

## Extra Capabilities
- You can look up town and state data to ground ideas in real context
- You can create volunteer tasks from actionable ideas
- You know the state-builder checklist and can reference it
- Transcripts from these sessions may inform future builds
```

#### UI Indicator

When a user is in a volunteer meeting, the header shows a badge:

```
┌──────────────────────────────────┐
│  🤝 CT Town Builder Sprint  [TPB]│
│  5 volunteers · sync · 45 min    │
```

The `[TPB]` badge signals this is a gated volunteer session.
Open meetings show no badge — they're just meetings.

#### Why This Matters

The snow plow session proves `/talk` is a general-purpose brainstorming tool.
That's its strength — it's useful for anything, which means people will actually use it.

But TPB's core mission needs a protected space where volunteers brainstorm
with civic ethics baked in, where the AI knows who Maria is, and where
ideas flow directly into the task pipeline that builds state pages.

Same tool. Same UI. Same infrastructure. Different gate, different AI depth.

---

### Privacy & Safety

| Rule | Why |
|------|-----|
| Solo sessions are always private | Your raw thoughts are yours |
| Meeting ideas visible only to participants | Brainstorming needs psychological safety |
| Observer mode for stakeholders | Watch without influencing |
| Admin can remove participants | Handle bad actors |
| AI moderates against rule violations | "Let's stay constructive" not "You're wrong" |
| No anonymous contributions in meetings | Attribution builds accountability and trust |
| Meeting data retained after close | Participants can reference, admin can archive |
| Public meetings show on `/talk/meetings.php` | Community can discover and join open brainstorms |
| Private meetings require invite or code | Controlled access for focused work |
| Volunteer meetings gated by `volunteer_status` | Platform brainstorms stay with trusted builders |
| Open meetings never see volunteer data | Tier separation protects civic infrastructure work |
| Volunteer transcripts visible to TPB admin | Accountability + institutional knowledge |

---

### Meeting Lifecycle

```
DRAFT ──→ SCHEDULED ──→ OPEN ──→ ACTIVE ──→ CLOSING ──→ CLOSED ──→ ARCHIVED
  │           │           │         │           │          │
  │           │           │         │           │          └─ Long-term storage
  │           │           │         │           └─ AI generating final summary
  │           │           │         └─ At least 1 participant contributing
  │           │           └─ Past scheduled_start (or immediate if no schedule)
  │           └─ Has scheduled_start in future
  └─ Admin still editing settings
```

- **Draft → Scheduled:** Admin sets times and publishes
- **Scheduled → Open:** Cron or on-access check: `NOW() >= scheduled_start`
- **Open → Active:** First contribution received
- **Active → Closing:** `NOW() >= scheduled_end` or admin triggers close
- **Closing → Closed:** AI finishes summary, actionable items promoted
- **Closed → Archived:** Admin archives (ideas still readable, no new input)

---

### Transcripts

Since every contribution is text in `idea_log`, a full transcript is a natural byproduct —
no extra recording infrastructure needed.

#### What a Transcript Contains

```
TRANSCRIPT: "Childcare Resources for CT"
Meeting ID: 7 | Mode: hybrid | Feb 12–18, 2026
Participants: Sarah, Marcus, Keisha, David, Lin
Total ideas: 34 | Actionable: 6
───────────────────────────────────────────────

[Feb 12, 10:15am] Sarah (💡 idea)
  "what about a childcare cost calculator?"
  AI: "Got it — that connects to the CT benefits page.
       Care4Kids covers families under 75% SMI."

[Feb 12, 3:05pm] Marcus (↩️ reaction → Sarah #1)
  "yes and link to WIC too — that's $125/mo extra"
  AI: "Good build — WIC is $125/mo for Maria's family.
       Combined with Care4Kids that's $600/mo total."

[Feb 12, 9:22pm] Sarah (↩️ reaction → Marcus #1)
  "WIC plus childcare = $12k/year total impact"
  AI: "Strong distilled number. Promoted to refining."
  STATUS: raw → refining

  ...

[Feb 15, 7:25pm] AI DIGEST (sync session)
  "3 clusters: benefits bundling (12), cost tools (8),
   outreach (5). Top chain: Sarah→Marcus→Sarah on
   childcare+WIC bundling."

[Feb 18, 5:00pm] AI FINAL SUMMARY
  "34 raw ideas → 6 actionable items:
   1. Childcare+WIC benefits calculator (distilled from 5 ideas)
   2. ..."
  STATUS: meeting closed
```

#### API Endpoint

**GET `/talk/api.php?action=transcript`**
```
?action=transcript
&meeting_id=7           (meeting transcript)
&session_id=abc-123     (solo session transcript — alternative)
&format=json            (json | text | html)
```

Response (JSON format):
```json
{
    "success": true,
    "transcript": {
        "title": "Childcare Resources for CT",
        "meeting_id": 7,
        "mode": "hybrid",
        "period": "Feb 12–18, 2026",
        "participants": ["Sarah", "Marcus", "Keisha", "David", "Lin"],
        "stats": {
            "total_ideas": 34,
            "actionable": 6,
            "distillation_chains": 5,
            "clusters": 3
        },
        "entries": [
            {
                "id": 53,
                "timestamp": "2026-02-12T10:15:00",
                "user_display": "Sarah",
                "category": "idea",
                "content": "what about a childcare cost calculator?",
                "ai_response": "Got it — that connects to...",
                "parent_id": null,
                "status": "distilled",
                "tags": "childcare,calculator,benefits"
            },
            {
                "id": 55,
                "timestamp": "2026-02-12T15:05:00",
                "user_display": "Marcus",
                "category": "reaction",
                "content": "yes and link to WIC too...",
                "ai_response": "Good build — WIC is $125/mo...",
                "parent_id": 53,
                "status": "refining",
                "tags": "wic,benefits"
            }
        ]
    }
}
```

Response (text format — for reading, sharing, printing):
```
Plain text rendering of the transcript as shown above.
Suitable for email, paste into doc, or TTS read-back of entire session.
```

Response (HTML format — printable view):
```
Styled HTML page with threading visualization, status badges,
AI responses in distinct blocks. Link: /talk/transcript.php?meeting_id=7
```

#### Transcript Uses

| Use | How |
|-----|-----|
| **Review after meeting** | Participants revisit the full arc of ideas |
| **Onboard latecomers** | New participant reads transcript to catch up (async/hybrid) |
| **Feed into next session** | AI loads prior transcript as context for follow-up meeting |
| **Export for documentation** | Text/HTML format for reports, proposals, archives |
| **AI re-analysis** | Feed transcript to AI: "find patterns we missed" |
| **Accountability** | Who contributed what, when — transparent record |
| **TTS playback** | Read entire transcript aloud (text format → speechSynthesis) |

---

## Platform & Device Support

This is a **web app**, not a native app. Same URL on every device.

### How It Works Across Platforms

| Feature | Phone (iOS/Android) | PC/Mac | Tablet |
|---------|-------------------|--------|--------|
| Text input | Keyboard | Keyboard | Keyboard |
| Voice input (STT) | Chrome/Safari mic | Chrome/Edge mic | Chrome/Safari mic |
| Voice output (TTS) | speechSynthesis | speechSynthesis | speechSynthesis |
| Meeting feed | Polling / SSE | Polling / SSE | Polling / SSE |
| Notifications | Browser push (if enabled) | Browser push | Browser push |
| Layout | Single column, thumb-friendly | Wider: sidebar + main | Adaptive |

### Browser Requirements

- **Chrome 90+** or **Edge 90+** — full support (STT + TTS + SSE)
- **Safari 15+** — TTS works, STT works (webkitSpeechRecognition)
- **Firefox** — TTS works, STT not supported (text-only fallback)

No app store. No install. Open the URL and go. Add to home screen for app-like experience
(PWA meta tags already in `talk/index.php`).

### Responsive UI Adaptation

**Phone (< 600px):**
- Single column, bottom tab bar
- Big mic button center stage
- Cards stack vertically
- Swipe gestures for promote/react

**Desktop (> 900px):**
- Two-panel layout: feed on left, AI chat on right
- Keyboard shortcuts (Enter to send, Ctrl+Enter to save, Tab to switch tabs)
- Mic button in input bar (smaller, beside text field)
- Thread chains shown as indented tree (more horizontal space)

**Tablet (600–900px):**
- Hybrid: stacked cards but with more horizontal room
- Tab bar at bottom or side depending on orientation

The phone is the primary design target — if it works well on a 375px screen,
it works everywhere. Desktop gets a better layout, not different features.

---

## UI Use Case: Harley, Sandi & Ben — CT Snow Storm Brainstorm

> Three people. Three iPhones. One kitchen table (Xfinity WiFi). One hour.
> Topic: what to do about the relentless CT snow this year.

### The Setup

**Saturday morning, Feb 15, 2026.** Another 8 inches fell overnight. The driveway
Harley shoveled at 6am is already drifting over. Sandi's back hurts from yesterday's
round. Ben drove up from Putnam and almost got stuck on Route 44.

Harley pulls out his iPhone at the kitchen table. "Let's figure this out for real."

---

### 9:15am — Harley Creates the Meeting

Harley opens `4tpb.org/talk` in Safari. Taps the **+** icon in the top corner.

```
┌──────────────────────────────────┐
│  + New Brainstorm                 │
│                                   │
│  Title:                           │
│  ┌────────────────────────────┐  │
│  │ CT Snow: Buy a Plow?       │  │
│  └────────────────────────────┘  │
│                                   │
│  Mode: [Sync ✓] Async  Hybrid   │
│  Duration: 1 hour                 │
│                                   │
│  Join code:                       │
│  ┌────────────────────────────┐  │
│  │ SNOW-26                    │  │
│  └────────────────────────────┘  │
│                                   │
│  [ Create Meeting ]               │
└──────────────────────────────────┘
```

Harley taps **Create Meeting**. The app generates the session.
He says out loud: "Code is SNOW-26."

**Behind the scenes:**
```
POST /talk/api.php?action=create_meeting
{
    "title": "CT Snow: Buy a Plow?",
    "mode": "sync",
    "scheduled_end": "2026-02-15T10:15:00",
    "join_code": "SNOW-26",
    "visibility": "private",
    "focus_prompt": "Brainstorm solutions for heavy CT snowfall.
                     Consider: buying equipment, hiring services,
                     ROI, physical strain, drifting, sub-zero temps."
}
→ meeting_id: 12, status: active
→ Harley added as admin
```

### 9:16am — Sandi and Ben Join

Sandi opens Safari on her iPhone, goes to `4tpb.org/talk`, taps **Join**.
Types `SNOW-26`. She's in.

Ben does the same from across the table.

```
┌──────────────────────────────────┐
│  ⚡ CT Snow: Buy a Plow?         │
│  3 people · sync · 59 min left   │
│                                   │
│  ┌─ AI ────────────────────┐    │
│  │ Welcome Harley, Sandi,   │    │
│  │ and Ben! Tonight's topic │    │
│  │ is CT snow solutions.    │    │
│  │                          │    │
│  │ Remember the rules:      │    │
│  │ • No criticism           │    │
│  │ • "Yes, and..."          │    │
│  │ • Wild ideas welcome     │    │
│  │                          │    │
│  │ Go! What's on your mind? │    │
│  └──────────────────────────┘    │
│                                   │
├──────────┬──────────┬────────────┤
│ 🎤 Talk  │ 📖 Feed  │ 🤖 AI     │
└──────────┴──────────┴────────────┘
```

**Behind the scenes:**
```
POST /talk/api.php?action=join_meeting  { "join_code": "SNOW-26" }
→ brainstorm_participants: Sandi (contributor), Ben (contributor)
→ AI clerk 'brainstorm' gets meeting context injected into system prompt
→ Claude API call (~$0.01): generate welcome message with participant names
```

---

### 9:17am — DIVERGE Phase (20 min)

The phase banner shows: `⚡ DIVERGE ████████████░░░░ 18:42`

Everyone talks into their iPhones simultaneously. The ideas stream into the shared feed.

**Harley** taps the mic button, dictates:
> "We should just buy our own snow plow attachment for the truck.
> Used ones on Facebook Marketplace are like two grand."

His iPhone's SpeechRecognition converts it to text. He taps **Save**.

**What Harley sees (his phone):**
```
┌──────────────────────────────────┐
│  ⚡ DIVERGE  ████████░░  18:42   │
│                                   │
│          [  🎤  ]                │
│                                   │
│  ┌─────────────────────────┐     │
│  │ We should just buy our   │     │
│  │ own snow plow attachment │     │
│  │ for the truck. Used ones │     │
│  │ on Facebook Marketplace  │     │
│  │ are like two grand.      │     │
│  └─────────────────────────┘     │
│                                   │
│  💡idea  📋todo  📝note  ❓q    │
│                                   │
│  [ Add to Brainstorm ]            │
└──────────────────────────────────┘
```

**Behind the scenes:**
```
POST /talk/api.php
{
    "content": "We should just buy our own snow plow attachment for the truck.
                Used ones on Facebook Marketplace are like two grand.",
    "category": "idea",
    "source": "voice",
    "meeting_id": 12,
    "session_id": "harley-uuid-xxx"
}
→ idea_log id: 201, status: raw
→ Claude API call (~$0.01): AI generates acknowledgment
```

**2 seconds later, on everyone's Feed tab:**
```
│  ┌─ Harley · 💡 ─────────────┐  │
│  │ "We should just buy our    │  │
│  │ own snow plow attachment   │  │
│  │ for the truck. Used ones   │  │
│  │ on FB Marketplace are like │  │
│  │ two grand."                │  │
│  │ 9:17am                     │  │
│  │ AI: "Good starting point.  │  │
│  │ A plow attachment for a    │  │
│  │ pickup runs $1,500-3,000   │  │
│  │ used. What truck?"         │  │
│  │ [↩️ React] [🔊] [👍]      │  │
│  └────────────────────────────┘  │
```

**Sandi** taps her mic right after, dictates:
> "My back can't take another season of shoveling.
> I'm 64. The heavy wet stuff is dangerous."

**Ben** taps his mic:
> "What about one of those snow blower services?
> I saw a guy on Nextdoor doing driveways for 50 bucks a storm."

**The feed fills up. All three see each other's ideas in real time.**

The polling loop on each phone hits the feed endpoint every 3 seconds:
```
GET /talk/api.php?action=meeting_feed&meeting_id=12&since=2026-02-15T09:17:00
→ Returns new ideas since last check
→ JavaScript appends them to the feed
```

---

### 9:20am — The Builds Start

Sandi reads Harley's plow idea on her Feed tab. Taps **↩️ React**. Her phone
switches to Talk tab with `parent_id: 201` pre-set. She dictates:

> "Yes, and if we buy a plow, Harley can do our driveway and Ben's too.
> That's three households splitting the cost. Like $700 each."

**What the feed shows (all three phones):**
```
│  ┌─ Sandi · ↩️ → Harley #1 ──┐  │
│  │ "Yes, and if we buy a plow │  │
│  │ Harley can do our driveway │  │
│  │ and Ben's too. Three       │  │
│  │ households splitting the   │  │
│  │ cost. Like $700 each."     │  │
│  │ 9:20am                     │  │
│  │ AI: "Nice build. At $700   │  │
│  │ each and ~15 storms/year   │  │
│  │ in NE CT, that's $47 per   │  │
│  │ storm vs. $50 for a        │  │
│  │ service. Pays for itself   │  │
│  │ in year one."              │  │
│  │ [↩️ React] [🔊] [👍]      │  │
│  └────────────────────────────┘  │
```

**Behind the scenes:**
```
POST /talk/api.php
{
    "content": "Yes, and if we buy a plow...",
    "category": "reaction",
    "source": "voice",
    "meeting_id": 12,
    "parent_id": 201
}
→ idea_log id: 204, parent_id: 201

Claude API call with meeting context:
  System prompt includes: meeting topic, 3 participants, all ideas so far
  AI does the ROI math: $2,100 / 3 = $700 each, ~15 storms, $47/storm
  Cost: ~$0.01
```

Ben reacts to Sandi's build:
> "But who's getting up at 5am to plow? And what about the drifts?
> I plowed for my uncle — the wind blows it right back in 20 minutes."

**AI response to Ben:**
> "Real concern. Wind drifts in open areas can re-cover a driveway
> in under an hour during nor'easters. That's a maintenance question,
> not just a purchase question. What's the driveway exposure like?"

Harley reacts:
> "Our driveway is 200 feet long and faces north. Total wind tunnel.
> Last week I shoveled three times in one day."

**AI connects the dots:**
> "Harley, that's key data. A 200ft north-facing driveway in NE CT
> during a nor'easter could need 3-4 passes. At $50/service that's
> $150-200 per storm day. The plow ROI looks even better — but the
> labor is real. Sandi's back concern plus Harley's 3x/day shoveling
> both point the same direction."

---

### 9:37am — READ BACK Phase (5 min)

Phase banner changes: `📖 READ BACK  ████████████░░ 3:45`

AI posts a summary to the feed. On Sandi's phone she taps 🔊 and her
iPhone reads it aloud via speechSynthesis:

```
│  ┌─ AI READ BACK ─────────────┐  │
│  │ 12 ideas in 20 minutes.     │  │
│  │ Great energy!               │  │
│  │                             │  │
│  │ 3 clusters:                 │  │
│  │                             │  │
│  │ 🔧 Buy Equipment (5 ideas) │  │
│  │ • Plow attachment $1.5-3k   │  │
│  │ • Split 3 ways = $700 each │  │
│  │ • ROI: pays back year one   │  │
│  │ • Snow blower as backup     │  │
│  │ • Drift shield / wind fence │  │
│  │                             │  │
│  │ 💪 Physical Concerns (4)   │  │
│  │ • Sandi's back — can't     │  │
│  │   keep shoveling            │  │
│  │ • Harley's 3x/day in storms│  │
│  │ • Sub-zero: frostbite risk  │  │
│  │ • 200ft driveway, N-facing  │  │
│  │                             │  │
│  │ 💰 Hire Service (3 ideas)  │  │
│  │ • $50/storm Nextdoor guy    │  │
│  │ • Seasonal contract option  │  │
│  │ • But $150-200 on bad days  │  │
│  │                             │  │
│  │ Key tension: equipment cost │  │
│  │ vs. physical toll vs.       │  │
│  │ service reliability.        │  │
│  └─────────────────────────────┘  │
```

**Behind the scenes:**
```
AI DIGEST action triggered by phase change
Claude API call (~$0.02): reads all 12 ideas, clusters them, identifies tensions
Result stored as a special idea_log entry (category: 'digest', meeting_id: 12)
```

---

### 9:42am — CONVERGE Phase (15 min)

Phase banner: `🔄 CONVERGE  ████████░░░░ 12:15`

AI presents one cluster at a time. Participants build and refine.

**AI:** "Let's start with the equipment cluster. The plow at $700/each
seems strong. But Ben raised the drift problem. What solves that?"

Harley dictates:
> "What about a snow fence on the north side? My neighbor has one.
> Those orange plastic ones are like 50 bucks at Tractor Supply."

Sandi builds:
> "Yes, and if we put the snow fence up AND have the plow, the fence
> reduces drift, the plow handles what gets through. Belt and suspenders."

Ben builds:
> "And for the really bad storms — the ones where it's minus 10 and
> blowing sideways — we keep the Nextdoor guy's number as backup.
> $50 for the worst days is worth not getting frostbite."

**AI promotes and connects:**
> "That's converging into a hybrid strategy:
> 1. Snow fence ($50) — reduce drift
> 2. Plow attachment (~$2,100 / 3 = $700 each) — handle normal storms
> 3. Service backup ($50/storm) — extreme conditions only
>
> Estimated annual cost per household:
> - Year 1: $750 (fence + plow share) + ~$100 (2 backup calls) = $850
> - Year 2+: ~$100/year (backup only)
> vs. full service at $50 × 15 storms = $750/year ongoing
>
> Payback: midway through year 2. Promoting to refining."

```
STATUS: ideas #201, #204, #208, #211, #213 → refining
AI links them into a distillation chain: 201 → 204 → 208 → 213 → [distilled]
```

---

### 9:55am — DISTILL Phase (10 min)

Phase banner: `✨ DISTILL  ████████░░░░ 8:30`

AI presents the draft distilled idea. All three phones show it:

```
│  ┌─ AI DISTILLED IDEA #1 ─────┐  │
│  │                              │  │
│  │ 🏔️ CT Snow: Hybrid Strategy  │  │
│  │                              │  │
│  │ Three-household snow         │  │
│  │ partnership:                 │  │
│  │                              │  │
│  │ 1. Install snow fence on     │  │
│  │    north side ($50 total)    │  │
│  │                              │  │
│  │ 2. Buy used plow attachment  │  │
│  │    for Harley's truck        │  │
│  │    ($2,100 / 3 = $700 each) │  │
│  │                              │  │
│  │ 3. Harley plows all three   │  │
│  │    driveways after storms    │  │
│  │                              │  │
│  │ 4. Keep service backup for   │  │
│  │    extreme days ($50/call)   │  │
│  │                              │  │
│  │ ROI: $850 year 1, then      │  │
│  │ ~$100/year. Beats $750/year │  │
│  │ service. Saves Sandi's back.│  │
│  │ Saves Harley from 3x/day    │  │
│  │ shoveling.                  │  │
│  │                              │  │
│  │ Next steps:                  │  │
│  │ □ Check FB Marketplace for   │  │
│  │   plow attachments           │  │
│  │ □ Measure driveway for fence │  │
│  │ □ Get Nextdoor guy's number  │  │
│  │                              │  │
│  │ Built from 5 ideas by        │  │
│  │ Harley, Sandi, and Ben.      │  │
│  │                              │  │
│  │ [👍 Approve] [↩️ Suggest]   │  │
│  └──────────────────────────────┘  │
```

Sandi taps 👍. Ben taps 👍. Harley taps 👍.

**AI:** "Unanimous! Promoted to actionable. I've created three
todo items from the next steps. Great session — 16 raw ideas
became 1 concrete plan in under an hour."

```
STATUS: distilled idea → actionable
idea_log entries created for each next-step (category: 'todo')
```

---

### 10:05am — CLOSE

Phase banner: `✅ SESSION CLOSED`

```
│  ┌─ AI CLOSING SUMMARY ───────┐  │
│  │                              │  │
│  │ CT Snow: Buy a Plow?        │  │
│  │ Feb 15, 2026 · 50 minutes   │  │
│  │ Harley, Sandi, Ben          │  │
│  │                              │  │
│  │ 16 ideas → 3 clusters →     │  │
│  │ 1 actionable plan            │  │
│  │                              │  │
│  │ Decision: Three-household    │  │
│  │ snow partnership with plow,  │  │
│  │ fence, and backup service.   │  │
│  │                              │  │
│  │ [📄 View Transcript]         │  │
│  │ [🔗 Share Summary]           │  │
│  └──────────────────────────────┘  │
```

Sandi taps **View Transcript**. Her iPhone loads:
```
GET /talk/api.php?action=transcript&meeting_id=12&format=html
```

Full record: every idea, every AI response, every build chain, the
read back, the convergence, the final plan. All text. All timestamped.

Harley taps **Share Summary** — copies a link he can text to his
neighbor: "We figured out the snow thing. Here's the plan."

---

### What Just Happened — Under the Hood

```
3 iPhones on Xfinity WiFi
    ↓ HTTPS
4tpb.org/talk (Apache/PHP on InMotion)
    ↓ meeting_feed polling every 3 sec
    ↓ idea saves via /talk/api.php
    ↓
idea_log table (16 rows, meeting_id: 12)
    ↓ each idea triggers
Claude API (brainstorm clerk)
    ↓ ~$0.01-0.02 per response
    ↓ ~15 API calls total session
    ↓ estimated cost: $0.15-0.25
    ↓
AI responses stored in idea_log.ai_response
    ↓
Feed tab polls, JS renders new cards
    ↓
Browser speechSynthesis for TTS (free, client-side)
Browser SpeechRecognition for STT (free, client-side)
```

**Total infrastructure used:**
- 3 iPhones + Safari
- Home WiFi
- 1 shared hosting account (InMotion, existing)
- MySQL (existing `sandge5_tpb2` database)
- Claude API (~$0.20 for the whole session)
- No WebSocket server. No Redis. No app store. No video. No audio streaming.

**Total new technology:** Polling loop + meeting tables. That's it.
Everything else — voice, AI, text storage, feed display — already exists
in the current `/talk` system or `claude-chat.php`.

---

## Implementation Phases

### Phase 1: Database + API Foundation
- ALTER `idea_log` table (add columns, indexes, FK)
- Expand `talk/api.php` with action parameter routing
- Add session_id generation (UUID) on first capture
- User association (from cookie/session)
- **Test:** Save an idea with user_id, session_id, verify history returns it

### Phase 2: AI Brainstorm Clerk
- Insert `brainstorm` clerk into `ai_clerks`
- Write system prompt with rules + ethics
- Add clerk actions (SAVE_IDEA, LINK_IDEAS, PROMOTE, TAG, READ_BACK)
- Add action handlers in `claude-chat.php` (or new `talk/brainstorm-api.php`)
- Register `system_documentation` entry for brainstorm clerk
- **Test:** Chat with brainstorm clerk, verify it saves ideas via action tags

### Phase 3: Phone UI — Read Tab
- Add bottom tab bar to `talk/index.php`
- Build Read view (idea cards with thread chains)
- TTS read-back (browser speechSynthesis)
- React button (capture with parent_id)
- Promote gesture (status advancement)
- **Test:** Capture → Read back → React → verify chain in DB

### Phase 4: Phone UI — AI Tab
- Full chat interface with brainstorm clerk
- Session context (clerk sees all session ideas)
- Voice input in chat (mic button in input bar)
- AI acknowledgments after captures
- "Read my ideas back" → AI + TTS
- **Test:** Full distillation session — raw to actionable

### Phase 5: Meeting Foundation (Async)
- CREATE `brainstorm_meetings` and `brainstorm_participants` tables
- ALTER `idea_log` — add `meeting_id` column
- Meeting CRUD API: create, join, feed, close
- Join flow: `/talk/join.php?code=XXXX`
- Generate join codes (admin-chosen or auto-generated)
- Meeting listing page: `/talk/meetings.php` (public meetings)
- **Test:** Admin creates meeting → participant joins by code → adds idea → appears in feed

### Phase 6: Async Multi-Person UI
- Meeting mode in Talk tab (header shows meeting name, participant count, time remaining)
- Feed tab (replaces Read tab in meeting context) — all participants' ideas with attribution
- React across people (build on someone else's idea)
- AI digest on demand or scheduled
- Notification stubs (in-app indicator; email optional later)
- **Test:** 2-3 users contributing async over hours, AI digests, distillation chains across users

### Phase 7: Sync Mode
- Real-time feed (polling every 2-3s or SSE)
- Phase system: diverge → read back → converge → distill → close
- Phase timer visible to all (admin can extend)
- AI facilitator drives phase transitions
- Live participant count and activity indicators
- **Test:** 3+ users in sync session, phase transitions, AI moderation

### Phase 8: Volunteer Tier
- Add `tier` column to `brainstorm_meetings` (open / volunteer)
- Volunteer gate: check `volunteer_status = 'active'` on create and join
- Register `brainstorm-tpb` clerk with ethics + state-builder context
- Volunteer-only categories available in volunteer meetings
- `create_task` action: promote actionable ideas into volunteer task system
- `[TPB]` badge in meeting header for volunteer sessions
- Volunteer transcripts accessible to TPB admin
- **Test:** Non-volunteer blocked from creating/joining volunteer meeting; volunteer creates meeting, brainstorms, promotes idea to task

### Phase 9: Hybrid + Polish
- Hybrid mode (async window with embedded sync session)
- Meeting lifecycle automation (draft → scheduled → open → active → closing → closed)
- Cron job for meeting status transitions and scheduled digests
- QR code generation for join links
- Observer role (read-only participation)
- Meeting archive and history
- **Test:** Full hybrid meeting lifecycle end-to-end

---

## Files Affected

| File | Change |
|------|--------|
| `talk/index.php` | Major: tab bar, session management, meeting mode, Read/Feed and AI tabs |
| `talk/api.php` | Major: action routing — history, brainstorm, promote, link, meeting CRUD, feed |
| `talk/join.php` | **New:** Join meeting by code/link |
| `talk/meetings.php` | **New:** List public/active meetings |
| `talk/history.php` | May merge into Read tab or keep as standalone |
| `api/claude-chat.php` | Add brainstorm clerk actions (SAVE_IDEA, LINK_IDEAS, PROMOTE, TAG, READ_BACK, DIGEST, MODERATE, PHASE_CHANGE, CLOSE_MEETING) |
| DB: `idea_log` | ALTER: add user_id, session_id, meeting_id, parent_id, status, ai_response, tags, updated_at |
| DB: `brainstorm_meetings` | **New table:** meeting definitions, timing, access, facilitation settings |
| DB: `brainstorm_participants` | **New table:** who joined which meeting, roles, activity tracking |
| DB: `ai_clerks` | INSERT: `brainstorm` (general) and `brainstorm-tpb` (volunteer, ethics + state-builder context) |
| DB: `brainstorm_meetings` | Add `tier` column (open / volunteer) |
| DB: `system_documentation` | INSERT: brainstorm clerk docs, talk app docs, meeting system docs |

---

## Related Documentation

- [Use Case: Brainstorming with AI (1:1)](talk-brainstorm-use-case.md) — The session that inspired this plan
- [Ethics Foundation](state-builder/ETHICS-FOUNDATION.md) — Golden Rule, the "benefit ALL" test
- [Volunteer Orientation](state-builder/VOLUNTEER-ORIENTATION.md) — Maria, Tom, Jamal personas
- [Brainstorming Rules](../z-states/ct/putnam/index.php) — The five rules (line 622)
