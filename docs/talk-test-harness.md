# Talk Test Harness

**Multi-user automated integration tests for the Talk deliberation system.**

This document is the full specification for the test harness. It serves as both the reference for `tests/talk-harness.php` and a model/template for future test harnesses on any TPB subsystem.

---

## What It Tests

The Talk system lets citizens contribute ideas, collaborate in groups, and synthesize proposals. The test harness validates:

- **Authentication** — cookie-based user identification via the real API
- **Group lifecycle** — create, join, leave, manage members, archive
- **Idea CRUD** — save, edit, delete, promote ideas
- **Permission enforcement** — who can do what (facilitator vs member vs observer)
- **AI integration** — classification, brainstorm, gather, crystallize (optional, costs money)
- **Data isolation** — ideas stay in their group, personal ideas stay personal

## Why Automated

Manual testing requires logging in/out as different users, switching cookies, and remembering which user should see what. This is slow, error-prone, and doesn't catch regressions. The harness runs 40+ steps in under 2 seconds.

---

## Architecture

```
┌──────────────┐     curl + Cookie header     ┌──────────────┐     SQL     ┌──────────┐
│  talk-harness │ ──────────────────────────→ │  talk/api.php │ ─────────→ │  MySQL   │
│  .php         │ ←────────────────────────── │  (23 actions) │ ←───────── │  (real)  │
│               │     JSON response           │               │            │          │
│  Dashboard UI │                             │  getUser()    │            │ idea_log │
│  (HTML/JS)    │                             │  checks cookie│            │ groups   │
└──────────────┘                              └──────────────┘            │ members  │
                                                                          └──────────┘
```

**Key design decisions:**

| Decision | Rationale |
|----------|-----------|
| **curl, not direct PHP calls** | Tests the real HTTP pipeline — headers, JSON parsing, cookie validation. Catches bugs that in-process testing would miss. |
| **`tpb_user_id` cookie** | Uses the `getUser()` fallback path (get-user.php:32-37) which does a direct user lookup. No need to create `user_devices` entries — avoids polluting production tables. |
| **Real database** | Every API call hits MySQL. `create_group` inserts rows, `save` inserts ideas, etc. This is a full integration test. |
| **`__TEST_HARNESS__` prefix** | All test groups are named with this prefix so cleanup can delete them without touching real data. |
| **Self-contained PHP** | Dashboard + test engine in one file. Visit one URL, click Run, watch results. Matches the inline-everything pattern of the Talk pages themselves. |

---

## How Authentication Works

The Talk API (`talk/api.php`) calls `getUser($pdo)` on every request. This function (`includes/get-user.php`) checks two cookies in order:

1. **`tpb_civic_session`** — looks up `user_devices` table, requires `is_active = 1` (DB-validated)
2. **`tpb_user_id`** — direct lookup in `users` table (fallback, no device session needed)

The test harness uses **method 2** — each curl request includes:

```
Cookie: tpb_user_id=1
```

This tells the API "I am user 1" without needing a device session entry. It's the same auth path that production uses as a fallback.

---

## Test Users

These are existing accounts in the database. The harness does not create or modify user accounts.

| Key | user_id | Display Name | Email | Test Role | Dashboard Color |
|-----|---------|-------------|-------|-----------|----------------|
| `facilitator` | 1 | Harley H | hhg@sandgems.net | Creates group, manages members, runs gather/crystallize | Blue `#4fc3f7` |
| `member1` | 10 | har | harley@sandgems.net | Joins group, contributes ideas, tests member permissions | Green `#81c784` |
| `member2` | 32 | hh | hhg@4tpb.org | Joins group, tests cross-user restrictions | Orange `#ffb74d` |
| `observer` | 33 | Houston | harley@4tpb.org | Tests observer role (read-only, cannot submit ideas) | Purple `#ce93d8` |

---

## API Actions Tested

The Talk API has 23 actions. The harness tests all of them except `invite_to_group` and `get_invites` (which require email delivery).

