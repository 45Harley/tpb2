# /talk Phase 3 — Seeds

> **Superseded by [talk-architecture.md](talk-architecture.md)** (2026-02-13).
> The open questions below were answered during the Phase 3 design session.
> This file is kept for historical reference.

**Pre-brainstorm notes captured 2026-02-12**

## The Vision (from conversation)

/talk is not a personal note-taking app. It's a collective deliberation tool.

### The Pipeline

1. **Scatter** — Many people dump raw thoughts (Quick Thought, Brainstorm chat)
2. **Gather** — Related thoughts from different people cluster together by theme
3. **Refine** — The group iteratively sharpens those clusters through discussion
4. **Crystallize** — Refined ideas become concrete proposals the collective agrees on

### What exists (Phase 1 + 2)

- Step 1 works: Quick Thought captures fast, Brainstorm chat captures with AI help
- History page shows flat list with filters
- Promote buttons change status labels but don't facilitate actual refinement
- `parent_id` and `link` action exist in API but nothing uses them in UI

### What's missing

- **Gathering**: No way to group related thoughts across users by theme
- **Refining**: No conversational thread where people react/build/challenge a cluster
- **Consensus**: No mechanism for collective agreement on refined ideas
- The status pipeline (raw -> refining -> distilled -> actionable) has no tooling — it's just manual label changes

### Key insight

The refinement step is a **social process**, not an individual one. Tom's thought about property taxes connects to Maria's thought about school funding. The system (or AI, or a facilitator) surfaces that connection, and the group works it into something concrete together.

## Open questions for brainstorm session

- Who does the gathering — AI auto-clustering, manual linking, facilitator, or all three?
- What does a "thread" look like when it's cross-user?
- How does consensus work — voting, reactions, facilitator calls it?
- Does the brainstorm clerk gain new powers (LINK, CLUSTER) or is this a new clerk?
- What's the minimal Phase 3 that makes the pipeline real?

## Answers (from Phase 3 design session, 2026-02-13)

- **Who gathers?** All three: AI auto-clustering (gatherer clerk), manual linking (users), facilitator curation. See `idea_links` table in architecture doc.
- **Cross-user threads?** Same tree structure via `parent_id`. Both users and AI are first-class entities — either can be parent or child.
- **Consensus?** Not yet specified. Status pipeline (raw → actionable) + group membership + moderation. Voting/reactions TBD.
- **New clerk or new powers?** Multiple clerks: brainstorm (responder), gatherer (linker/summarizer), moderator, resolver. Each with distinct capabilities.
- **Minimal Phase 3?** `idea_links` table + AI as separate nodes (not `ai_response` column) + groups. See schema evolution in architecture doc.
- **Data model?** Nodes (`idea_log`) + Trees (`parent_id`) + Networks (`idea_links`) + Groups (`idea_groups`). Full graph, not just trees.

## Also pending (from original notes)

- CSS fix for index.php (justify-content overflow) — committed in 9b8d603
