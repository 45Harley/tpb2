# /talk Visual Walkthrough

**A step-by-step guide to using TPB's collective deliberation system.**

This walkthrough covers all four pages: Quick Capture, Brainstorm, History, and Groups. Screenshots should be taken from the live site and placed in `docs/images/talk/` — the ASCII diagrams below show the layout for reference until real screenshots are added.

---

## The Big Picture

`/talk` turns scattered thoughts into concrete proposals through four stages:

```
You have a thought ──→ Quick Capture (dump it fast)
                              │
Want to go deeper? ───→ Brainstorm (chat with AI)
                              │
Review what you said ──→ History (filter, promote, edit, share)
                              │
Work with others ──────→ Groups (deliberate, crystallize)
                              │
                        Proposal.md (the deliverable)

Need help? ────────────→ Help / FAQ (guides + Ask AI)
```

**Login indicator**: All pages show a green dot + your username when logged in, or a nudge to create an account when anonymous.

---

## 1. Quick Capture — Say It and Go

**URL:** `/talk/` or `/talk/index.php`

This is the fastest on-ramp. Got a thought? Speak it or type it. Done in 10 seconds.

```
┌─────────────────────────────────────────────────┐
│  💡 Quick Thought          🧠 Brainstorm        │
│                            👥 Groups             │
│                            📚 History            │
├─────────────────────────────────────────────────┤
│     [Personal (no group) ▾]  ← group selector   │
│                                                 │
│        Tap mic to speak, or type below          │
│                                                 │
│                 ┌──────┐                        │
│                 │  🎤  │  ← Big mic button      │
│                 │      │    (turns red when      │
│                 └──────┘     listening)          │
│                                                 │
│  ┌─────────────────────────────────────────┐    │
│  │ What's on your mind?                    │    │
│  │                                         │    │
│  │                                         │    │
│  └─────────────────────────────────────────┘    │
│                                                 │
│  [💡 Idea] [✅ Decision] [📋 Todo]              │
│  [📝 Note] [❓ Question] [↩️ Reaction]           │
│                                                 │
│  ┌─────────────────────────────────────────┐    │
│  │           Save Thought                  │    │
│  └─────────────────────────────────────────┘    │
│                                                 │
└─────────────────────────────────────────────────┘
```

### How to use it

1. **Tap the mic** or just start typing in the text box
2. **Pick a category** — "Idea" is selected by default. Use "Question" if it's a question, "Todo" if it's an action item, etc.
3. **Choose a group** (optional) — logged-in users see a dropdown to assign the idea to a group, or leave it as "Personal (no group)"


### Tips
- **Voice works great** for capturing thoughts on the go — just tap the mic, speak naturally, and it fills in the text
- Thoughts are **private by default** — nobody sees them until you mark them shareable
- **Group selector** — choose which group your idea belongs to. Ideas saved to a group are automatically shareable. Ideas stay personal by default.
- **Ctrl+Enter** (or Cmd+Enter on Mac) submits without clicking the button
- The "Reaction" category is grayed out here — it activates when you're replying to someone else's thought
- **Log in to keep your thoughts.** Anonymous thoughts are tied to your browser tab — close it and you can't find them again. Logged-in thoughts are tied to your account and persist forever.

---

## 2. Brainstorm — Think With AI

**URL:** `/talk/brainstorm.php`

This is where you go deeper. It's a chat — you talk, the AI responds, and it **automatically captures the good ideas** from your conversation.

```
┌─────────────────────────────────────────────────┐
│  🧠 Brainstorm   [Personal ▾]  □ Shareable     │
│                        Groups  Quick  History    │
├─────────────────────────────────────────────────┤
│                                                 │
│              Let's think together               │
│    Share an idea, question, or problem.         │
│    I'll brainstorm with you and capture         │
│    the good stuff.                              │
│                                                 │
│                                                 │
│                                                 │
│                                                 │
│                                                 │
│                                                 │
│                                                 │
│                                                 │
├─────────────────────────────────────────────────┤
│  [🎤]  │ What's on your mind?          │  [➤]  │
└─────────────────────────────────────────────────┘
```