| Action | Method | What it does | Tested in |
|--------|--------|-------------|-----------|
| `save` | POST | Create an idea | Scenario 1 (steps 6-9) |
| `history` | GET | Fetch ideas with filters | Scenario 1 (step 10) |
| `edit` | POST | Edit idea content | Scenario 1 (steps 11-12) |
| `delete` | POST | Soft-delete an idea | Scenario 1 (steps 16-17) |
| `promote` | POST | Change idea status | Scenario 1 (steps 13-14) |
| `toggle_shareable` | POST | Flip shareable flag | Scenario 1 (step 15) |
| `brainstorm` | POST | AI brainstorm response | Scenario 5 (optional) |
| `create_group` | POST | Create deliberation group | Scenario 1 (step 1) |
| `list_groups` | GET | List user's groups | Scenario 1 (step 23) |
| `get_group` | GET | Fetch group details | Scenario 1 (step 20) |
| `join_group` | POST | Join an open group | Scenario 1 (steps 2-4) |
| `leave_group` | POST | Leave a group | Scenario 1 (step 21) |
| `update_group` | POST | Change group settings | Scenario 1 (steps 18-19) |
| `update_member` | POST | Change member role/status | Scenario 1 (step 5), Scenario 2 |
| `add_member` | POST | Add user to group | Scenario 2 |
| `gather` | POST | AI gathers themes | Scenario 5 (optional) |
| `crystallize` | POST | AI produces proposal | Scenario 5 (optional) |
| `check_staleness` | GET | Check if gather/crystallize stale | Scenario 5 (optional) |
| `create_link` | POST | Link two ideas thematically | Scenario 3 |
| `get_links` | GET | Fetch links for an idea | Scenario 3 |
| `link` | POST | Set parent_id (threading) | Scenario 3 |

---

## Test Scenarios

### Scenario 1: Group Lifecycle (24 steps)

The primary scenario. Exercises the full lifecycle: create group, add members, submit ideas, edit/delete, manage roles, leave group.

```
Step  1: [facilitator] create_group "__TEST_HARNESS__ Deliberation"
           → EXPECT: success, returns group_id
           → Creates group with access_level='open', facilitator as creator

Step  2: [member1]     join_group
           → EXPECT: success, role='member'
           → Open group allows self-join as member

Step  3: [member2]     join_group
           → EXPECT: success, role='member'

Step  4: [observer]    join_group
           → EXPECT: success, role='member' (open group = member, not observer)

Step  5: [facilitator] update_member: set observer's role to 'observer'
           → EXPECT: success
           → Only facilitators can change roles

Step  6: [facilitator] save idea "We should increase youth voter registration"
           → EXPECT: success, returns idea with id, category, tags
           → group_id set to test group

Step  7: [member1]     save idea "Local community centers need WiFi upgrades"
           → EXPECT: success

Step  8: [member2]     save idea "Senior citizens need transportation to polling places"
           → EXPECT: success

Step  9: [observer]    save idea "This should be blocked"
           → EXPECT: FAIL — "Must be group member to submit"
           → Observers cannot contribute ideas (api.php:208)

Step 10: [facilitator] history (group_id, limit=50)
           → EXPECT: success, 3 ideas returned (steps 6-8)
           → Observer's blocked idea should NOT appear

Step 11: [member1]     edit own idea (append " — updated by member1")
           → EXPECT: success
           → Users can edit their own ideas

Step 12: [member2]     edit facilitator's idea
           → EXPECT: FAIL — not the owner
           → Users cannot edit others' ideas

Step 13: [facilitator] promote own idea to 'refining'
           → EXPECT: success
           → Status: raw → refining

Step 14: [member1]     promote own idea to 'refining'
           → EXPECT: success

Step 15: [facilitator] toggle_shareable on own idea
           → EXPECT: success

Step 16: [member1]     delete member2's idea
           → EXPECT: FAIL — not the owner
           → Users cannot delete others' ideas

Step 17: [member2]     delete own idea
           → EXPECT: success (soft delete)

Step 18: [facilitator] update_group: change description
           → EXPECT: success
           → Only facilitators can update group settings

Step 19: [member1]     update_group: try to change description
           → EXPECT: FAIL — "Only facilitators can update group"

Step 20: [facilitator] get_group
           → EXPECT: success
           → VERIFY: 4 members listed with correct roles
           → VERIFY: facilitator=1, member=10, member=32 (observer role), observer=33

Step 21: [member1]     leave_group
           → EXPECT: success

Step 22: [member1]     save idea to group
           → EXPECT: FAIL — no longer a member

Step 23: [facilitator] list_groups (mine=1)
           → EXPECT: test group appears in list

Step 24: [cleanup]     delete all __TEST_HARNESS__ data
```

