# /talk Phase 1 Design: Database + API Foundation

> The backend foundation for the brainstorming app. Schema changes, unified API with action routing, brainstorm clerk registration, session management.

**Date:** 2026-02-12
**Status:** Approved
**Approach:** Unified API (all endpoints in `talk/api.php`)
**UI changes:** None (Phase 3)

---

## Decisions Made

| Decision | Choice | Rationale |
|----------|--------|-----------|
| DB columns | Phase 1 needs only (+ ai_response) | YAGNI — add tags, meeting_id when those phases arrive |
| Category type | Convert ENUM to VARCHAR(50) now | Future-proof; avoids another ALTER later |
| Auth | Allow anonymous (user_id NULL) | Lowered barrier; logged-in users auto-associated |
| API scope | All endpoints from the plan | Delivers complete API in one phase |
| Architecture | Unified `talk/api.php` | Brainstorm clerk actions all touch idea_log; one file, one place |
| Web search | Disabled for brainstorm clerk | Facilitates idea capture, not fact lookup |
| UI | No changes in Phase 1 | Backend-only; Phase 3 rebuilds UI with tabs |

---

## Database Schema Changes

### ALTER `idea_log`

```sql
-- Convert category from ENUM to VARCHAR(50)
ALTER TABLE idea_log MODIFY category VARCHAR(50) DEFAULT 'idea';

-- Add new columns
ALTER TABLE idea_log ADD COLUMN user_id INT NULL AFTER id;
ALTER TABLE idea_log ADD COLUMN session_id VARCHAR(36) NULL AFTER user_id;
ALTER TABLE idea_log ADD COLUMN parent_id INT NULL AFTER session_id;
ALTER TABLE idea_log ADD COLUMN status ENUM('raw','refining','distilled','actionable','archived') DEFAULT 'raw' AFTER category;
ALTER TABLE idea_log ADD COLUMN ai_response TEXT NULL AFTER content;
ALTER TABLE idea_log ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Indexes
ALTER TABLE idea_log ADD INDEX idx_user (user_id);
ALTER TABLE idea_log ADD INDEX idx_session (session_id);
ALTER TABLE idea_log ADD INDEX idx_parent (parent_id);
ALTER TABLE idea_log ADD INDEX idx_status (status);

-- Foreign keys
ALTER TABLE idea_log ADD FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL;
ALTER TABLE idea_log ADD FOREIGN KEY (parent_id) REFERENCES idea_log(id) ON DELETE SET NULL;
```

### Column Purposes

| Column | Purpose |
|--------|---------|
| `user_id` | Who captured this (NULL for anonymous) |
| `session_id` | UUID grouping a brainstorm session (one sitting) |
| `parent_id` | Self-referencing FK — this idea builds on parent (distillation chain) |
| `status` | Where in the distillation process: raw -> refining -> distilled -> actionable |
| `ai_response` | What the AI said back (kept alongside the idea for read-back) |
| `updated_at` | Track when ideas get refined |

### Deferred Columns

| Column | Phase | Why deferred |
|--------|-------|-------------|
| `tags` (VARCHAR 500) | Phase 2+ | Not needed until tag-based discovery |
| `meeting_id` (INT + FK) | Phase 5 | Requires brainstorm_meetings table |

### INSERT `ai_clerks`

```sql
INSERT INTO ai_clerks (clerk_key, clerk_name, model, description, capabilities, enabled)
VALUES (
    'brainstorm',
    'Brainstorm Partner',
    'claude-sonnet-4-5-20250929',
    'Brainstorming facilitator following the 5 rules and Golden Rule ethics',
    'save_idea,link_ideas,read_back,distill,promote',
    1
);
```

---

## API: `talk/api.php` — Unified Entry Point

### Action Routing

| Action | Method | What it does |
|--------|--------|-------------|
| *(none)* | GET/POST | Save an idea (existing behavior, enhanced) |
| `history` | GET | Read back ideas with filters |
| `promote` | POST | Advance idea status |
| `link` | POST | Set parent_id on an idea |
| `brainstorm` | POST | AI interaction via brainstorm clerk |

### Save (default — no action param)

**Request:**
```json
POST /talk/api.php
{
    "content": "what if we added a childcare finder",
    "category": "idea",
    "source": "voice",
    "session_id": "uuid-here",
    "parent_id": null
}
```

- Also accepts GET params for backward compatibility with existing index.php
- `session_id`: auto-generated UUID v4 if not provided
- `user_id`: resolved from cookies via `getUser($pdo)` — NULL if anonymous
- `parent_id`: optional, links to parent idea

**Response:**
```json
{
    "success": true,
    "id": 42,
    "session_id": "auto-uuid-here",
    "status": "raw",
    "message": "Idea logged"
}
```

### History

**Request:**
```
GET /talk/api.php?action=history
    &session_id=uuid        (optional)
    &category=idea          (optional)
    &status=raw             (optional)
    &limit=20               (optional, default 50, max 200)
    &include_ai=1           (optional)
```

If user is logged in, defaults to showing their ideas. If session_id provided, filters to that session.

**Response:**
```json
{
    "success": true,
    "ideas": [
        {
            "id": 42,
            "content": "what if we added a childcare finder",
            "category": "idea",
            "status": "raw",
            "ai_response": "Yes — and that connects to...",
            "parent_id": null,
            "children_count": 2,
            "source": "voice",
            "created_at": "2026-02-12 14:30:00"
        }
    ]
}
```

`ai_response` only included when `include_ai=1`. `children_count` is a subquery: `SELECT COUNT(*) FROM idea_log WHERE parent_id = this.id`.

### Promote

**Request:**
```json
POST /talk/api.php?action=promote
{
    "idea_id": 42,
    "status": "refining"
}
```