After a few exchanges, it looks like this:

```
┌─────────────────────────────────────────────────┐
│  🧠 Housing Committee  [Housing ▾]  ■ Shareable│
├─────────────────────────────────────────────────┤
│                                                 │
│                    Childcare in Putnam is        │
│                    way too expensive. Families   │
│                    can't afford it.     [YOU ──] │
│                                                 │
│  [── AI]  That's a real concern. CT has the     │
│           Care4Kids program — it covers up to   │
│           $9,600/year for qualifying families.   │
│           What income level are you thinking    │
│           about?                                │
│                                                 │
│          ┌─ 💡 Idea #42 captured ─┐             │
│          └────────────────────────┘             │
│                                                 │
│                    Most families I know make     │
│                    around $45-55k. Do they      │
│                    qualify?               [YOU] │
│                                                 │
│  [AI]    Yes! Care4Kids covers families up to   │
│          75% of state median income...          │
│                                                 │
├─────────────────────────────────────────────────┤
│  [🎤]  │ Type here...                  │  [➤]  │
└─────────────────────────────────────────────────┘
```

### How to use it

1. **Type or speak** your thought in the input bar at the bottom
2. **Press Enter** (or click the send arrow) to send
3. The AI responds — it asks follow-up questions, adds data, challenges assumptions
4. **Ideas get captured automatically** — you'll see green system messages like "💡 Idea #42 captured" when the AI distills a clean idea from your conversation
5. Keep going as long as you want. The AI handles the bookkeeping.

### Key controls

