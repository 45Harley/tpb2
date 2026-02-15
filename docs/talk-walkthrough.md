# /talk Visual Walkthrough

**A step-by-step guide to using TPB's collective deliberation system.**

This walkthrough covers the unified Talk page and the Groups management page. Screenshots should be taken from the live site and placed in `docs/images/talk/` — the ASCII diagrams below show the layout for reference until real screenshots are added.

---

## The Big Picture

`/talk` turns scattered thoughts into concrete proposals — all on one page:

```
Type or speak a thought ──→ AI classifies it (category + tags)
                              │
Toggle AI respond on? ────→ AI brainstorms back
                              │
Stream builds live ────────→ Your ideas + group members' ideas
                              │
Facilitator clicks ────────→ Gather (find connections)
                              │
                         Crystallize (structured proposal)
```

**Two pages:**
- **Talk** (`/talk/`) — the main page: input, AI, stream, everything
- **Groups** (`/talk/groups.php`) — create, discover, and manage groups

**Legacy pages** (still functional):
- Brainstorm (`/talk/brainstorm.php`) — dedicated AI chat
- History (`/talk/history.php`) — filtered/threaded view of past ideas

**Login indicator**: All pages show a green dot + your username when logged in, or a nudge to create an account when anonymous.

---

## 1. Talk — The Unified Page

**URL:** `/talk/` or `/talk/index.php`

Everything happens here: type an idea, AI classifies it, see the stream, brainstorm with AI, edit, promote, gather, crystallize.

### Layout

```
┌─────────────────────────────────────────────┐
│  Talk                         [? Help] [👤] │
├─────────────────────────────────────────────┤
│  ┌─────────────────────────────────────┐    │
│  │ ▼ Personal / Group selector         │    │
│  └─────────────────────────────────────┘    │
│  ┌─────────────────────────────────────┐    │
│  │ What's on your mind?           [🤖] │    │
│  └─────────────────────────────────────┘    │
│  [🎤]                            [Send ➤]  │
├─────────────────────────────────────────────┤
│  ─── Stream ────────────────────────────    │
│  ┌─────────────────────────────────────┐    │
│  │▌ 💡 You              RAW  Feb 15   │    │
│  │▌ Childcare costs are crushing       │    │
│  │▌ families earning $45-55k           │    │
│  │▌ [childcare] [housing] [benefits]   │    │
│  │▌                          ✎ ✕ ⬆    │    │
│  └─────────────────────────────────────┘    │
│  ┌─────────────────────────────────────┐    │
│  │▌ 🤖 AI                             │    │
│  │▌ Good point. CT has the Care4Kids   │    │
│  │▌ program — covers up to $9,600/yr   │    │
│  │▌ for qualifying families...         │    │
│  └─────────────────────────────────────┘    │
│         ── Load more ──                     │
├─────────────────────────────────────────────┤
│ [📊 Gather]  [💎 Crystallize]    (group+fac)│
└─────────────────────────────────────────────┘
```

### How to use it

1. **Choose context** — the dropdown at the top selects Personal (just your ideas) or a group. This is sticky — it remembers your choice between sessions.
2. **Type or speak** your thought in the text box. Tap the mic for voice input.
3. **Press Send** (or Ctrl+Enter / Cmd+Enter) — AI automatically classifies your idea with a category and tags. The card appears in the stream.
4. **AI respond** — toggle the robot icon on to get AI brainstorm replies after each idea. Toggle off and AI just classifies silently.

### Key controls

| Control | What it does |
|---------|-------------|
| **Context dropdown** | Switch between Personal and your groups. Sticky across sessions. |
| **Robot icon** | Toggle AI respond on/off. When on (highlighted), AI brainstorms after each idea. |
| **Mic button** | Tap to speak instead of type. |
| **Send button** | Submit the idea. Ctrl+Enter also works. |

### What the AI does

**Always (silent):**
- Classifies your idea into a category (idea, decision, todo, note, question)
- Assigns 2-5 relevant keyword tags

**When AI respond is on:**
- Brainstorms back after each idea
- Asks follow-up questions, adds data, challenges assumptions
- AI response appears as a purple-tinted card below your idea

### The stream

Ideas appear in reverse-chronological order. Each card shows:

