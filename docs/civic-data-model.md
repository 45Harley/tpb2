# Civic Data Model: Derivatives

**Living document — last updated 2026-03-12**

## Overview

TPB's civic content follows a two-level derivative model. Raw civic input enters the system from multiple sources, flows through a deliberation pipeline, and produces distilled civic output.

## First Derivatives (Raw Civic Input)

Two separate systems feed civic content into TPB:

### 1. User Civic Input (Ideas + Thoughts → unified `idea_log`)

User-generated civic content. Ideas and Thoughts share the same fundamental shape — content with jurisdiction, tags, votes, and geographic hierarchy (town → state → USA).

| Type | Source | Tagger | Table | Input Path |
|------|--------|--------|-------|------------|
| **Ideas** | User (via Talk) | AI classification + user | `idea_log` | `/talk/`, Claudia widget |
| **Thoughts** | User (town/state/national pages) | User-selected category | `user_thoughts` | Town pages, `voice.php`, `thought.php` |

**Jurisdiction tagging rule**: If the user sets jurisdiction, use that. Otherwise default from user context (their town, their state).

**Table unification**: `user_thoughts` is conceptually a subset of `idea_log`. The only unique fields thoughts have are jurisdiction/branch flags (`jurisdiction_level`, `is_legislative`, `is_executive`, `is_judicial`). Adding those columns to `idea_log` would allow merging into one table. The `source` column already distinguishes origin (user, voice, clerk, etc.).

### 2. Threat System (separate — `executive_threats` + related tables)

**Threats are a separate system. No convergence with idea_log.**

AI-scraped from trusted outside sources, AI auto-tagged, with domain-specific fields (severity_score, official_id, source_url, action_script, threat_type). Threats feed polls — that's their pipeline.

| Table | Purpose |
|-------|---------|
| `executive_threats` | Core threat records (title, description, severity, branch, category, source_url, official_id) |
| `threat_tags` + `threat_tag_map` | Normalized tag system |
| `threat_ratings` | User ratings on threats |
| `threat_responses` | User responses to threats |

**Pipeline**: `collect-threats-local.bat` → AI scrape + classify → INSERT threats → auto-generate polls for high-severity threats.

## Second Derivatives (Distilled Civic Output)

Produced from first derivatives through the deliberation pipeline (scatter → gather → refine → crystallize):

| Type | Derived From | Pipeline Stage | Purpose |
|------|-------------|----------------|---------|
| **Mandates** | Ideas/groups crystallized | Crystallize | Formal citizen positions |
| **Ballot Initiatives** | Mandates promoted to vote | Post-crystallize | Actionable proposals for civic action |
| **Polls** | Threats/ideas requiring public input | Auto-generated | Gauge public opinion on issues |
| **Digests** | Multiple first derivatives synthesized | Gather + summarize | Monthly town/state summaries |

## The Pipeline

```
User Input Pipeline:
  Ideas (user)      ─┐
                      ├──→  Scatter → Gather → Refine → Crystallize  ──→  Mandates
  Thoughts (user)   ─┘                                                     Ballot Initiatives
                                                                            Digests

Threat Pipeline (separate system):
  Threats (AI)      ──→  AI scrape → Classify → Tag → Severity score  ──→  Polls
```

## Stream Display Pattern

First derivatives are displayed as filtered streams on pages throughout the site. These streams have full user interaction (vote, edit, delete, promote). **The stream display is independent of the input method** — Claudia replaces how content enters the system, not how it's displayed.

### Pages with thought/idea streams (as of 2026-03-12)

| Page | Stream Type | Status | Notes |
|------|------------|--------|-------|
| `talk/index.php` | idea_log (geo-filtered) | Talk-stream removed, Claudia handles input | Stream display via Talk UI |
| `elections/the-fight.php` | Community stream | **Stripped** — needs restoration | Was talk-stream, replaced with Claudia comment |
| `voice.php` | user_thoughts (national) | **Working** | Full stream + submission form |
| `z-states/ct/putnam/index.php` | user_thoughts (town_id=119) | **Stripped** — needs restoration | Removed in commit d7cbd52 |
| `z-states/ct/index.php` | thought-form (state) | **Stripped** — needs restoration | Removed in commit d7cbd52 |
| `z-states/ct/woodstock/index.html` | user_thoughts (town_id=172) | **Working** | Via AJAX to get-thoughts.php |

### Stream CRUD capabilities
- **Create** — submit via form or Claudia
- **Read** — filtered stream display (by town, state, national, category)
- **Update** — owner edit (ideas have `edit_count`; thoughts don't yet)
- **Delete** — owner delete (ideas soft-delete with `deleted_at`; thoughts hard-delete)
- **Vote** — agree/disagree on each item
- **Promote** — advance status or turn into task

### Thought API Endpoints
- `api/get-thoughts.php` — read/list with geo filters (town_id, state_id, is_federal)
- `api/submit-thought.php` — create new thought
- `api/vote-thought.php` — agree/disagree voting
- `api/delete-thought.php` — owner delete
- `api/expand-task.php` — turn thought into task

## Related Documentation
- `docs/talk-architecture.md` — full Talk pipeline (phases 1-8)
- `docs/talk-app-plan.md` — idea_log schema, status progression
- `docs/plans/civic-engine-design.md` — ballots, mandates, feed engine
- `docs/collect-threats-process.md` — threat data pipeline