| Control | What it does |
|---------|-------------|
| **Group dropdown** | Switch between "Personal" (just you + AI) and a group (AI sees everyone's shared thoughts) |
| **Shareable toggle** | When ON (green), your thoughts in this session are visible to your groups |
| **Mic button** | Tap to speak instead of type — works the same as Quick Capture's mic |

### What the AI does behind the scenes

The AI isn't just chatting — it's working:
- **SAVE_IDEA** — When it hears a good idea, it saves a clean version (you see "💡 Idea #42 captured")
- **TAG_IDEA** — It tags saved ideas with relevant topics (you see "🏷️ Tagged #42: housing, childcare")
- **READ_BACK** — Ask "what have I said so far?" and it lists your session's ideas
- **SUMMARIZE** — It can create a digest of your entire session

### Group brainstorming

When you select a group from the dropdown:
- The AI sees **all shareable thoughts from group members**, not just yours
- It makes connections: "Tom mentioned something similar about senior housing — your childcare concern ties into his affordability point"
- The header changes to show the group name (e.g., "🧠 Housing Committee")
- You can also get here by clicking "🧠 Brainstorm in this group" from the Groups page

---

## 3. History — Review and Refine

**URL:** `/talk/history.php`

Everything you've said (and what the AI captured) lives here. Filter it, promote it, share it, or follow conversation threads.

```
┌─────────────────────────────────────────────────┐
│  📚 Thought History           Show all          │
│                     👥 Groups  🧠 Brainstorm    │
├─────────────────────────────────────────────────┤
│  [All] [💡 Ideas] [✅ Decisions] [📋 Todos]     │
│  [📝 Notes] [❓ Questions] [💬 Chat] [📊 Digest]│
│                                                 │
│  [All Status] [Raw] [Refining] [Distilled]      │
│  [Actionable]                                   │
│                                                 │
│  View: [Flat] [Threaded]                        │
├─────────────────────────────────────────────────┤
│                                                 │
│  ┌───────────────────────────────────────────┐  │
│  │▌💡 You                    RAW   Feb 14   │  │
│  │▌                                         │  │
│  │▌ Childcare in Putnam costs $15k/year     │  │
│  │▌ for a family with two kids. Care4Kids   │  │
│  │▌ covers up to $9,600 for qualifying      │  │
│  │▌ families.                               │  │
│  │▌                                         │  │
│  │▌ via web    2 builds →    □ Share  ⬆ ref │  │
│  └───────────────────────────────────────────┘  │
│                                                 │
│  ┌───────────────────────────────────────────┐  │
│  │▌💬 AI  [brainstorm]       RAW   Feb 14   │  │
│  │▌                                         │  │
│  │▌ Great point. The gap between $15k cost  │  │
│  │▌ and $9,600 coverage means families      │  │
│  │▌ still pay $5,400 out of pocket...       │  │
│  │▌                                         │  │
│  │▌ builds on #42                           │  │
│  └───────────────────────────────────────────┘  │
│                                                 │
└─────────────────────────────────────────────────┘
```

### Personal tools

Logged-in users see **Gather** and **Crystallize** buttons at the top of the History page:

```
Personal: [📊 Gather] [💎 Crystallize]
```

- **Gather** — runs the AI gatherer on just your personal ideas (not group ideas), finding thematic connections
- **Crystallize** — produces a structured personal proposal from your ideas and gatherer digests

### Reading the cards

Each thought card shows:

| Element | Meaning |
|---------|---------|
| **Colored left border** | Category: cyan=Idea, green=Decision, orange=Todo, purple=Note, pink=Question, gray=Chat |
| **Category + Author** | "💡 You" or "💬 AI" — who said it and what kind |
| **[brainstorm] badge** | Purple badge = AI-generated, shows which clerk role created it |
| **Group badge** | Blue pill showing which group the idea belongs to (absent for personal ideas) |
| **Status pill** | RAW → REFINING → DISTILLED → ACTIONABLE (the idea's maturity) |
| **Timestamp** | When it was created |
| **(edited)** | Shows if idea was modified after creation, with tooltip showing edit count + last edit time |
| **"builds on #42"** | This thought is a reply to thought #42 (click to jump) |
| **"2 builds →"** | 2 other thoughts reply to this one (click to see the thread) |

### Actions you can take

| Action | How | What happens |
|--------|-----|-------------|
| **Filter by category** | Click a category button (e.g., "💡 Ideas") | Only shows thoughts of that type |
| **Filter by status** | Click a status button (e.g., "Distilled") | Only shows thoughts at that maturity level |
| **Switch view** | Click "Flat" or "Threaded" | Flat = newest first; Threaded = conversation tree with indentation |
| **View a thread** | Click "2 builds →" on a card | Shows just that root idea and all its replies |
| **Share a thought** | Check the "□ Share" checkbox | Makes this thought visible to your groups |
| **Promote status** | Click "⬆ ref" button | Advances the idea: raw → refining → distilled → actionable |
| **Edit a thought** | Click ✎ (pencil) on your own card | Inline textarea appears — edit, then Save or Cancel. Edit count tracked. |
| **Delete a thought** | Click × on your own card | Soft-deletes by default (hidden but preserved). If ungathered, offers permanent delete. |
| **Show all** | Click "Show all" link in header | See everyone's shared thoughts, not just yours |

**Note**: Edit and delete buttons only appear on your own human-authored thoughts — not on AI-generated nodes.

### Threaded view

Switching to "Threaded" shows conversation trees with indentation:

```
  💡 You: "Fix the roads"                          RAW
    └─ 💬 AI [brainstorm]: "Which roads?"
         └─ 💡 You: "Main St has the worst potholes"
              └─ 💬 AI: "Main St gets heavy truck traffic..."
    └─ 💡 User-B: "The bridge is the real priority"
         └─ 💬 AI: "Engineering assessment says..."
```

Each reply is indented and connected with a visual line to its parent.

---

## 4. Groups — Deliberate Together

**URL:** `/talk/groups.php`

Groups are where individual thoughts become collective proposals. A group is 2+ people whose shared thoughts flow through the AI pipeline together.

### List view

```
┌─────────────────────────────────────────────────┐
│  👥 Groups       🧠 Brainstorm  📚 History      │
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

Click any group card to see its detail page:

```
┌─────────────────────────────────────────────────┐
│  ← All groups                                   │
├─────────────────────────────────────────────────┤
│                                                 │
│  Putnam Housing                                 │
│  Affordable housing ideas for Putnam            │
│  ● active   3 members   observable  [facilit.]  │
│  [housing] [putnam] [ct]                        │
│                                                 │
│  [🧠 Brainstorm in this group]                  │
│  [🔗 Run Gatherer] [💎 Crystallize]  [Leave]    │
│                                                 │
├─────────────────────────────────────────────────┤
│  Members                                        │
│  ┌────────┐ ┌────────┐ ┌────────┐              │
│  │You fac.│ │Maria m.│ │Tom  m. │              │
│  └────────┘ └────────┘ └────────┘              │
│        (facilitator can change roles ↑)         │
│                                                 │
├─────────────────────────────────────────────────┤
│  Group Ideas (8)                            │
│  ┌───────────────────────────────────────────┐  │
│  │ Maria: Childcare costs are crushing       │  │
│  │ families earning $45-55k...               │  │
│  │ 💡 idea   raw   🔗 2 links               │  │
│  └───────────────────────────────────────────┘  │
│  ┌───────────────────────────────────────────┐  │
│  │ Tom: Senior housing waitlist is 18        │  │
│  │ months. That's unacceptable...            │  │
│  │ 💡 idea   refining   🔗 1 link           │  │
│  └───────────────────────────────────────────┘  │
│  ...                                            │
│                                                 │
└─────────────────────────────────────────────────┘
```

### Inviting members

Facilitators can invite people by email directly from the group detail page:

1. Scroll to the **"Invite Members"** section (facilitator-only)
2. Enter email addresses — one per line or comma-separated
3. Click **"Send Invites"**
4. Results appear inline with color-coded status for each email:
   - **Green** (Invited) — email sent with accept/decline buttons
   - **Red** (Invalid email / No account found) — can't invite
   - **Orange** (Already a member / Already invited / Email not verified) — skipped

Each invitee receives an email with two buttons: **"Yes, I'll Join"** (green) and **"No Thanks"** (gray). Clicking either link works without logging in — the token authenticates the response.

The **Invitations** list (visible to members and facilitators, not observers) shows all invites with status badges:
- **Pending** (orange) — waiting for response
- **Accepted** (green) — joined the group
- **Declined** (red) — chose not to join
- **Expired** (gray) — 7-day window passed with no response

Facilitators can re-invite someone who declined (creates a new invitation).

### The facilitator toolkit

If you're a facilitator, you have special powers:

| Button | What it does | When to use it |
|--------|-------------|----------------|
| **Send Invites** | Enter email addresses, system validates and sends accept/decline emails | To add members to closed groups (or any group) |
| **Activate Group** | Changes status from "forming" to "active" | After members have joined and you're ready to start |
| **🔗 Run Gatherer** | AI scans all shared ideas, finds connections, creates digest summaries | After members have shared several ideas — run it periodically |
| **💎 Crystallize** | AI produces a structured proposal (.md document) from all the group's ideas, links, and digests | When the group has enough material for a proposal |
| **💎 Re-Crystallize** | Runs crystallization again, improving on the previous draft | After new ideas are added or to refine the proposal |
| **📦 Archive** | Locks the final crystallization as the definitive result | When the proposal is final |
| **🔓 Reopen** | Returns an archived/crystallized group back to active | If more work is needed |
| **⚠️ Stale banner** | Warning appears automatically when source ideas changed since last gather/crystallize | Re-run gatherer or re-crystallize to update |

### The deliberation flow

Here's the typical lifecycle of a group:

```
1. CREATE          Facilitator creates the group, sets purpose
       ↓
2. INVITE          Facilitator sends email invites → accept/decline
       ↓
3. BRAINSTORM      Members brainstorm (individually or together)
       ↓              Each marks their good ideas "shareable"
       ↓
4. GATHER          Facilitator clicks "Run Gatherer"
       ↓              AI finds connections between ideas
       ↓              AI creates theme summaries (digests)
       ↓
5. CRYSTALLIZE     Facilitator clicks "Crystallize"
       ↓              AI produces a structured proposal
       ↓              Saved as .md file + database record
       ↓
6. ITERATE         Add more ideas → Re-gather → Re-crystallize
       ↓              Each run improves on the last
       ↓
7. ARCHIVE         Facilitator locks the final proposal
```

### Group roles

| Role | Display | Can do |
|------|---------|--------|
| **Facilitator** | 🎯 Group Facilitator | Everything — manage members, send invites, run gatherer, crystallize, archive/reopen |
| **Member** | 💬 Group Member | Brainstorm, share ideas, participate in discussion, view invitations |
| **Observer** | 👁 Group Observer | Read only — can see shared ideas but can't contribute or see invitations |

Facilitators can change anyone's role using the dropdown on their member chip. The group creator is the first facilitator. Multiple facilitators are allowed.

### Staleness warnings

If someone edits or deletes an idea after a gatherer or crystallization has run, the outputs become **stale** — they no longer reflect the current state of the group's ideas. Facilitators see an orange warning banner:

```
⚠️ Some outputs are stale — 2 source idea(s) changed since Feb 14.
    Re-run gatherer or re-crystallize to update.
```

This cascades: if a source idea is edited, both the gather digest AND any crystallization built on it are flagged stale.

### Recursive groups (groups of groups)

Groups can have **sub-groups**. This enables scaling:

```
Parent: "CT Housing" (state-level)
  ├── Child: "Putnam Housing" → crystallizes → putnam-proposal.md
  ├── Child: "Bridgeport Housing" → crystallizes → bridgeport-proposal.md
  └── Child: "Hartford Housing" → crystallizes → hartford-proposal.md
                                        ↓
When parent crystallizes, the AI reads all child proposals
and weighs them by contributor count:
  "Bridgeport's proposal (backed by 15 people) carries more
   weight than Putnam's (3 people), but both are heard."
                                        ↓
                              ct-housing-proposal.md
```

---

## Navigation Map

Every page links to every other page:

```
                    ┌────────────────┐
            ┌──────│  Quick Capture  │──────┐
            │      │  /talk/         │      │
            │      └───────┬────────┘      │
            │              │               │
            ▼              ▼               ▼
  ┌──────────────┐  ┌────────────┐  ┌──────────┐
  │  Brainstorm  │  │  History   │  │  Groups  │
  │  /talk/      │  │  /talk/    │  │  /talk/  │
  │  brainstorm  │◄─┤  history   ├─►│  groups  │
  │              │  │            │  │          │
  └──────┬───────┘  └────────────┘  └────┬─────┘
         │                               │
         └───── "Brainstorm in ──────────┘
                 this group"
```

---

## Quick Reference

| I want to... | Go to |
|--------------|-------|
| Dump a quick thought | Quick Capture (`/talk/`) |
| Think deeper with AI | Brainstorm (`/talk/brainstorm.php`) |
| Review my past thoughts | History (`/talk/history.php`) |
| Edit or delete a thought | History → ✎ (edit) or × (delete) on your card |
| Collaborate with others | Groups (`/talk/groups.php`) |
| Find ideas by topic | History → filter by category or use tags |
| See what my group is thinking | Groups → click group → shareable ideas |
| Turn ideas into a proposal | Groups → Run Gatherer → Crystallize |
| Run AI on my personal ideas | History → Personal: Gather / Crystallize buttons |
| Mark something important | History → Promote (raw → refining → distilled → actionable) |
| Let my group see my thought | History → check "Share" on the thought |
| Get help or learn more | Help / FAQ (`/talk/help.php`) |

---

## Adding Screenshots

To complete this walkthrough with real screenshots:

1. Create directory: `docs/images/talk/`
2. Take screenshots of each page state:
   - `quick-capture-empty.png` — blank Quick Capture page
   - `quick-capture-listening.png` — mic active (red, pulsing)
   - `brainstorm-welcome.png` — initial welcome state
   - `brainstorm-conversation.png` — mid-conversation with system messages
   - `brainstorm-group-mode.png` — group selected in dropdown
   - `history-flat.png` — flat view with mixed categories
   - `history-threaded.png` — threaded view showing reply chains
   - `history-filters.png` — with category/status filters active
   - `groups-list.png` — list view with My Groups + Discover
   - `groups-create.png` — create form expanded
   - `groups-detail.png` — group detail with members + ideas
   - `groups-crystallize.png` — after crystallization (success message)
3. Reference them in this doc: `![Quick Capture](images/talk/quick-capture-empty.png)`