### Scenario 2: Access Control Matrix (8 steps)

Rapid-fire permission checks.

```
Step  1: [observer]    create_group "__TEST_HARNESS__ Observer Group"
           → EXPECT: success (any logged-in user can create a group)

Step  2: [member1]     save idea to observer's group (without joining)
           → EXPECT: FAIL — not a member

Step  3: [member1]     update_member on facilitator's group
           → EXPECT: FAIL — "Only facilitators can manage members"

Step  4: [facilitator] update_member: promote member2 to facilitator
           → EXPECT: success

Step  5: [member2]     update_member: demote facilitator to member
           → EXPECT: success (member2 is now facilitator too)

Step  6: [facilitator] update_group (still works — both are facilitators)
           → EXPECT: success

Step  7: [member2]     update_member: remove member2 (self-remove as facilitator)
           → EXPECT: success or auto-promote behavior

Step  8: [cleanup]
```

### Scenario 3: Idea Links (5 steps)

Tests thematic linking between ideas.

```
Step  1: [facilitator] save 2 ideas to group
Step  2: [facilitator] create_link between them (type='related')
           → EXPECT: success
Step  3: [facilitator] get_links for idea 1
           → EXPECT: 1 link returned
Step  4: [facilitator] create_link: self-link (same idea to itself)
           → EXPECT: FAIL
Step  5: [cleanup]
```

### Scenario 4: Edge Cases (6 steps)

Boundary conditions and error handling.

```
Step  1: [facilitator] save idea with empty content
           → EXPECT: FAIL — content required
Step  2: [member1]     join_group when already a member
           → EXPECT: FAIL — already a member
Step  3: [member2]     delete an already-deleted idea
           → EXPECT: FAIL
Step  4: [member2]     edit a deleted idea
           → EXPECT: FAIL
Step  5: [facilitator] promote to invalid status (e.g., 'invalid')
           → EXPECT: FAIL or ignored
Step  6: [cleanup]
```

### Scenario 5: AI Integration (optional)

**Skipped by default** — each AI call costs ~$0.01. Enable with `skip_ai=false` in config.

```
Step  1: [facilitator] save idea with auto_classify=true
           → EXPECT: success, idea has AI-assigned category + tags
Step  2: [facilitator] brainstorm on that idea
           → EXPECT: success, AI response saved as idea_log row
Step  3: [facilitator] gather group ideas
           → EXPECT: success, digest card(s) created
Step  4: [facilitator] check_staleness
           → EXPECT: not stale (nothing changed since gather)
Step  5: [facilitator] crystallize
           → EXPECT: success, proposal markdown created
Step  6: [cleanup]
```

---

## Dashboard UI

Dark-themed to match the Talk system. Four columns, one per test user.

```
┌──────────────────────────────────────────────────────┐
│  Talk Test Harness                  [Run All] [Clean] │
│  Target: http://localhost                             │
├─────────────┬───────────┬───────────┬────────────────┤
│ Harley H    │ har       │ hh        │ Houston        │
│ (facilitator)│ (member)  │ (member)  │ (observer)     │
│ #4fc3f7     │ #81c784   │ #ffb74d   │ #ce93d8        │
├─────────────┼───────────┼───────────┼────────────────┤
│ ✓ create    │           │           │                │
│   group     │           │           │                │
│             │ ✓ join    │ ✓ join    │ ✓ join         │
│ ✓ save idea │ ✓ save    │ ✓ save    │ ⊘ save BLOCKED │
│ ✓ promote   │ ✓ edit    │ ⊘ edit    │                │
│ ...         │ ...       │ ...       │ ...            │
├─────────────┴───────────┴───────────┴────────────────┤
│  ✓ 20 passed   ✗ 0 failed   ⊘ 4 expected-fail       │
│  Scenario: Group Lifecycle   Duration: 1.2s           │
└──────────────────────────────────────────────────────┘
```

### Result Indicators

| Border Color | Symbol | Meaning |
|-------------|--------|---------|
| Green `#4caf50` | ✓ | Passed — expected success, got success |
| Red `#f44336` | ✗ | Failed — unexpected result (bug found) |
| Yellow `#ff9800` | ⊘ | Expected failure — negative test confirmed correctly |

### Step Card Contents

