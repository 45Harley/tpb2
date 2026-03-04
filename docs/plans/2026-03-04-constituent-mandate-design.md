# Constituent Mandate — Design Doc

**Date:** 2026-03-04
**Status:** Approved concept

## What It Is

Citizens tell their representatives what they want done — privately, in their own words. AI aggregates everyone's items into a public **Constituent Mandate** summary targeted at reps at each level of government.

## How It Works

### Per-User: Private Stream

One stream per user. Each item is optionally tagged with a mandate level:

- **no mandate level** — regular private idea (unchanged from today)
- **mandate-federal** — included in Constituent Mandate for your congressional district
- **mandate-state** — included in Constituent Mandate for your state
- **mandate-town** — included in Constituent Mandate for your town

Setting a mandate category = opting to share that item with reps. Leaving it blank keeps it private. One gesture.

Users can:
- **Add** — type or speak (via Claudia/mic) a mandate item with a level
- **List** — view their own mandate items (all levels in one stream)
- **Edit** — modify any item
- **Delete** — remove any item (e.g., "delete #14")
- **Display/speak** — Claudia reads back your list

### AI Refining Dialog (Optional)

Each mandate item can optionally go through a refining conversation with Claudia:

1. **User** drops a raw idea
2. **Claudia** asks clarifying questions (if user wants)
3. **User** refines
4. **Claudia** proposes final wording
5. **User commits** — that version goes into the mandate
6. User can **edit/reopen** anytime — restarts the dialog

The committed version is what the aggregator reads. The refining dialog lives as child nodes (parent/child threading already in `idea_log`).

### Tone Tagging

AI auto-tags tone/sentiment on each mandate item:
- urgent, frustrated, supportive, concerned, neutral

Feeds into aggregation: not just *what* people want, but *how strongly they feel*.

### Per-District: AI-Aggregated Public Summary

AI reads all users' mandate-tagged items within a geographic scope and produces a public summary:

> **Constituent Mandate for CT-2**
> 247 constituents have spoken. Top priorities:
> 1. Trade bill opposition (83 mentions, mostly frustrated)
> 2. Term limits (61, urgent)
> 3. Veterans benefits expansion (44, supportive)
> ...

Three summaries per user's location:
- **Constituent Mandate for [Town]** — town-level items
- **Constituent Mandate for [State]** — state-level items
- **Constituent Mandate for [District]** — federal-level items

### Re-Aggregation Over Time

The mandate isn't static. Each aggregation run captures the current collective will. Aggregation history shows how priorities shift:

- January: *Trade bill opposition — 83 mentions*
- March: *Trade bill opposition — 41 mentions* (cooled off)
- March: *Healthcare costs — 112 mentions* (surged)

The trend over time is the story of what a district cares about. Reps can't say "I didn't know."

## UI Design

### Two Complete Interfaces

Every action works through **both** interfaces — same data underneath:

1. **Visual (keyboard/mouse)** — talk stream include for the screen
2. **Voice (mic/speaker)** — C widget / Claudia for eyes-free use

A user can do everything with just a keyboard, or just their voice, or both.

### Page Structure

Standalone POC page (`mandate-poc.php`) — does not modify existing codebase. Includes:

1. **Phone login** (if not already authenticated via cookie)
2. **Talk stream** configured for personal mode with mandate categories
3. **C widget** wired for mandate voice commands
4. **Public mandate summary** section (read-only)

### Visual Interface (Talk Stream)

