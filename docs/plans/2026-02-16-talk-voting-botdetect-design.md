# Talk: Agree/Disagree Voting + Bot Detection

**Date**: 2026-02-16
**Status**: Approved

## Context

TPB is a civic platform at federal, state, and local levels. Facilitated online engagement is the basis of civic progress at each level. The /talk system is the universal civic engine — the same tool at every scope. Two features from the town thought system (Brainstorm Putnam) are missing from /talk and need to be added to unify the platform:

1. **Agree/Disagree voting** — civic agreement on ideas (not Reddit-style up/down)
2. **Bot detection** — honeypot + timing checks, consistent with the town system

Additionally, the admin dashboard needs visibility into bot activity.

## Philosophy

- Without ideas nothing exists. The more good ideas the better — proven in brainstorming.
- The online communication tools should be fundamentally the same at every level.
- Ideas gather and crystallize from the bottom up (town → state → federal), not top-down.
- AI governed by the same Golden Rule ethics accelerates progress in all domains.
- Agree/disagree framing (not up/down) aligns with brainstorming rules: constructive, not competitive.

## Design

### 1. Database Schema

**New table:**
```sql
CREATE TABLE idea_votes (
    vote_id INT AUTO_INCREMENT PRIMARY KEY,
    idea_id INT NOT NULL,
    user_id INT NOT NULL,
    vote_type ENUM('agree','disagree') NOT NULL,
    voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_vote (idea_id, user_id),
    FOREIGN KEY (idea_id) REFERENCES idea_log(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);
```

**New columns on idea_log:**
```sql
ALTER TABLE idea_log
    ADD COLUMN agree_count INT DEFAULT 0,
    ADD COLUMN disagree_count INT DEFAULT 0;
```

UNIQUE KEY (idea_id, user_id) enforces one vote per user per idea at the DB level.

### 2. Vote API

New action in `talk/api.php`: `action=vote`

**Request:**
```json
POST api.php?action=vote
{
    "idea_id": 42,
    "vote_type": "agree"
}
```

**Toggle logic:**
1. User not logged in → error
2. Existing vote, same type → remove (toggle off), decrement count
3. Existing vote, different type → switch, adjust both counts
4. No existing vote → insert, increment count
5. Return: `agree_count`, `disagree_count`, `user_vote` (null/'agree'/'disagree')

**Rules:**
- Login required (no anonymous voting)
- No civic points (not part of /talk currently)
- Cannot vote on AI-generated cards (clerk_key is set)
- Cannot vote on digest/crystal cards

### 3. Frontend — Vote UI

In `talk/index.php`, `renderIdeaCard()` adds vote buttons to card footer:

```
[tags] [status]           [👍 3] [👎 1]  [edit] [delete] [promote]
```

- Vote buttons on left, owner actions on right
- Active state highlighted when user has voted
- Counts update in-place (no full reload)
- Excluded: AI cards (clerk_key set), digest cards, crystal cards
- `loadIdeas()` history response includes agree_count, disagree_count, user_vote per idea
- Polling picks up count changes from other users

### 4. Bot Detection in /talk

Integrate existing `api/bot-detect.php` into `talk/api.php` handleSave().

**Frontend (talk/index.php):**
- Hidden honeypot input (CSS off-screen)
- `_form_load_time` recorded on page load as JS variable
- Both sent in submitIdea() POST body

**Backend (talk/api.php handleSave):**
- `require_once __DIR__ . '/../api/bot-detect.php';`
- Call `checkForBot($pdo, 'talk_save', $input, $formLoadTime)`
- Bot detected → return `{success: true}` (silent rejection)
- Logged to `bot_attempts` table, same alert thresholds

**Not added:**
- No bot detection on voting (login required)
- No bot detection on gather/crystallize (facilitator-only)

### 5. Bot Tab in Admin Dashboard

New tab in `admin.php` alongside Dashboard / Volunteers / Thoughts / Users / Activity / Help.

**Summary cards:**
- Attempts (24h) — total bot attempts
- Attempts (7d) — total bot attempts
- Unique IPs (24h) — distinct flagged IPs
- Top form — which form gets hit most

**Attempts table (100 most recent):**
| Time | IP | Form | Triggers | User Agent | Session |
|------|-----|------|----------|------------|---------|
| 2 min ago | 45.33.x.x | submit_thought | honeypot, too_fast | Mozilla/5.0... | civic_abc... |

**Top Offenders:**
- IPs with 3+ attempts in last 7 days
- Columns: IP, attempt count, last seen, forms targeted

No IP blocking — that's .htaccess / server-level. This tab is visibility and awareness.

## Files to Modify

- `talk/api.php` — add vote action + bot detection in handleSave
- `talk/index.php` — vote buttons on cards + honeypot/timestamp fields
- `admin.php` — new Bot tab

## Files NOT Changed

- `api/bot-detect.php` — reused as-is
- `includes/thought-form.php` — town system untouched
- `api/submit-thought.php` — town system untouched
- `api/vote-thought.php` — town system untouched
- `api/get-thoughts.php` — town system untouched

## DB Migrations

```sql
CREATE TABLE idea_votes (
    vote_id INT AUTO_INCREMENT PRIMARY KEY,
    idea_id INT NOT NULL,
    user_id INT NOT NULL,
    vote_type ENUM('agree','disagree') NOT NULL,
    voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_vote (idea_id, user_id),
    FOREIGN KEY (idea_id) REFERENCES idea_log(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

ALTER TABLE idea_log
    ADD COLUMN agree_count INT DEFAULT 0,
    ADD COLUMN disagree_count INT DEFAULT 0;
```