Each card shows:
- **User name** (color-coded)
- **Action** (e.g., `save`, `join_group`, `edit`)
- **Description** (what was attempted)
- **Result** (success/error message snippet)
- **Duration** (milliseconds)
- **Expandable** raw JSON response (click to toggle)

---

## Cleanup Strategy

All test data uses the `__TEST_HARNESS__` prefix in group names. Cleanup deletes in dependency order:

```sql
-- 1. Delete idea links referencing test ideas
DELETE FROM idea_links WHERE idea_id_a IN
  (SELECT id FROM idea_log WHERE group_id IN
    (SELECT id FROM idea_groups WHERE name LIKE '__TEST_HARNESS__%'))
  OR idea_id_b IN
  (SELECT id FROM idea_log WHERE group_id IN
    (SELECT id FROM idea_groups WHERE name LIKE '__TEST_HARNESS__%'));

-- 2. Delete group memberships
DELETE FROM idea_group_members WHERE group_id IN
  (SELECT id FROM idea_groups WHERE name LIKE '__TEST_HARNESS__%');

-- 3. Delete ideas in test groups
DELETE FROM idea_log WHERE group_id IN
  (SELECT id FROM idea_groups WHERE name LIKE '__TEST_HARNESS__%');

-- 4. Delete test groups
DELETE FROM idea_groups WHERE name LIKE '__TEST_HARNESS__%';
```

The "Clean" button runs this independently of tests — useful if a previous run crashed mid-way and left orphaned data.

---

## How to Run

### Browser (interactive)

1. Start Apache (XAMPP or staging server)
2. Visit `http://localhost/tests/talk-harness.php`
3. Click **Run All** to execute all scenarios
4. Watch results appear in real-time per user lane
5. Click **Clean** if needed to remove test data

### Playwright (headless CI)

```bash
# Against localhost
npm run test:talk

# Against staging
npm run test:talk-staging
```

### Auto-run mode (for CI)

Visit `?auto=1` to run all scenarios automatically on page load. The page sets a `#harness-complete` element and `#harness-summary` data attribute when done, which Playwright reads.

---

## How to Extend

### Adding a new scenario

1. Create a function `scenarioMyNewTest()` in `talk-harness.php`
2. Return an array of step results, each with:
   ```php
   [
       'step'     => 1,
       'user'     => 'facilitator',      // key from test users
       'action'   => 'save',             // API action name
       'desc'     => 'Save idea to group', // human-readable
       'expect'   => 'success',          // 'success' or 'fail'
       'passed'   => true,               // did it match expectation?
       'response' => '...',              // snippet of API response
       'duration' => 45                  // milliseconds
   ]
   ```
3. Add it to the scenario dispatcher

### Adding a new test user

1. Ensure the user exists in the `users` table
2. Add them to the config array with a user_id, label, and color
3. Reference them by key in scenario steps

### Testing a new API action

1. Add a step to the appropriate scenario (or create a new one)
2. Call `talkApiCall($baseUrl, $userId, 'POST', 'new_action', $data)`
3. Assert on `$result['data']['success']`

---

## Security

| Concern | Mitigation |
|---------|-----------|
| Test harness accessible in production | `tests/` directory blocked by `.htaccess` (verified in security.spec.js) |
| Test data left in production DB | `__TEST_HARNESS__` prefix + automatic cleanup. Manual "Clean" button as fallback. |
| `tpb_user_id` cookie spoofable | This is a known fallback auth path, not a test-only hack. The DB session (`tpb_civic_session`) takes precedence in production. |
| AI cost from accidental runs | AI scenarios disabled by default (`skip_ai=true`). Must be explicitly enabled. |

---

## File Map

```
tests/
  talk-harness.php          Main test runner (PHP engine + HTML dashboard)
  talk-harness.spec.js      Playwright CI wrapper

docs/
  talk-test-harness.md      This document (specification + reference)
```

---

## Key Source Files

| File | Relevance |
|------|-----------|
| `talk/api.php` | All 23 action handlers (switch block lines 80-162) |
| `includes/get-user.php` | Auth: DB session (line 25-30), user_id fallback (line 32-37) |
| `includes/set-cookie.php` | Cookie helper functions and constants |
| `tests/security.spec.js` | Existing Playwright test pattern, SITE_URL env var |
| `playwright.config.js` | Existing Playwright config |