Uses `includes/talk-stream.php` configured for mandate mode:
- Input area with mic button and AI toggle
- Category selector includes: mandate-federal, mandate-state, mandate-town
- Stream displays user's own items only (personal mode)
- Each item shows its mandate level tag
- Edit/delete inline
- TTS playback button per item (new — talk stream doesn't have this yet)

### Voice Interface (C Widget / Claudia)

Full verbal command set:

**Login/Logout:**
- *"Claudia, this is 8-0-3-9-8-4-1-8-2-7"* → confirm → "Mandate ready"
- *"Claudia, log me out"* → clears localStorage → "Done"

**Add:**
- *"Add to my federal mandate: vote no on the trade bill"*
- *"Mandate town: fix Route 44 potholes"*
- *"Add: expand childcare subsidies"* → AI auto-detects level

**List:**
- *"Read me my mandate"* (all levels)
- *"List my federal mandate"*
- *"List my town mandate"*

**Edit:**
- *"Edit number 3: change to repave Route 44"*
- *"Change number 7 in my state mandate"*
- *"Move number 3 to federal"* (change level)

**Delete:**
- *"Delete number 5"*
- *"Delete number 14 from my federal mandate"*

**Refine:**
- *"Help me refine number 7"* → starts back-and-forth dialog

**Public Summary:**
- *"What does CT-2 want?"*
- *"Read the Constituent Mandate for Putnam"*
- *"What's trending in my district?"*
- *"How many people in Putnam have spoken?"*

### Phone Login Flow

Phone number stored in `user_identity_status.phone` (already exists).

1. Enter/speak phone number digit by digit
2. Claudia repeats back: *"I heard 8-0-3-9-8-4-1-8-2-7, is that correct?"*
3. **Yes** → save to localStorage → DB lookup
4. **No** → *"Please say your number again"* (3 attempts max, then quit)
5. **Single match** → logged in → "Mandate ready"
6. **Multiple matches** → *"I found multiple accounts. What's your name?"* → match first_name → log in
7. **Still ambiguous** → *"Please log in another way"*
8. **No match** → *"I don't recognize that number"*

## Implementation: Minimal Changes

### Database

No schema changes needed. The existing `idea_log.category` column (varchar 50) gains three new values:
- `mandate-federal`
- `mandate-state`
- `mandate-town`

Alongside existing values: `idea`, `decision`, `todo`, `note`, `question`.

Category = private. Mandate category = shared with reps.

Tone stored in `idea_log.tags` (JSON) alongside existing auto-tags.

Phone number already exists in `user_identity_status.phone`.

### AI Aggregation

New cron job or on-demand action:
- Query all `idea_log` rows with `mandate-*` categories, grouped by user's geo scope
- Claude summarizes into ranked priorities with counts and tone
- Store each aggregation run with timestamp (tracks shifts over time)
- Could use `idea_log` with `clerk_key = 'mandate-aggregator'` or a dedicated table

### POC Scope

Standalone page — no existing files modified:
- `mandate-poc.php` at project root
- Requires `includes/talk-stream.php` and `includes/c-widget.php`
- Uses existing `talk/api.php` endpoints
- Phone login is POC-only (localStorage + DB lookup)
- Public mandate summary is read-only section on same page

## What This Builds On

- **Talk Personal mode** — private stream already exists (group_id = NULL)
- **`includes/talk-stream.php`** — reusable stream component with mic, AI toggle
- **`includes/c-widget.php`** — voice in/out, AI dialog, TTS
- **`talk/api.php`** — save, list, edit, delete, AI respond already built
- **Parent/child threading** — refining dialog fits existing `idea_log.parent_id`
- **Geo context** — user's state_id, town_id, us_congress_district in profile
- **Phone number** — already in `user_identity_status.phone`

## What's New

1. Three new category values on `idea_log`
2. Tone/sentiment auto-tagging
3. AI refining dialog for mandate items (opt-in)
4. AI aggregation endpoint (reads across users, produces public summary)
5. Aggregation history (tracks collective shifts over time)
6. Public Constituent Mandate summary view
7. Claudia voice commands for mandate CRUD + login/logout
8. Phone-number-based access flow for mobile
9. TTS playback button in talk stream

## Title Convention

- Personal view: **"My Mandate"**
- Public summary: **"Constituent Mandate for [Location/District]"**