**Validation:** Forward-only transitions: raw -> refining -> distilled -> actionable. Any state can go to "archived". Backwards transitions rejected with error.

**Response:**
```json
{
    "success": true,
    "idea_id": 42,
    "old_status": "raw",
    "new_status": "refining"
}
```

### Link

**Request:**
```json
POST /talk/api.php?action=link
{
    "idea_id": 45,
    "parent_id": 42
}
```

**Validation:** Both IDs must exist. Circular reference check: walk up from parent_id to ensure idea_id isn't in the ancestor chain.

**Response:**
```json
{
    "success": true,
    "idea_id": 45,
    "parent_id": 42
}
```

### Brainstorm (AI interaction)

**Request:**
```json
POST /talk/api.php?action=brainstorm
{
    "message": "what if we made the brainstorming rules reusable",
    "session_id": "uuid-here",
    "history": [
        {"role": "user", "content": "..."},
        {"role": "assistant", "content": "..."}
    ]
}
```

**Internal flow:**

1. Require `config-claude.php` for `callClaudeAPI()`, `getClerk()`, `parseActions()`, `cleanActionTags()`
2. Load brainstorm clerk via `getClerk($pdo, 'brainstorm')`
3. Build system prompt:
   - `buildClerkPrompt($pdo, $clerk)` — base TPB prompt + clerk identity + knowledge
   - Append brainstorm rules block (5 rules, ethics, behavior)
   - Append session context: query all ideas in this session_id, format as numbered list
4. Call `callClaudeAPI($systemPrompt, $messages, $clerk['model'], false)` — web search disabled
5. Parse action tags from response via `parseActions()`
6. Execute actions against idea_log (SAVE_IDEA, LINK_IDEAS, PROMOTE, READ_BACK)
7. Clean action tags from response text
8. Return cleaned response + action results

**Response:**
```json
{
    "success": true,
    "response": "Yes, and — reusable brainstorming rules would let any...",
    "clerk": "Brainstorm Partner",
    "actions": [
        {
            "action": "SAVE_IDEA",
            "success": true,
            "idea_id": 46
        }
    ]
}
```

---

## Brainstorm Clerk System Prompt

Appended to the base prompt built by `buildClerkPrompt()`:

```
## Brainstorming Rules (non-negotiable)
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
- CONNECT: Surface related ideas from the user's session
- DISTILL: When asked, help tighten language — but never kill the idea
- NEVER: Shut down, rank, criticize, scope down, or say "that's too vague"

## Session Ideas
{dynamically injected: numbered list of all ideas in current session_id}
```

### Action Tag Instructions (appended to prompt)

```
## Action Tags
When appropriate, include action tags to save state:

To save a new idea:
[ACTION: SAVE_IDEA]
content: {the idea text}
category: {idea|decision|todo|note|question|reaction}
parent_id: {id of parent idea, if building on one}

To link two ideas:
[ACTION: LINK_IDEAS]
idea_id: {the idea to update}
parent_id: {the parent idea}

To advance an idea's status:
[ACTION: PROMOTE]
idea_id: {the idea to promote}
status: {refining|distilled|actionable}

To read back recent ideas:
[ACTION: READ_BACK]
limit: {number of ideas to show}
```

---

## Session Management

### UUID Generation (server-side)

```php
function generateSessionId() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
```

Auto-generated on first save if not provided. Returned in response. Client stores in `sessionStorage` and sends on subsequent requests.

### User Association

```php
require_once __DIR__ . '/../includes/get-user.php';
$dbUser = getUser($pdo);
$userId = $dbUser ? (int)$dbUser['user_id'] : null;
```

Resolved from cookies. No login required. NULL for anonymous.

---

## Files Affected

| File | Change | Size |
|------|--------|------|
| `talk/api.php` | Major rewrite | ~300 lines (from ~90) |
| DB: `idea_log` | ALTER (6 columns, 4 indexes, 2 FKs) | Migration script |
| DB: `ai_clerks` | INSERT brainstorm clerk | 1 row |
| `scripts/db/talk-phase1.sql` | New migration script | ~30 lines |

### Not Touched

- `talk/index.php` — no UI changes (Phase 3)
- `talk/history.php` — no UI changes (Phase 3)
- `api/claude-chat.php` — no modifications (functions reused via require)
- `config-claude.php` — no modifications (functions reused via require)

---

## Testing Plan

All tests via direct HTTP calls (curl or browser):

1. **Save (basic):** POST content — verify idea created with auto session_id, status=raw
2. **Save (with session):** POST with session_id — verify same session grouped
3. **Save (logged in):** POST while logged in — verify user_id attached
4. **Save (anonymous):** POST without login — verify user_id=NULL, idea still saved
5. **Save (with parent):** POST with parent_id — verify chain created
6. **History:** GET with session filter — verify only session ideas returned
7. **History (category filter):** GET with category — verify filtered
8. **Promote (valid):** POST raw->refining — verify success
9. **Promote (invalid):** POST refining->raw — verify rejected
10. **Link:** POST link — verify parent_id set
11. **Link (circular):** POST where parent is descendant — verify rejected
12. **Brainstorm:** POST message — verify AI responds, clerk name returned
13. **Brainstorm (SAVE_IDEA):** Verify AI can auto-save ideas via action tags
14. **Backward compat:** GET with old params (content, category, source) — verify still works

---

## Related Documents

- [Talk App Plan](../talk-app-plan.md) — Full 9-phase plan
- [Philosophical Grounding](../talk-philosophical-grounding.md) — Two-pillar foundation
- [Use Case Narrative](../talk-brainstorm-use-case.md) — The brainstorming session that inspired this