| Element | Meaning |
|---------|---------|
| **Colored left border** | Category: cyan=Idea, green=Decision, orange=Todo, purple=Note, pink=Question |
| **Category + Author** | "💡 You" or "🤖 AI" — who said it and what kind |
| **Clerk badge** | Purple badge on AI-generated cards showing which clerk role created it |
| **Status pill** | RAW → REFINING → DISTILLED → ACTIONABLE (idea maturity) |
| **Tags** | AI-assigned keyword tags |
| **Timestamp** | When it was created |
| **(edited)** | Shows if idea was modified after creation |

### Actions on your cards

| Action | How | What happens |
|--------|-----|-------------|
| **Edit** | Click ✎ on your card | Inline textarea appears — edit, then Save or Cancel |
| **Delete** | Click × on your card | Soft-delete (hidden but preserved for gathered outputs) |
| **Promote** | Click ⬆ | Advance: raw → refining → distilled → actionable |

### Group mode

When you select a group from the context dropdown:

- The stream shows **all group members' ideas**, not just yours
- New ideas from other members appear automatically (polls every 8 seconds)
- Polling pauses when the browser tab is hidden (saves resources)
- If you're a facilitator, the footer bar appears with Gather and Crystallize buttons

### Personal mode

When set to "Personal":
- Only your own ideas appear
- No polling (it's just you)
- No footer bar

### Card types in the stream

| Card | Background | Left border | When it appears |
|------|-----------|-------------|-----------------|
| **Your idea** | Dark (0.06 white) | Category color (cyan, green, orange, etc.) | Every time you submit |
| **AI response** | Purple tint | Solid purple | When AI respond is toggled on |
| **Digest** | Gold tint | Bold gold (4px) | After facilitator runs Gather |
| **Crystallization** | Purple tint | Bold purple (4px) | After facilitator runs Crystallize |

---

## 2. Groups — Create & Manage

**URL:** `/talk/groups.php`

Groups page is for setup and administration. Creating groups, inviting members, managing roles. Once a group exists, daily usage happens on the Talk page.

### List view

```
┌─────────────────────────────────────────────────┐
│  👥 Groups                     Talk  Help       │
├─────────────────────────────────────────────────┤
│  [+ Create Group]                               │
│                                                 │
│  My Groups                                      │
│  ┌───────────────────────────────────────────┐  │
│  │▌ Putnam Housing            [facilitator]  │  │
│  │▌ Affordable housing ideas for Putnam      │  │
│  │▌ ● active   3 members   observable        │  │
│  │▌ [housing] [putnam] [ct]                  │  │
│  └───────────────────────────────────────────┘  │
│  ┌───────────────────────────────────────────┐  │
│  │▌ CT Roads & Infrastructure  [member]      │  │
│  │▌ Statewide road and bridge priorities     │  │
│  │▌ ● active   7 members   open             │  │
│  │▌ [infrastructure] [roads] [ct]            │  │
│  └───────────────────────────────────────────┘  │
│                                                 │
│  Discover                                       │
│  ┌───────────────────────────────────────────┐  │
│  │▌ Putnam Schools                           │  │
│  │▌ School budget and curriculum discussion  │  │
│  │▌ ○ forming   2 members   open            │  │
│  │▌ [education] [putnam]                     │  │
│  └───────────────────────────────────────────┘  │
│                                                 │
└─────────────────────────────────────────────────┘
```

### Creating a group

1. Click **"+ Create Group"** — a form expands
2. Fill in:
   - **Name** (required) — e.g., "Putnam Housing"
   - **Description** — what the group is about
   - **Tags** — for discoverability (e.g., "housing, putnam, ct")
   - **Access level:**
     - **Observable** (default) — anyone can see, only members contribute
     - **Open** — anyone can join and contribute
     - **Closed** — invitation only
3. Click **"Create Group"** — you become the facilitator

### Group detail view

Click any group card to see members, status, and management controls.

The detail view shows:
- Group info (name, description, status, access level)
- Members list with role badges
- **"Open in Talk"** link — takes you to the Talk page with this group selected
- Invite members form (facilitator only)
- Invitation list with status badges

### Inviting members

Facilitators can invite people by email from the group detail page:

1. Enter email addresses — one per line or comma-separated
2. Click **"Send Invites"**
3. Results appear inline:
   - **Green** (Invited) — email sent with accept/decline buttons
   - **Red** (Invalid / No account) — can't invite
   - **Orange** (Already member / Already invited) — skipped

Each invitee receives an email with **"Yes, I'll Join"** and **"No Thanks"** buttons. Clicking either works without logging in. Invitations expire after 7 days.

### Group roles

| Role | Display | Can do |
|------|---------|--------|
| **Facilitator** | 🎯 Group Facilitator | Everything — manage members, send invites, gather, crystallize, archive |
| **Member** | 💬 Group Member | Contribute ideas, view invitations |
| **Observer** | 👁 Group Observer | Read only |

Facilitators can change anyone's role. Multiple facilitators allowed. If the last facilitator leaves, the longest-tenured member is auto-promoted.

---

## 3. Facilitator Workflow

Facilitators guide a group from scattered ideas to a concrete proposal. Here's the typical lifecycle:

```
1. CREATE          Go to Groups page, create group with clear purpose
       ↓
2. INVITE          Send email invites to members
       ↓
3. BRAINSTORM      Members contribute ideas on the Talk page
       ↓              (select the group from context dropdown)
       ↓
4. GATHER          Facilitator clicks Gather in the Talk footer
       ↓              AI finds connections between ideas
       ↓              Creates theme-based digest summaries
       ↓
5. CRYSTALLIZE     Facilitator clicks Crystallize in Talk footer
       ↓              AI produces a structured proposal
       ↓
6. ITERATE         Add more ideas → Re-gather → Re-crystallize
       ↓              Each run improves on the last
       ↓
7. ARCHIVE         Lock the final proposal (from Groups page)
```

### When to gather

Run the gatherer when:
- Several members have contributed ideas (at least 5-10)
- New ideas have been added since the last run
- You want to see emerging themes before crystallizing

The gatherer is **incremental** — safe to run as often as you want.

### When to crystallize

Run crystallization when:
- The group has enough material for a real proposal (usually after gathering)
- Discussion feels like it's converging
- You want a deliverable to share with others

Each crystallization run **improves on the last** — add more ideas, re-gather, re-crystallize.

### Staleness warnings

If someone edits or deletes an idea after a gather or crystallize ran, an orange warning appears. Re-run the relevant tool to incorporate changes.

---

## 4. Legacy Pages

The old dedicated pages still work and are linked from the Talk page:

### Brainstorm (`/talk/brainstorm.php`)

Dedicated AI chat interface. Full back-and-forth conversation with the AI, which automatically captures ideas from your dialogue. Still useful for deep 1-on-1 brainstorming sessions.

### History (`/talk/history.php`)

Full-featured idea archive with:
- Category and status filters
- Flat and threaded views
- Share checkbox per idea
- Personal Gather and Crystallize buttons

Still useful for detailed filtering and threaded conversation views.

---

## Quick Reference

| I want to... | Go to |
|--------------|-------|
| Dump a quick thought | Talk (`/talk/`) |
| Brainstorm with AI | Talk — toggle AI respond on |
| Review my past ideas | Talk — scroll the stream or Load More |
| Edit or delete an idea | Talk — click ✎ (edit) or × (delete) on your card |
| Collaborate with a group | Talk — select group from context dropdown |
| Create or manage a group | Groups (`/talk/groups.php`) |
| Invite people to a group | Groups → click group → Invite Members |
| Turn ideas into a proposal | Talk (group mode) → Gather → Crystallize |
| Use advanced filters/threads | History (`/talk/history.php`) |
| Get help | Help (`/talk/help.php`) |

---

## Adding Screenshots

To complete this walkthrough with real screenshots:

1. Create directory: `docs/images/talk/`
2. Take screenshots of each page state:
   - `talk-personal-empty.png` — blank Talk page in personal mode
   - `talk-personal-stream.png` — personal mode with ideas in stream
   - `talk-group-stream.png` — group mode with multiple members' ideas
   - `talk-ai-respond.png` — idea with AI response card below
   - `talk-voice-active.png` — mic button active (red)
   - `talk-footer-facilitator.png` — gather/crystallize footer bar
   - `groups-list.png` — groups list with My Groups + Discover
   - `groups-create.png` — create form expanded
   - `groups-detail.png` — group detail with members + invites
3. Reference them in this doc: `![Talk Stream](images/talk/talk-personal-stream.png)`
