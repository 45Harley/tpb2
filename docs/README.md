# TPB2 Documentation Index

> Master registry: `system_documentation` table in `sandge5_tpb2` DB.
> Query: `SELECT doc_key, doc_title, roles, tags FROM system_documentation`

---

## Core

| Doc | Description |
|-----|-------------|
| [platform-overview.md](platform-overview.md) | High-level overview of civic participation features (Talk, Thoughts, Polls, identity levels) |
| [infrastructure.md](infrastructure.md) | Development, staging, and production environments + technology stack |
| [dev-setup-guide.md](dev-setup-guide.md) | Local development environment setup (Windows 11, XAMPP, Git) |
| [admin-guide.md](admin-guide.md) | Admin dashboard: user management, soft delete, volunteer approval, audit log |
| [media-management.md](media-management.md) | Image/audio/video file tracking (git vs scp deploy) |

## Talk System

| Doc | Description |
|-----|-------------|
| [talk-architecture.md](talk-architecture.md) | Full system architecture: scatter-gather-refine-crystallize pipeline, phases 1-8 |
| [talk-access-model.md](talk-access-model.md) | Three-layer access control (identity level, group access, public flags) |
| [talk-app-plan.md](talk-app-plan.md) | Original app plan for phone-based brainstorming |
| [talk-walkthrough.md](talk-walkthrough.md) | Step-by-step user guide with ASCII layout diagrams |
| [talk-brainstorm-use-case.md](talk-brainstorm-use-case.md) | Use case: 1:1 brainstorming with AI |
| [talk-philosophical-grounding.md](talk-philosophical-grounding.md) | Why the brainstorming distillation loop works |
| [talk-test-harness.md](talk-test-harness.md) | Multi-user integration test specification |
| [talk-phase3-seeds.md](talk-phase3-seeds.md) | Pre-brainstorm notes (archived vision doc) |
| [talk-csps-article-draft.md](talk-csps-article-draft.md) | Draft article: civic infrastructure on spiritual ground |

## Elections

| Doc | Description |
|-----|-------------|
| See [platform-overview.md](platform-overview.md) | Elections 2026: landing page, The Fight (pledges/knockouts), The War (28th Amendment) |

Key files: `elections/index.php`, `elections/the-fight.php`, `elections/the-amendment.php`, `api/pledge-action.php`, `api/knockout-action.php`, `api/email-recruit.php`

## Builder Kits

### State Builder (`state-builder/`)

| Doc | Description |
|-----|-------------|
| [state-builder/README.md](state-builder/README.md) | Kit introduction and overview |
| [state-builder/VOLUNTEER-ORIENTATION.md](state-builder/VOLUNTEER-ORIENTATION.md) | Required 30-35 min orientation: mission, Golden Rule ethics, quality standards |
| [state-builder/ETHICS-FOUNDATION.md](state-builder/ETHICS-FOUNDATION.md) | Deep dive into philosophical values and selfless service |
| [state-builder/STATE-BUILDER-AI-GUIDE.md](state-builder/STATE-BUILDER-AI-GUIDE.md) | AI session context for Claude helping volunteers build state pages |
| [state-builder/state-build-checklist.md](state-builder/state-build-checklist.md) | Pre-submission quality checklist |
| [state-builder/state-data-template.json](state-builder/state-data-template.json) | JSON data template for state page content |

### Town Builder (`town-builder/`)

| Doc | Description |
|-----|-------------|
| [town-builder/TOWN-BUILDER-AI-GUIDE.md](town-builder/TOWN-BUILDER-AI-GUIDE.md) | AI session context for Claude helping volunteers build town pages |
| [town-builder/TOWN-TEMPLATE.md](town-builder/TOWN-TEMPLATE.md) | Template guide for building town pages from the Putnam CT model |
| [town-builder/town-data-template.json](town-builder/town-data-template.json) | JSON data template for town page content |

## Plans (`plans/`)

| Doc | Status | Description |
|-----|--------|-------------|
| [plans/2026-02-12-talk-phase1-design.md](plans/2026-02-12-talk-phase1-design.md) | Complete | Database + API + UI wiring design |
| [plans/2026-02-12-talk-phase1-impl.md](plans/2026-02-12-talk-phase1-impl.md) | Complete | Phase 1 implementation roadmap |
| [plans/2026-02-12-talk-phase2-design.md](plans/2026-02-12-talk-phase2-design.md) | Complete | AI brainstorm clerk design |
| [plans/2026-02-12-talk-phase2-impl.md](plans/2026-02-12-talk-phase2-impl.md) | Complete | Phase 2 implementation roadmap |
| [plans/2026-02-16-talk-voting-botdetect-design.md](plans/2026-02-16-talk-voting-botdetect-design.md) | Complete | Agree/disagree voting + bot detection design |
| [plans/2026-02-16-talk-voting-botdetect-impl.md](plans/2026-02-16-talk-voting-botdetect-impl.md) | Complete | Voting + bot detection implementation |
| [plans/2026-02-18-imagine-stories.md](plans/2026-02-18-imagine-stories.md) | Draft | Group invite funnel story pages |
| [plans/2026-02-19-standard-groups-scoped.md](plans/2026-02-19-standard-groups-scoped.md) | Complete | Scoped civic groups (town/state/federal) + department mapping |
| [plans/2026-02-21-usa-map-modes-design.md](plans/2026-02-21-usa-map-modes-design.md) | Complete | Multi-mode USA map (National, Election, Bills, Orders, Courts) |
| [plans/2026-02-23-usa-map-draft.md](plans/2026-02-23-usa-map-draft.md) | Draft | USA map implementation notes |
| [plans/2026-02-24-poll-roll-call-design.md](plans/2026-02-24-poll-roll-call-design.md) | Complete | Poll system: citizen/rep voting on executive threats |
| [plans/2026-02-24-poll-roll-call-plan.md](plans/2026-02-24-poll-roll-call-plan.md) | Complete | Poll roll call implementation plan |

## Other

| Doc | Description |
|-----|-------------|
| [TPB-Volunteer-Task-Workflow.md](TPB-Volunteer-Task-Workflow.md) | Dual-track volunteer progression with mentorship and points |
| [collect-threats-process.md](collect-threats-process.md) | Process for loading executive threats into election DB |
| [tpb-education-vision.md](tpb-education-vision.md) | TPB as learning platform across 12+ academic subjects |
| [tpb-growth-expansions.md](tpb-growth-expansions.md) | Seven independent 1000x growth vectors |
| [tpb-learning-subjects.md](tpb-learning-subjects.md) | Subject areas and rep targets for student contributions |
| [the-valentine-fly.html](the-valentine-fly.html) | Fictional story set in Putnam CT, 2036 |
| [tpb_refactoring_summary.html](tpb_refactoring_summary.html) | Refactoring summary (HTML) |
| [TPB-PHS-Partnership-Proposal.docx](TPB-PHS-Partnership-Proposal.docx) | PHS partnership proposal (Word doc) |
