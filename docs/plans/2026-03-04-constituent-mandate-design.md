# Constituent Mandate — Design Doc

**Date:** 2026-03-04
**Status:** Approved concept

## What It Is

Citizens tell their representatives what they want done — privately, in their own words. AI aggregates everyone's items into a public **Constituent Mandate** summary targeted at reps at each level of government.

## How It Works

### Per-User: Private Stream

Users add items to their existing Talk Personal stream. Each item can optionally have a **mandate level**:

- **blank/null** — regular private idea (unchanged from today)
- **federal** — shared with your congressional delegation (district-scoped)
- **state** — shared with your state legislators
- **town** — shared with your town/city officials

Setting a mandate level = opting to share that item with reps. Leaving it blank keeps it private. One gesture.

Users can:
- **Add** — type or speak (via Claudia/mic) a mandate item with a level
- **List** — view their own mandate items (all levels in one stream)
- **Edit** — modify any item
- **Delete** — remove any item (e.g., "delete #14")
- **Display/speak** — Claudia reads back your list

### Per-District: AI-Aggregated Public Summary

AI reads all users' mandate-tagged items within a geographic scope and produces a public summary:

> **Constituent Mandate for CT-2**
> 247 constituents have spoken. Top priorities:
> 1. Trade bill opposition (83 mentions)
> 2. Term limits (61)
> 3. Veterans benefits expansion (44)
> ...

Three summaries per user's location:
- **Constituent Mandate for [Town]** — town-level items
- **Constituent Mandate for [State]** — state-level items
- **Constituent Mandate for [District]** — federal-level items

## Implementation: Minimal Changes

### Database

One new column on `idea_log`:

```sql
ALTER TABLE idea_log ADD COLUMN mandate_level ENUM('federal','state','town') DEFAULT NULL;
```

- `NULL` = private (existing behavior unchanged)
- Any value = included in mandate aggregation for that level

No new tables needed.

### UI Changes

1. **Talk input area** — add a mandate level selector (dropdown or three buttons: Federal / State / Town). Default: blank (private).
2. **Mandate view** — filter personal stream to show only mandate-tagged items. Could be a tab or filter button on Talk page.
3. **Public mandate summary page** — read-only view of AI-aggregated mandate for a given scope/geo. Accessible to anyone.

### AI Aggregation

New API action or cron job:
- Query all `idea_log` rows where `mandate_level = 'federal'` grouped by user's federal district
- Claude summarizes into ranked priorities with counts
- Store summary for display (could be a special `idea_log` entry with `clerk_key = 'mandate-aggregator'`, or a separate table)

### Claudia (C Widget) Integration

Voice commands:
- *"Add to my federal mandate: vote no on the trade bill"*
- *"Read me my mandate"*
- *"Delete number 3 from my state mandate"*

### Phone Access Flow

1. Enter/speak phone number digit by digit
2. Claudia repeats back for confirmation (yes/no)
3. Three attempts max, then quit
4. Phone stored in localStorage
5. DB lookup matches phone to user
6. "Mandate ready" — user can start adding

## What This Builds On

- **Talk Personal mode** — private stream already exists (group_id = NULL)
- **`includes/talk-stream.php`** — reusable stream component with mic, AI toggle
- **`talk/api.php`** — save, list, edit, delete actions already built
- **Geo context** — user's state_id, town_id already in profile
- **Claudia** — voice input/output already built in C widget

## What's New

1. `mandate_level` column on `idea_log`
2. Mandate level selector in Talk UI
3. AI aggregation endpoint (reads across users, produces public summary)
4. Public Constituent Mandate summary page
5. Claudia voice commands for mandate CRUD
6. Phone-number-based access flow for mobile

## Title Convention

- Personal view: **"My Mandate"**
- Public summary: **"Constituent Mandate for [Location/District]"**
