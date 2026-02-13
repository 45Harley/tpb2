# /talk Phase 1 Design: Database + API + UI Wiring

> Approved 2026-02-12. End-to-end capture flow: schema, API, and frontend updates.

---

## Scope

Phase 1 takes the existing Quick Thought capture tool (`talk/`) and upgrades it from a flat idea logger to a session-aware, user-attributed, threadable idea system. This is the foundation for all later phases (AI clerk, Read tab, meetings).

**What's in scope:**
- ALTER `idea_log` table (new columns, indexes, FKs, VARCHAR category)
- Expand `talk/api.php` with action routing (save, history, promote, link)
- Update `talk/index.php` to POST JSON with session_id and expanded categories
- Update `talk/history.php` with user attribution, status badges, threading, promote

**What's NOT in scope:**
- AI brainstorm clerk (Phase 2)
- Bottom tab bar / Read tab (Phase 3)
- AI chat tab (Phase 4)
- Meetings (Phase 5+)

---

## Decisions Made

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Phase 1 scope | Backend + UI wiring | End-to-end validation of new schema |
| Session UUID | Client-side (JS) | Client controls session boundary via sessionStorage |
| Categories | Expand now to VARCHAR(50) | Foundation ready for Phase 2+ categories |
| History page | Update in Phase 1 | Validates schema visually, becomes proto-Read tab |
| API routing | Action query parameter | Matches claude-chat.php pattern, plan doc design |

---

## 1. Database Changes

### ALTER `idea_log`

```sql
-- Change category from ENUM to VARCHAR
ALTER TABLE idea_log MODIFY category VARCHAR(50) DEFAULT 'idea';

-- Add new columns
ALTER TABLE idea_log ADD COLUMN user_id INT NULL AFTER id;
ALTER TABLE idea_log ADD COLUMN session_id VARCHAR(36) NULL AFTER user_id;
ALTER TABLE idea_log ADD COLUMN parent_id INT NULL AFTER session_id;
ALTER TABLE idea_log ADD COLUMN status ENUM('raw','refining','distilled','actionable','archived') DEFAULT 'raw' AFTER category;
ALTER TABLE idea_log ADD COLUMN ai_response TEXT NULL AFTER content;
ALTER TABLE idea_log ADD COLUMN tags VARCHAR(500) NULL AFTER status;
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

All new columns are nullable. Existing rows unaffected. `category` moves from ENUM to VARCHAR(50) — existing values still valid.

---

## 2. API — `talk/api.php` v2

### Routing

Action query parameter: `?action=save`, `?action=history`, etc. Default (no action) = `save`. Switch/case dispatch, matching the `claude-chat.php` pattern.

User identified server-side via `getUser($pdo)` from `tpb_user_id` cookie. Never from client.

### Actions

#### `save` (POST, default)

```
POST /talk/api.php  or  POST /talk/api.php?action=save
Body: {
    "content": "what if we added a childcare finder",
    "category": "idea",
    "source": "voice",
    "session_id": "uuid-from-client",
    "parent_id": null,
    "tags": "benefits,childcare"
}
Response: { "success": true, "id": 42, "session_id": "...", "status": "raw" }
```

- `user_id` from `getUser()`, not client
- `session_id` from client (JS UUID)
- `parent_id`, `tags` optional

#### `history` (GET)

```
GET /talk/api.php?action=history&session_id=X&category=idea&status=raw&limit=50
```

- Logged-in user: filters to their ideas by default
- Optional filters: `session_id`, `category`, `status`
- Returns `children_count` (subquery) per idea
- Ordered by `created_at DESC`

#### `promote` (POST)

```
POST /talk/api.php?action=promote
Body: { "idea_id": 42, "status": "refining" }
```

- Owner-only (check `user_id` matches)
- Status must advance forward (raw -> refining -> distilled -> actionable), not backward

#### `link` (POST)

```
POST /talk/api.php?action=link
Body: { "idea_id": 45, "parent_id": 42 }
```

- Sets `parent_id` on existing idea
- Owner-only

### Backward Compatibility

Old GET-based `?content=X&category=Y&source=Z` still works. If `content` is in `$_GET` and no `action` param, falls through to save handler. No breaking change.

---

## 3. Frontend — `index.php` Updates

1. **POST JSON** instead of GET query string
2. **Session ID** — `crypto.randomUUID()` on page load, stored in `sessionStorage`. Same tab = same session.
3. **User ID** — Not sent by client. Server reads from cookie.
4. **Category chips** — Add `question` and `reaction`:
   ```
   💡 Idea | ✅ Decision | 📋 Todo | 📝 Note | ❓ Question | ↩️ Reaction
   ```
   `reaction` disabled unless `parent_id` is set (Phase 3 React button).
5. **Source detection** — Track whether current text came from mic or keyboard. Mic → `'voice'`, typed → `'web'`.
6. **Response display** — Show `"Idea #42 saved (raw)"` instead of generic message.

Layout, styling, mic button behavior, Ctrl+Enter shortcut, history link all unchanged.

---

## 4. Frontend — `history.php` Updates

1. **User attribution** — Cards show who posted (first_name or "You"). JOIN to `users`.
2. **Session grouping** — Visual group for same `session_id`. Filter by `?session=uuid`.
3. **Status badge** — Pill next to category icon. Colors: raw=gray, refining=blue, distilled=green, actionable=gold.
4. **Thread indicator** — "builds on #X" link if `parent_id` set. "N builds" if has children.
5. **Status filters** — New row: `[All] [raw] [refining] [distilled] [actionable]`.
6. **Promote button** — `⬆` on each card, calls `?action=promote`. Only on your own ideas.
7. **User-scoped** — Logged in = your ideas only. Toggle for "Show all".

Layout, dark theme, category filters, card design all preserved.

---

## 5. Data Flow

```
1. Page loads
   → JS: sessionId = sessionStorage.getItem('tpb_session')
          || crypto.randomUUID() → store in sessionStorage

2. User dictates/types, picks category, taps Save
   → JS: POST /talk/api.php (JSON body)
     { content, category, source, session_id, parent_id, tags }

3. api.php receives POST
   → require includes/get-user.php
   → $user = getUser($pdo) → $userId or null
   → Switch on action (default: save)
   → INSERT INTO idea_log
   → Return { success, id, session_id, status: "raw" }

4. JS shows: "Idea #42 saved (raw)"

5. history.php
   → getUser($pdo) → filter to user's ideas
   → JOIN users, subquery children_count
   → Render cards with status, threads, promote

6. Promote
   → POST ?action=promote { idea_id, status }
   → Verify ownership, advance status
```

**Anonymous users:** Everything works, `user_id` = NULL. No ownership checks, promote disabled.

**Legacy callers:** Old GET saves still insert rows with new columns as NULL/default. No breakage.

---

## Related Documents

- [Talk App Plan](../talk-app-plan.md) — Full 9-phase plan
- [Philosophical Grounding](../talk-philosophical-grounding.md) — Two-pillar foundation
- [Use Case Narrative](../talk-brainstorm-use-case.md) — The brainstorming session that inspired this
