# Talk Access Model

**Who can see what, and who can do what — the complete reference.**

Last updated: 2026-02-18

---

## Three Layers of Access Control

Talk uses three independent settings that combine to determine what any person can see and do:

1. **Identity level** — how verified is this person?
2. **Group access level** — how open is this group?
3. **Public access flags** — can non-members read or vote?

---

## 1. Identity Levels

Every user has an identity level from the `identity_levels` table:

| Level | Name | What it means | Talk permissions |
|-------|------|---------------|-----------------|
| 0 | anonymous | No account | Read USA stream only |
| 1 | anonymous | Account, no email verified | Read all streams |
| 2 | remembered | Email verified | Read + post (if location set) |
| 3 | verified | Phone verified | Read + post + public group access |
| 4 | vetted | Background checked | Full access |

**Key gates:**
- **Level 2+** required to post ideas, vote, join groups
- **Level 3+** required for public group reading/voting (non-member access)
- **Location required** — even at level 2+, posting requires `current_state_id` set in profile

---

## 2. Group Access Level

Every group has an `access_level` that controls who can **discover** and **join** it:

| Access Level | Who sees it exists | Who can join | Who contributes |
|-------------|-------------------|-------------|----------------|
| **open** | Everyone | Anyone (self-join) | Members |
| **observable** | Everyone | By invitation | Members |
| **closed** | Members only | By invitation | Members |

- `open` — the group appears in discovery and anyone can click "Join"
- `observable` — the group appears in discovery but you can only watch; joining requires an invite
- `closed` — invisible to non-members; only accessible via direct invite

**Default:** `observable` — civic deliberation should be transparent.

---

## 3. Public Access Flags (per group)

Two boolean flags that extend visibility beyond group members:

| Flag | Default | Effect |
|------|---------|--------|
| `public_readable` | 0 (off) | Verified non-members (level 3+) can view the group's ideas in the Talk stream |
| `public_voting` | 0 (off) | Verified non-members (level 3+) can also agree/disagree vote on ideas |

**Rules:**
- `public_voting = 1` implies `public_readable = 1` (you can't vote on what you can't see)
- Non-members can NEVER submit ideas — only members can contribute
- Non-members see the Talk stream read-only (input area hidden)
- These flags are set by the facilitator at group creation or changed later in group settings

---

## 4. Membership & Roles

When someone IS a member, their role determines what they can do:

| Role | Display | Can do |
|------|---------|--------|
| **facilitator** | Group Facilitator | Manage members, invite, run gather/crystallize, change settings, archive |
| **member** | Group Member | Submit ideas, vote, participate in discussion |
| **observer** | Group Observer | Read only — cannot submit or vote |

**Member status:** Each membership has a `status` field (`active` or `inactive`). Inactive members remain listed but are blocked from all group access until reactivated by a facilitator.

---

## 5. Access Matrix

Putting it all together — what can each type of person do?

| Person | See group exists | Read ideas | Submit ideas | Vote | Manage |
|--------|-----------------|------------|-------------|------|--------|
| Anonymous (no account) | If open/observable | No | No | No | No |
| Level 1 (unverified email) | If open/observable | No | No | No | No |
| Level 2 (email verified) | If open/observable | Only as member | Yes (as member, with location) | Yes (as member) | No |
| Level 3+ non-member, `public_readable=0` | If open/observable | No | No | No | No |
| Level 3+ non-member, `public_readable=1` | Yes | Yes (read-only) | No | No | No |
| Level 3+ non-member, `public_voting=1` | Yes | Yes | No | Yes | No |
| Member (any level 2+) | Yes | Yes | Yes | Yes | No |
| Observer | Yes | Yes | No | No | No |
| Facilitator | Yes | Yes | Yes | Yes | Yes |

### Closed group exception

For `closed` groups, the "See group exists" column changes — only members can see it. Non-members can't discover it at all, regardless of identity level.

---

## 6. Geographic Streams vs Groups

Ideas live in one of two contexts — they don't overlap:

| Context | How ideas get here | Who sees them |
|---------|-------------------|---------------|
| **Geo stream** (USA/State/Town) | `group_id IS NULL` — ungrouped ideas, stamped with poster's state_id/town_id | Everyone (read); level 2+ with location (post) |
| **Group** | `group_id = N` — belongs to that group | Members (always); public non-members (if flags set) |

**Important:** Group ideas (`group_id IS NOT NULL`) do NOT appear in geo streams. They're scoped to the group only.

---

## 7. Standard Civic Groups (SIC)

Standard groups are auto-created from SIC Division J (Public Administration) codes — 28 civic categories per community (courts, fire, highways, education, etc.).

| Property | Value |
|----------|-------|
| `is_standard` | 1 |
| `access_level` | open |
| `public_readable` | 1 |
| `public_voting` | 1 |
| Deletable by users | No |
| Renamable by users | No |

Standard civic groups are maximally open — anyone can join, and verified non-members can read and vote.

---

## 8. Access Gate Banners

The Talk frontend shows persistent banners based on user state:

| User state | Banner | Links to |
|-----------|--------|----------|
| No account / level < 2 | "Verify your email to join the conversation" | `/join.php` |
| Level 2+ but no location | "Set your town to join your local community" | `/profile.php#town` |
| Level 2+ with location | No banner — full access | — |

Banners are clickable and not dismissible. The input area is hidden until both gates are passed.

---

## 9. Implementation Reference

### Server-side functions

- **`getUser($pdo)`** — returns user array with `identity_level_id`, `current_state_id`, `current_town_id`
- **`getPublicAccess($group, $dbUser)`** — checks group flags + identity level, returns `'vote'`, `'read'`, or `null`
- **`handleSave()`** — enforces level 2+ and location gates before INSERT
- **`handleVote()`** — checks membership OR `public_voting` flag for non-members
- **`handleHistory()`** — respects `state_id`/`town_id` filters for geo streams
- **`get_access_status`** API action — returns `can_post`, `needs` ('verify_email'|'set_location'|null)

### Database columns

```sql
-- idea_groups
access_level    ENUM('open','closed','observable')  -- who can join
public_readable TINYINT(1) DEFAULT 0                -- non-members can read
public_voting   TINYINT(1) DEFAULT 0                -- non-members can vote
is_standard     TINYINT(1) DEFAULT 0                -- auto-created civic group

-- idea_group_members
role    ENUM('member','facilitator','observer')
status  VARCHAR(10) DEFAULT 'active'               -- active or inactive

-- idea_log
group_id  INT NULL     -- NULL = personal/geo stream, N = belongs to group
state_id  INT NULL     -- auto-stamped from poster's profile
town_id   INT NULL     -- auto-stamped from poster's profile

-- users
identity_level_id  INT    -- 1-4 verification level
current_state_id   INT    -- location gate
current_town_id    INT    -- location gate
```

---

*This document is the single source of truth for Talk's access model. Update it when access rules change.*
